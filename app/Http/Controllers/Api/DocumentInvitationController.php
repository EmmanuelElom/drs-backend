<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\DocumentInvitationMail;
use App\Mail\DocumentCompletionMail;
use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentField;
use App\Models\DocumentInvitation;
use App\Models\NotificationEvent;
use App\Models\Signature;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SignedPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class DocumentInvitationController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly SignedPdfService $signedPdfService
    )
    {
    }

    public function index(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        return response()->json([
            'data' => $document->invitations()
                ->orderByDesc('id')
                ->get()
                ->map(fn (DocumentInvitation $invitation) => $this->serializeInvitation($document, $invitation))
                ->values(),
        ]);
    }

    public function inbox(Request $request)
    {
        $user = $request->user();

        if (! $user || ! filled($user->email)) {
            return response()->json([
                'data' => [],
            ]);
        }

        $invitations = DocumentInvitation::query()
            ->with(['document.owner', 'document.createdBy', 'document.user', 'document.assignedBy', 'invitedByUser'])
            ->where('recipient_email', $user->email)
            ->orderByDesc('invited_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $invitations->map(function (DocumentInvitation $invitation) {
                $document = $invitation->document;

                return [
                    'invitation' => $this->serializeInvitation($document, $invitation),
                    'document' => $this->serializeInboxDocument($document, $invitation),
                ];
            })->values(),
        ]);
    }

    public function store(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'invitationType' => ['required', 'in:review,sign'],
            'reviewPeriodDays' => ['nullable', 'integer', 'min:1', 'max:365'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*.email' => ['required', 'email', 'max:255'],
            'recipients.*.name' => ['nullable', 'string', 'max:255'],
            'recipients.*.order' => ['nullable', 'integer', 'min:0'],
            'recipients.*.canReview' => ['nullable', 'boolean'],
            'recipients.*.canComment' => ['nullable', 'boolean'],
            'recipients.*.canSign' => ['nullable', 'boolean'],
        ]);

        $recipients = collect($data['recipients']);
        $normalizedEmails = $recipients->map(fn (array $recipient) => Str::lower(trim($recipient['email'])))->all();

        abort_if(count($normalizedEmails) !== count(array_unique($normalizedEmails)), 422, 'Duplicate recipient email addresses are not allowed.');

        if ($data['invitationType'] === 'sign') {
            $signatureFields = $document->fields()->where('field_type', 'signature')->get();
            abort_unless($signatureFields->isNotEmpty(), 422, 'Signature invitations require at least one signature field.');

            $assignedRecipientEmails = $signatureFields
                ->pluck('assigned_recipient_email')
                ->filter()
                ->map(fn (string $email) => Str::lower(trim($email)))
                ->values();

            abort_if($assignedRecipientEmails->isEmpty(), 422, 'Assign signature fields to recipients before sending signature invitations.');

            $unassignedFields = $signatureFields->filter(function (DocumentField $field) {
                return blank($field->assigned_recipient_email);
            });
            abort_if($unassignedFields->isNotEmpty(), 422, 'Assign every signature field to a recipient before sending signature invitations.');

            $unknownAssignedFields = $signatureFields->filter(function (DocumentField $field) use ($normalizedEmails) {
                return ! in_array(Str::lower(trim((string) $field->assigned_recipient_email)), $normalizedEmails, true);
            });
            abort_if($unknownAssignedFields->isNotEmpty(), 422, 'Signature fields must be assigned to one of the invited recipients.');

            $recipientsWithoutFields = collect($normalizedEmails)->filter(function (string $email) use ($signatureFields) {
                return ! $signatureFields->contains(function (DocumentField $field) use ($email) {
                    return Str::lower(trim((string) $field->assigned_recipient_email)) === $email;
                });
            });

            abort_if($recipientsWithoutFields->isNotEmpty(), 422, 'Every signer needs at least one signature field assigned.');
        }

        $expiresAt = now()->copy()->addDays((int) ($data['reviewPeriodDays'] ?? 7));
        $sender = $request->user();
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $created = [];

        foreach ($recipients as $index => $recipient) {
            $rawToken = Str::random(80);
            $invitation = DocumentInvitation::query()->create([
                'document_id' => $document->id,
                'recipient_email' => trim($recipient['email']),
                'recipient_name' => $recipient['name'] ?? null,
                'invited_by' => $sender->id,
                'invited_at' => now(),
                'expires_at' => $expiresAt,
                'access_token_hash' => hash('sha256', $rawToken),
                'invitation_type' => $data['invitationType'],
                'status' => 'pending',
                'recipient_order' => (int) ($recipient['order'] ?? $index),
                'can_review' => (bool) ($recipient['canReview'] ?? ($data['invitationType'] === 'review')),
                'can_comment' => (bool) ($recipient['canComment'] ?? true),
                'can_sign' => (bool) ($recipient['canSign'] ?? ($data['invitationType'] === 'sign')),
                'metadata' => [
                    'source' => 'api',
                    'frontendRoute' => '/access?token=' . $rawToken,
                ],
            ]);

            $mailData = $this->buildInvitationMailData(
                document: $document,
                invitation: $invitation,
                sender: $sender,
                rawToken: $rawToken,
                frontendUrl: $frontendUrl
            );

            Mail::to($invitation->recipient_email)->queue((new DocumentInvitationMail($mailData))->afterCommit());

            NotificationEvent::query()->create([
                'event_type' => 'invitation_sent',
                'action' => 'invitation_sent',
                'channel' => 'mail',
                'recipient_name' => $invitation->recipient_name,
                'recipient_email' => $invitation->recipient_email,
                'user_id' => $sender->id,
                'document_id' => $document->id,
                'invitation_id' => $invitation->id,
                'subject' => $mailData['subject_line'],
                'payload' => $mailData,
                'status' => 'queued',
            ]);

            $this->auditLogger->fromRequest(
                action: 'invitation_created',
                request: $request,
                document: $document,
                invitationId: $invitation->id,
                details: sprintf('Created %s invitation for %s.', $data['invitationType'], $invitation->recipient_email),
                metadata: ['token' => $rawToken]
            );

            $created[] = [
                'token' => $rawToken,
                'invitation' => $this->serializeInvitation($document, $invitation),
            ];
        }

        return response()->json([
            'message' => 'Invitations created successfully.',
            'data' => $created,
        ], 201);
    }

    public function resend(Request $request, DocumentInvitation $invitation)
    {
        $document = $invitation->document()->with(['fields', 'owner', 'createdBy', 'user', 'assignedBy'])->firstOrFail();
        $this->authorize('update', $document);

        abort_if(in_array($invitation->status, ['revoked', 'completed'], true), 410, 'This invitation can no longer be resent.');

        $rawToken = Str::random(80);
        $invitation->forceFill([
            'access_token_hash' => hash('sha256', $rawToken),
            'status' => 'pending',
            'invited_at' => now(),
            'expires_at' => now()->copy()->addDays(7),
            'revoked_at' => null,
            'viewed_at' => null,
        ])->save();

        $mailData = $this->buildInvitationMailData(
            document: $document,
            invitation: $invitation,
            sender: $request->user(),
            rawToken: $rawToken,
            frontendUrl: rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/')
        );

        Mail::to($invitation->recipient_email)->queue((new DocumentInvitationMail($mailData))->afterCommit());

        NotificationEvent::query()->create([
            'event_type' => 'invitation_resent',
            'action' => 'invitation_resent',
            'channel' => 'mail',
            'recipient_name' => $invitation->recipient_name,
            'recipient_email' => $invitation->recipient_email,
            'user_id' => $request->user()->id,
            'document_id' => $document->id,
            'invitation_id' => $invitation->id,
            'subject' => $mailData['subject_line'],
            'payload' => $mailData,
            'status' => 'queued',
        ]);

        $this->auditLogger->fromRequest(
            action: 'invitation_resent',
            request: $request,
            document: $document,
            invitationId: $invitation->id,
            details: sprintf('Resent invitation to %s.', $invitation->recipient_email),
            metadata: ['token' => $rawToken]
        );

        return response()->json([
            'message' => 'Invitation resent successfully.',
            'data' => [
                'token' => $rawToken,
                'invitation' => $this->serializeInvitation($document, $invitation->fresh()),
            ],
        ]);
    }

    public function revoke(Request $request, DocumentInvitation $invitation)
    {
        $document = $invitation->document()->with(['fields', 'owner', 'createdBy', 'user', 'assignedBy'])->firstOrFail();
        $this->authorize('update', $document);

        $invitation->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
        ])->save();

        $this->auditLogger->fromRequest(
            action: 'invitation_revoked',
            request: $request,
            document: $document,
            invitationId: $invitation->id,
            details: sprintf('Revoked invitation for %s.', $invitation->recipient_email)
        );

        return response()->json([
            'message' => 'Invitation revoked successfully.',
            'data' => $this->serializeInvitation($document, $invitation),
        ]);
    }

    public function access(Request $request, string $token)
    {
        $invitation = $this->resolveInvitationFromToken($token, true);
        $document = $invitation->document()->with(['owner', 'createdBy', 'user', 'assignedBy', 'fields', 'comments.user', 'comments.invitation', 'signatures.user', 'signatures.invitation', 'invitations'])->firstOrFail();

        if ($invitation->status === 'pending') {
            $this->markInvitationViewed($request, $document, $invitation);
        }

        return response()->json([
            'data' => [
                'invitation' => $this->serializeInvitation($document, $invitation->fresh()),
                'document' => $this->serializeAccessDocument($document, $invitation),
            ],
        ]);
    }

    public function comment(Request $request, string $token)
    {
        $invitation = $this->resolveInvitationFromToken($token);
        abort_unless($invitation->can_comment, 403, 'This invitation does not allow comments.');

        $document = $invitation->document()->firstOrFail();
        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'selected_text' => ['nullable', 'string'],
            'comment' => ['required', 'string'],
            'parent_comment_id' => ['nullable', 'exists:comments,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'annotation_metadata' => ['nullable', 'array'],
        ]);

        if (! empty($data['parent_comment_id'])) {
            $parentComment = Comment::query()->findOrFail($data['parent_comment_id']);
            abort_unless($parentComment->document_id === $document->id, 422, 'The parent comment must belong to the same document.');
        }

        $this->markInvitationViewed($request, $document, $invitation);

        $comment = Comment::query()->create([
            'document_id' => $document->id,
            'invitation_id' => $invitation->id,
            'user_id' => null,
            'author_name' => $data['author_name'],
            'author_email' => $data['author_email'] ?? null,
            'selected_text' => $data['selected_text'] ?? null,
            'comment' => $data['comment'],
            'parent_comment_id' => $data['parent_comment_id'] ?? null,
            'page' => $data['page'] ?? null,
            'annotation_metadata' => $data['annotation_metadata'] ?? null,
        ]);

        $this->auditLogger->recordAnonymous(
            action: 'public_comment_added',
            document: $document,
            documentTitle: $document->title,
            details: sprintf('Public comment added by %s.', $comment->author_name),
            ipAddress: $request->ip(),
            invitationId: $invitation->id
        );

        return response()->json([
            'message' => 'Comment added successfully.',
            'data' => $this->serializeComment($document, $comment),
        ], 201);
    }

    public function review(Request $request, string $token)
    {
        $invitation = $this->resolveInvitationFromToken($token);
        abort_unless($invitation->can_review, 403, 'This invitation does not allow review.');

        $document = $invitation->document()->firstOrFail();
        $data = $request->validate([
            'author_name' => ['required', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
            'selected_text' => ['nullable', 'string'],
            'comment' => ['nullable', 'string'],
            'parent_comment_id' => ['nullable', 'exists:comments,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'annotation_metadata' => ['nullable', 'array'],
        ]);

        if (! empty($data['comment'])) {
            Comment::query()->create([
                'document_id' => $document->id,
                'invitation_id' => $invitation->id,
                'user_id' => null,
                'author_name' => $data['author_name'],
                'author_email' => $data['author_email'] ?? null,
                'selected_text' => $data['selected_text'] ?? null,
                'comment' => $data['comment'],
                'parent_comment_id' => $data['parent_comment_id'] ?? null,
                'page' => $data['page'] ?? null,
                'annotation_metadata' => $data['annotation_metadata'] ?? null,
            ]);
        }

        $this->markInvitationViewed($request, $document, $invitation);
        $this->completeInvitationReview($request, $invitation, $document, $data['author_name']);

        return response()->json([
            'message' => 'Review completed successfully.',
            'data' => [
                'invitation' => $this->serializeInvitation($document, $invitation->fresh()),
                'document' => $this->serializeAccessDocument($document->fresh(['owner', 'createdBy', 'user', 'assignedBy', 'fields', 'comments.user', 'comments.invitation', 'signatures.user', 'signatures.invitation', 'invitations']), $invitation),
            ],
        ]);
    }

    public function sign(Request $request, string $token)
    {
        $invitation = $this->resolveInvitationFromToken($token);
        abort_unless($invitation->can_sign, 403, 'This invitation does not allow signing.');

        $document = $invitation->document()->with(['fields', 'signatures.user'])->firstOrFail();
        $data = $request->validate([
            'signer_name' => ['required', 'string', 'max:255'],
            'signer_email' => ['nullable', 'email', 'max:255'],
            'signature_data' => ['required', 'string'],
            'agreement' => ['accepted'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'metadata' => ['nullable', 'array'],
            'document_field_id' => ['nullable', 'exists:document_fields,id'],
        ]);

        $eligibleFields = $this->signedPdfService->resolveSignatureFieldsForInvitation($document, $invitation);
        abort_if($eligibleFields->isEmpty(), 422, 'No signature fields are assigned to this invitation.');

        if (! empty($data['document_field_id'])) {
            $field = DocumentField::query()->findOrFail($data['document_field_id']);
            abort_unless($field->document_id === $document->id, 422, 'The signature field must belong to the same document.');
            abort_unless(
                $eligibleFields->contains(fn (DocumentField $eligibleField) => (string) $eligibleField->id === (string) $field->id),
                422,
                'The signature field is not assigned to this invitation.'
            );
        }

        $this->markInvitationViewed($request, $document, $invitation);

        $signatures = $eligibleFields->map(function (DocumentField $field) use ($request, $document, $invitation, $data) {
            return Signature::query()->create([
                'document_id' => $document->id,
                'invitation_id' => $invitation->id,
                'document_field_id' => $field->id,
                'user_id' => null,
                'signer_name' => $data['signer_name'],
                'signer_email' => $data['signer_email'] ?? null,
                'signature_data' => $data['signature_data'],
                'signed_at' => now(),
                'ip_address' => $data['ip_address'] ?? $request->ip(),
                'metadata' => $data['metadata'] ?? null,
            ]);
        })->values();

        $invitation->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        $this->signedPdfService->refresh($document);
        $this->signedPdfService->finalizeDocumentIfComplete($document);

        $this->auditLogger->recordAnonymous(
            action: 'signature_added',
            document: $document,
            documentTitle: $document->title,
            details: sprintf('Signature added by %s.', $data['signer_name']),
            ipAddress: $request->ip(),
            invitationId: $invitation->id
        );

        $this->auditLogger->recordAnonymous(
            action: 'signing_completed',
            document: $document,
            documentTitle: $document->title,
            details: sprintf('Signing completed for %s.', $document->title),
            ipAddress: $request->ip(),
            invitationId: $invitation->id
        );

        $this->notifyDocumentOwnerCompleted($request, $document, $invitation, 'signing_completed', $data['signer_name']);

        return response()->json([
            'message' => 'Signing completed successfully.',
            'data' => [
                'invitation' => $this->serializeInvitation($document->fresh(), $invitation->fresh()),
                'document' => $this->serializeAccessDocument(
                    $document->fresh(['owner', 'createdBy', 'user', 'assignedBy', 'fields', 'comments.user', 'comments.invitation', 'signatures.user', 'signatures.invitation', 'invitations']),
                    $invitation->fresh()
                ),
                'signatures' => $signatures->map(fn (Signature $signature) => $this->serializeSignature($document, $signature))->values(),
            ],
        ]);
    }

    public function complete(Request $request, string $token)
    {
        $invitation = $this->resolveInvitationFromToken($token, true);

        if ($invitation->invitation_type === 'sign') {
            return response()->json([
                'message' => 'Signing workflow already completes on sign.',
                'data' => [
                    'invitation' => $this->serializeInvitation($invitation->document()->firstOrFail(), $invitation),
                ],
            ]);
        }

        return $this->review($request, $token);
    }

    private function resolveInvitationFromToken(string $token, bool $allowCompleted = false): DocumentInvitation
    {
        $hash = hash('sha256', $token);
        $invitation = DocumentInvitation::query()->with(['document', 'document.fields', 'document.comments.user', 'document.comments.invitation', 'document.signatures.user', 'document.signatures.invitation', 'document.invitations'])
            ->where('access_token_hash', $hash)
            ->first();

        abort_if(! $invitation, 404, 'Invitation not found.');

        if ($invitation->expires_at && $invitation->expires_at->isPast()) {
            $invitation->forceFill(['status' => 'expired'])->save();
            abort(410, 'This invitation has expired.');
        }

        if (in_array($invitation->status, ['revoked', 'expired'], true)) {
            abort(410, 'This invitation is no longer available.');
        }

        if (! $allowCompleted && $invitation->status === 'completed') {
            abort(410, 'This invitation has already been completed.');
        }

        return $invitation;
    }

    private function completeInvitationReview(Request $request, DocumentInvitation $invitation, Document $document, string $authorName): void
    {
        $invitation->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        $document->forceFill([
            'status' => 'reviewed',
            'review_acknowledged' => true,
            'acknowledged_at' => now(),
        ])->save();

        $this->signedPdfService->finalizeDocumentIfComplete($document);

        $this->auditLogger->recordAnonymous(
            action: 'review_completed',
            document: $document,
            documentTitle: $document->title,
            details: sprintf('Review completed by %s.', $authorName),
            ipAddress: $request->ip(),
            invitationId: $invitation->id
        );

        $this->notifyDocumentOwnerCompleted($request, $document, $invitation, 'review_completed', $authorName);
    }

    private function markInvitationViewed(Request $request, Document $document, DocumentInvitation $invitation): void
    {
        if ($invitation->status !== 'pending') {
            return;
        }

        $invitation->forceFill([
            'status' => 'viewed',
            'viewed_at' => now(),
        ])->save();

        $this->auditLogger->recordAnonymous(
            action: 'invitation_viewed',
            document: $document,
            documentTitle: $document->title,
            details: sprintf('Invitation viewed for %s.', $document->title),
            ipAddress: $request->ip(),
            invitationId: $invitation->id
        );
    }

    private function buildInvitationMailData(Document $document, DocumentInvitation $invitation, $sender, string $rawToken, string $frontendUrl): array
    {
        $actionPath = '/access?token=' . $rawToken;
        $typeLabel = $invitation->invitation_type === 'sign' ? 'Signature Invitation' : 'Review Invitation';
        $actionLabel = $invitation->invitation_type === 'sign' ? 'Open Signing Page' : 'Open Review Page';

        return [
            'subject_line' => sprintf('%s: %s', $typeLabel, $document->title),
            'portal_name' => config('app.name'),
            'type_label' => $typeLabel,
            'recipient_name' => $invitation->recipient_name ?: $invitation->recipient_email,
            'sender_name' => $sender->username,
            'sender_email' => $sender->email,
            'recipient_email' => $invitation->recipient_email,
            'invitation_type' => $invitation->invitation_type,
            'role_label' => $invitation->invitation_type === 'sign' ? 'Signer' : 'Reviewer',
            'document_title' => $document->title,
            'document_id' => $document->document_uuid,
            'document_status' => $document->status,
            'action_label' => $actionLabel,
            'action_url' => $frontendUrl . $actionPath,
            'details' => [
                ['label' => 'Document title', 'value' => $document->title],
                ['label' => 'Document ID', 'value' => $document->document_uuid],
                ['label' => 'Recipient', 'value' => $invitation->recipient_name ?: $invitation->recipient_email],
                ['label' => 'Invitation type', 'value' => $typeLabel],
                ['label' => 'Assigned by', 'value' => $sender->username],
                ['label' => 'Expires at', 'value' => optional($invitation->expires_at)->toDayDateTimeString() ?? 'Not available'],
                ['label' => 'Current status', 'value' => $document->status],
                ['label' => 'File name', 'value' => $document->file_name ?: 'Not provided'],
                ['label' => 'File type', 'value' => $document->file_type ?: 'Not provided'],
            ],
            'intro_paragraph' => $invitation->invitation_type === 'sign'
                ? 'You have been invited to sign this document. Please open the secure access link below to continue.'
                : 'You have been invited to review this document. Please open the secure access link below to continue.',
            'review_note' => 'The access link is tokenized and only the intended recipient should use it.',
            'instructions' => $invitation->invitation_type === 'sign'
                ? [
                    'Open the secure link below.',
                    'Review the document and apply your signature when ready.',
                    'Use the invitation token to continue without a separate account if allowed.',
                ]
                : [
                    'Open the secure link below.',
                    'Review the document carefully and add any comments needed.',
                    'Complete the review when finished.',
                ],
            'support_email' => config('mail.from.address'),
            'footer_note' => 'If you were not expecting this invitation, you can safely ignore this email.',
        ];
    }

    private function notifyDocumentOwnerCompleted(Request $request, Document $document, DocumentInvitation $invitation, string $completionType, string $actorName): void
    {
        $document->loadMissing(['owner', 'createdBy']);
        $recipient = $document->owner ?: $document->createdBy;

        if (! $recipient || ! filled($recipient->email)) {
            return;
        }

        $mailData = $this->buildCompletionMailData($document, $invitation, $recipient, $completionType, $actorName);

        Mail::to($recipient->email)->queue(
            (new DocumentCompletionMail($mailData))->afterCommit()
        );

        NotificationEvent::query()->create([
            'event_type' => $completionType,
            'action' => $completionType,
            'channel' => 'mail',
            'recipient_name' => $recipient->username,
            'recipient_email' => $recipient->email,
            'user_id' => $recipient->id,
            'document_id' => $document->id,
            'invitation_id' => $invitation->id,
            'subject' => $mailData['subject_line'],
            'payload' => $mailData,
            'status' => 'queued',
        ]);
    }

    private function buildCompletionMailData(Document $document, DocumentInvitation $invitation, User $recipient, string $completionType, string $actorName): array
    {
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $subjectLabel = str_starts_with($completionType, 'sign') ? 'Signing Completed' : 'Review Completed';

        return [
            'subject_line' => sprintf('%s: %s', $subjectLabel, $document->title),
            'portal_name' => config('app.name'),
            'recipient_name' => $recipient->username,
            'summary' => str_starts_with($completionType, 'sign')
                ? sprintf('%s completed signing for "%s".', $actorName, $document->title)
                : sprintf('%s completed a review for "%s".', $actorName, $document->title),
            'action_label' => 'Open Documents',
            'action_url' => $frontendUrl . '/documents',
            'details' => [
                ['label' => 'Document title', 'value' => $document->title],
                ['label' => 'Document ID', 'value' => $document->document_uuid],
                ['label' => 'Completed by', 'value' => $actorName],
                ['label' => 'Invitation type', 'value' => $invitation->invitation_type],
                ['label' => 'Completed at', 'value' => now()->toDayDateTimeString()],
                ['label' => 'Current status', 'value' => $document->status],
            ],
            'footer_note' => 'This notification was generated automatically from the DRS workflow.',
        ];
    }

    private function serializeInvitation(Document $document, DocumentInvitation $invitation): array
    {
        return [
            'id' => (string) $invitation->id,
            'documentId' => $document->document_uuid,
            'recipientName' => $invitation->recipient_name,
            'recipientEmail' => $invitation->recipient_email,
            'invitedByUsername' => $invitation->relationLoaded('invitedByUser') && $invitation->invitedByUser
                ? $invitation->invitedByUser->username
                : null,
            'invitedByEmail' => $invitation->relationLoaded('invitedByUser') && $invitation->invitedByUser
                ? $invitation->invitedByUser->email
                : null,
            'invitationType' => $invitation->invitation_type,
            'status' => $invitation->status,
            'recipientOrder' => $invitation->recipient_order,
            'canReview' => (bool) $invitation->can_review,
            'canComment' => (bool) $invitation->can_comment,
            'canSign' => (bool) $invitation->can_sign,
            'viewedAt' => optional($invitation->viewed_at)->toISOString(),
            'completedAt' => optional($invitation->completed_at)->toISOString(),
            'revokedAt' => optional($invitation->revoked_at)->toISOString(),
            'expiresAt' => optional($invitation->expires_at)->toISOString(),
            'createdAt' => optional($invitation->created_at)->toISOString(),
            'updatedAt' => optional($invitation->updated_at)->toISOString(),
        ];
    }

    private function serializeInboxDocument(Document $document, DocumentInvitation $invitation): array
    {
        return [
            'id' => (string) $document->id,
            'documentId' => $document->document_uuid,
            'ownerId' => $document->owner_id ? (string) $document->owner_id : null,
            'createdById' => $document->created_by_id ? (string) $document->created_by_id : null,
            'userId' => $document->user_id ? (string) $document->user_id : null,
            'ownerUsername' => $document->relationLoaded('owner') && $document->owner ? $document->owner->username : null,
            'createdByUsername' => $document->relationLoaded('createdBy') && $document->createdBy ? $document->createdBy->username : null,
            'title' => $document->title,
            'content' => $document->content,
            'fileName' => $document->file_name,
            'fileType' => $document->file_type,
            'fileSize' => $document->file_size,
            'assignedAt' => optional($document->assigned_at)->toISOString(),
            'sentAt' => optional($document->sent_at)->toISOString(),
            'expiresAt' => optional($invitation->expires_at ?? $document->expires_at)->toISOString(),
            'status' => $document->status,
            'reviewAcknowledged' => (bool) $document->review_acknowledged,
            'acknowledgedAt' => optional($document->acknowledged_at)->toISOString(),
            'signatureInvited' => (bool) $invitation->can_sign,
            'signatureInvitedAt' => optional($document->signature_invited_at)->toISOString(),
            'signatureCompleted' => (bool) $document->signature_completed,
            'signatureCompletedAt' => optional($document->signature_completed_at)->toISOString(),
            'completedAt' => optional($document->completed_at)->toISOString(),
            'archivedAt' => optional($document->archived_at)->toISOString(),
            'createdAt' => optional($document->created_at)->toISOString(),
            'updatedAt' => optional($document->updated_at)->toISOString(),
            'permissions' => [
                'canEdit' => false,
                'canReview' => (bool) $invitation->can_review,
                'canComment' => (bool) $invitation->can_comment || (bool) $invitation->can_review,
                'canSign' => (bool) $invitation->can_sign,
                'canDelete' => false,
                'canDownload' => true,
            ],
        ];
    }

    private function serializeAccessDocument(Document $document, DocumentInvitation $invitation): array
    {
        $signatureFields = $this->signedPdfService->resolveSignatureFieldsForInvitation($document, $invitation);
        $signatures = $this->signedPdfService->resolveAccessibleSignaturesForInvitation($document, $invitation);

        return [
            'id' => (string) $document->id,
            'documentId' => $document->document_uuid,
            'title' => $document->title,
            'content' => $document->content,
            'fileName' => $document->file_name,
            'fileType' => $document->file_type,
            'fileSize' => $document->file_size,
            'status' => $document->status,
            'fileData' => $document->file_data,
            'showSignaturesToSigners' => (bool) $document->show_signatures_to_signers,
            'signatureFields' => $signatureFields->map(fn (DocumentField $field) => [
                'id' => (string) $field->id,
                'invitationId' => $field->invitation_id ? (string) $field->invitation_id : null,
                'assignedRecipientEmail' => $field->assigned_recipient_email,
                'fieldType' => $field->field_type,
                'x' => (string) $field->x,
                'y' => (string) $field->y,
                'width' => (string) $field->width,
                'height' => (string) $field->height,
                'page' => $field->page,
                'required' => (bool) $field->required,
                'metadata' => $field->metadata,
            ])->values(),
            'signatures' => $signatures->map(fn (Signature $signature) => $this->serializeSignature($document, $signature))->values(),
            'comments' => $document->comments->map(fn (Comment $comment) => [
                'id' => (string) $comment->id,
                'documentId' => $document->document_uuid,
                'username' => $comment->author_name,
                'selectedText' => $comment->selected_text,
                'comment' => $comment->comment,
                'parentCommentId' => $comment->parent_comment_id ? (string) $comment->parent_comment_id : null,
                'timestamp' => optional($comment->created_at)->toISOString(),
                'createdAt' => optional($comment->created_at)->toISOString(),
                'updatedAt' => optional($comment->updated_at)->toISOString(),
            ])->values(),
            'permissions' => [
                'canEdit' => false,
                'canReview' => (bool) $invitation->can_review,
                'canComment' => (bool) $invitation->can_comment,
                'canSign' => (bool) $invitation->can_sign && $signatureFields->isNotEmpty(),
                'canDelete' => false,
                'canDownload' => true,
            ],
        ];
    }

    private function serializeComment(Document $document, Comment $comment): array
    {
        return [
            'id' => (string) $comment->id,
            'documentId' => $document->document_uuid,
            'userId' => $comment->user_id ? (string) $comment->user_id : null,
            'username' => $comment->author_name ?: $comment->user?->username,
            'selectedText' => $comment->selected_text,
            'comment' => $comment->comment,
            'timestamp' => optional($comment->created_at)->toISOString(),
            'createdAt' => optional($comment->created_at)->toISOString(),
            'updatedAt' => optional($comment->updated_at)->toISOString(),
            'parentCommentId' => $comment->parent_comment_id ? (string) $comment->parent_comment_id : null,
        ];
    }

    private function serializeSignature(Document $document, Signature $signature): array
    {
        return [
            'id' => (string) $signature->id,
            'documentId' => $document->document_uuid,
            'invitationId' => $signature->invitation_id ? (string) $signature->invitation_id : null,
            'documentFieldId' => $signature->document_field_id ? (string) $signature->document_field_id : null,
            'userId' => $signature->user_id ? (string) $signature->user_id : null,
            'username' => $signature->signer_name ?: $signature->user?->username,
            'signerName' => $signature->signer_name ?: $signature->user?->username,
            'signerEmail' => $signature->signer_email,
            'signatureData' => $signature->signature_data,
            'signedAt' => optional($signature->signed_at)->toISOString(),
            'ipAddress' => $signature->ip_address,
            'createdAt' => optional($signature->created_at)->toISOString(),
            'updatedAt' => optional($signature->updated_at)->toISOString(),
        ];
    }
}
