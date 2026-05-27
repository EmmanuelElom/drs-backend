<?php

namespace App\Http\Controllers\Api;

use App\Mail\DocumentInvitationMail;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\DocumentAssignment;
use App\Models\DocumentField;
use App\Models\DocumentInvitation;
use App\Models\Document;
use App\Models\NotificationEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DocumentCompletionNotifier;
use App\Services\SignedPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DocumentController extends Controller
{
    private const STORAGE_SETTING_KEY = 'document_storage_mode';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentCompletionNotifier $completionNotifier,
        private readonly SignedPdfService $signedPdfService
    )
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewAny', Document::class);

        $data = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'scope' => ['nullable', 'in:library,assigned,sent,completed,archived,all'],
            'storage_mode' => ['nullable', 'in:base64,upload,auto'],
            'user_id' => ['nullable', 'exists:users,id'],
            'all' => ['nullable', 'boolean'],
        ]);

        $scope = $data['scope'] ?? null;
        $query = Document::query()
            ->with(['owner', 'createdBy', 'user', 'assignedBy', 'fields', 'comments.user', 'comments.invitation', 'signatures.user', 'signatures.invitation', 'invitations']);

        if ($user->role !== 'admin') {
            $query->where(function ($inner) use ($user, $scope) {
                if ($scope === 'library') {
                    $inner->where('owner_id', $user->id)
                        ->whereIn('status', ['draft', 'saved', 'sent']);
                    return;
                }

                if ($scope === 'assigned') {
                    $inner->where('user_id', $user->id);
                    return;
                }

                if ($scope === 'sent') {
                    $inner->where('owner_id', $user->id)
                        ->whereNotNull('sent_at');
                    return;
                }

                if ($scope === 'completed') {
                    $inner->where(function ($owned) use ($user) {
                        $owned->where('owner_id', $user->id)
                            ->orWhere('created_by_id', $user->id)
                            ->orWhere('user_id', $user->id);
                    })->where(function ($completed) {
                        $completed->whereIn('status', ['reviewed', 'signed', 'completed'])
                            ->orWhereNotNull('completed_at');
                    });
                    return;
                }

                if ($scope === 'archived') {
                    $inner->where(function ($owned) use ($user) {
                        $owned->where('owner_id', $user->id)
                            ->orWhere('created_by_id', $user->id)
                            ->orWhere('user_id', $user->id);
                    })->whereNotNull('archived_at');
                    return;
                }

                $inner->where('owner_id', $user->id)
                    ->orWhere('created_by_id', $user->id)
                    ->orWhere('user_id', $user->id);
            });
        } elseif ($scope === 'library') {
            $query->whereIn('status', ['draft', 'saved', 'sent']);
        } elseif ($scope === 'assigned') {
            $query->whereNotNull('user_id');
        } elseif ($scope === 'sent') {
            $query->whereNotNull('sent_at');
        } elseif ($scope === 'completed') {
            $query->where(function ($completed) {
                $completed->whereIn('status', ['reviewed', 'signed', 'completed'])
                    ->orWhereNotNull('completed_at');
            });
        } elseif ($scope === 'archived') {
            $query->whereNotNull('archived_at');
        }

        $query
            ->when($data['search'] ?? null, function ($query, string $search) {
                $query->where(function ($inner) use ($search) {
                    $inner->where('title', 'like', "%{$search}%")
                        ->orWhere('content', 'like', "%{$search}%")
                        ->orWhere('file_name', 'like', "%{$search}%");
                });
            })
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['storage_mode'] ?? null, fn ($query, string $storageMode) => $query->where('storage_mode', $storageMode))
            ->when($data['user_id'] ?? null, fn ($query, string $userId) => $query->where('user_id', $userId))
            ->orderByDesc('id');

        if ($request->boolean('all') || ($scope === 'all' && $user->role === 'admin')) {
            $documents = $query->get()->map(fn (Document $document) => $this->serializeDocument($document))->values();

            return response()->json([
                'data' => $documents,
            ]);
        }

        $perPage = (int) ($data['perPage'] ?? $data['per_page'] ?? 10);
        $page = (int) ($data['page'] ?? 1);
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Document $document) => $this->serializeDocument($document))
                ->values(),
            'meta' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Document::class);

        $storageMode = $this->getStorageMode();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,saved,sent,in-review,reviewed,signed,completed,archived'],
            'user_id' => ['nullable', 'exists:users,id'],
            'days_allowed' => ['nullable', 'integer', 'min:1'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_size' => ['nullable', 'integer', 'min:0'],
            'file_type' => ['nullable', 'string', 'max:100'],
            'file_data' => ['nullable', 'string'],
            'file' => ['nullable', 'file', 'mimetypes:application/pdf', 'max:10240'],
        ]);

        $uploadedFile = $request->file('file');
        $hasUpload = $uploadedFile instanceof UploadedFile && $uploadedFile->isValid();
        $hasInlineFile = filled($data['file_data'] ?? null);

        if ($hasUpload || $hasInlineFile) {
            if ($storageMode === 'upload' && ! $hasUpload) {
                abort(422, 'A PDF upload is required for the current storage mode.');
            }

            if ($storageMode === 'base64' && ! $hasInlineFile) {
                abort(422, 'Base64 file data is required for the current storage mode.');
            }
        } elseif (! filled($data['content'] ?? null)) {
            $data['content'] = null;
        }

        $daysAllowed = isset($data['days_allowed']) ? (int) $data['days_allowed'] : null;
        $assignedAt = now();
        $storedFile = $hasUpload ? $this->storeUploadedFile($uploadedFile) : null;
        $hasAssignment = filled($data['user_id'] ?? null);
        $status = $data['status'] ?? ($hasAssignment ? 'in-review' : (($hasUpload || $hasInlineFile || filled($data['content'] ?? null)) ? 'saved' : 'draft'));
        $currentUser = $request->user();

        $document = Document::query()->create([
            'document_uuid' => (string) Str::uuid(),
            'owner_id' => $currentUser->id,
            'created_by_id' => $currentUser->id,
            'user_id' => $data['user_id'] ?? null,
            'assigned_by_id' => $hasAssignment ? $currentUser->id : null,
            'title' => $data['title'],
            'content' => $data['content'] ?? '',
            'file_name' => $hasUpload ? $storedFile['file_name'] : ($data['file_name'] ?? null),
            'file_size' => $hasUpload ? $storedFile['file_size'] : ($data['file_size'] ?? null),
            'file_type' => $hasUpload ? $storedFile['file_type'] : ($data['file_type'] ?? null),
            'file_data' => $hasUpload ? $storedFile['file_data'] : ($data['file_data'] ?? null),
            'file_disk' => $storedFile['file_disk'] ?? null,
            'file_path' => $storedFile['file_path'] ?? null,
            'storage_mode' => $hasUpload ? 'upload' : ($hasInlineFile ? 'base64' : $storageMode),
            'days_allowed' => $daysAllowed,
            'assigned_at' => $hasAssignment ? $assignedAt : null,
            'sent_at' => $hasAssignment ? $assignedAt : null,
            'expires_at' => ($hasAssignment && $daysAllowed) ? $assignedAt->copy()->addDays($daysAllowed) : null,
            'status' => $status,
            'review_acknowledged' => false,
            'signature_invited' => false,
            'signature_completed' => false,
        ]);

        if ($hasAssignment) {
            DocumentAssignment::query()->create([
                'document_id' => $document->id,
                'user_id' => $data['user_id'],
                'assigned_by' => $currentUser->id,
                'assigned_at' => $assignedAt,
                'expires_at' => $document->expires_at,
                'days_allowed' => $daysAllowed,
                'status' => 'in-review',
            ]);
        }

        $document->load(['user', 'owner', 'createdBy', 'fields', 'invitations']);

        $this->auditLogger->fromRequest(
            action: $hasAssignment ? 'document_assigned' : 'document_created',
            request: $request,
            targetUser: $hasAssignment ? $document->user : null,
            document: $document,
            details: $hasAssignment
                ? sprintf('Assigned "%s" to %s.', $document->title, $document->user?->username)
                : sprintf('Created document "%s".', $document->title)
        );

        if ($hasUpload) {
            $this->auditLogger->fromRequest(
                action: 'document_uploaded',
                request: $request,
                targetUser: $hasAssignment ? $document->user : null,
                document: $document,
                details: sprintf('Uploaded "%s".', $document->title)
            );
        }

        if ($hasAssignment) {
            $this->queueInvitationEmail(
                document: $document,
                recipient: $document->user,
                sender: $currentUser,
                invitationType: 'review'
            );
        }

        return response()->json([
            'message' => 'Document created successfully.',
            'data' => $this->serializeDocument($document, true, $currentUser),
        ], 201);
    }

    public function show(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        $document->load(['owner', 'createdBy', 'user', 'assignedBy', 'fields', 'comments.user', 'comments.invitation', 'signatures.user', 'signatures.invitation', 'invitations', 'assignments']);

        return response()->json([
            'data' => $this->serializeDocument($document, true, $request->user()),
        ]);
    }

    public function update(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['nullable', 'string'],
            'status' => ['nullable', 'in:draft,saved,sent,in-review,reviewed,signed,completed,archived'],
            'days_allowed' => ['nullable', 'integer', 'min:1'],
            'file_name' => ['nullable', 'string', 'max:255'],
            'file_size' => ['nullable', 'integer', 'min:0'],
            'file_type' => ['nullable', 'string', 'max:100'],
            'storage_mode' => ['nullable', 'in:base64,upload,auto'],
        ]);

        $document->fill($data)->save();

        $this->auditLogger->fromRequest(
            action: 'document_updated',
            request: $request,
            document: $document,
            details: sprintf('Updated document "%s".', $document->title)
        );

        return response()->json([
            'message' => 'Document updated successfully.',
            'data' => $this->serializeDocument($document->fresh(['owner', 'createdBy', 'user', 'assignedBy', 'fields', 'comments.user', 'comments.invitation', 'signatures.user', 'signatures.invitation', 'invitations', 'assignments']), true, $request->user()),
        ]);
    }

    public function archive(Request $request, Document $document)
    {
        $this->authorize('archive', $document);

        $document->forceFill([
            'status' => 'archived',
            'archived_at' => now(),
        ])->save();

        $this->auditLogger->fromRequest(
            action: 'document_archived',
            request: $request,
            document: $document,
            details: sprintf('Archived document "%s".', $document->title)
        );

        return response()->json([
            'message' => 'Document archived successfully.',
            'data' => $this->serializeDocument($document->fresh(['owner', 'createdBy', 'user', 'assignedBy', 'fields', 'comments.user', 'comments.invitation', 'signatures.user', 'signatures.invitation', 'invitations', 'assignments']), true, $request->user()),
        ]);
    }

    public function upload(Request $request, Document $document)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'file' => ['required', 'file', 'mimetypes:application/pdf,text/plain', 'max:10240'],
        ]);

        $shouldResetWorkflow = in_array($document->status ?? null, ['signed', 'completed'], true) || (bool) $document->signature_completed || filled($document->completed_at);

        $this->deleteStoredFile($document);

        $uploadedFile = $request->file('file');
        $storedFile = $this->storeUploadedFile($uploadedFile);

        $document->forceFill([
            'file_name' => $storedFile['file_name'],
            'file_size' => $storedFile['file_size'],
            'file_type' => $storedFile['file_type'],
            'file_data' => $storedFile['file_data'],
            'file_disk' => $storedFile['file_disk'],
            'file_path' => $storedFile['file_path'],
            'signed_file_disk' => null,
            'signed_file_path' => null,
            'signed_file_generated_at' => null,
            'review_acknowledged' => $shouldResetWorkflow ? false : (bool) $document->review_acknowledged,
            'acknowledged_at' => $shouldResetWorkflow ? null : $document->acknowledged_at,
            'signature_invited' => $shouldResetWorkflow ? false : (bool) $document->signature_invited,
            'signature_invited_at' => $shouldResetWorkflow ? null : $document->signature_invited_at,
            'signature_completed' => false,
            'signature_completed_at' => null,
            'completed_at' => null,
            'status' => $shouldResetWorkflow ? 'saved' : $document->status,
            'storage_mode' => 'upload',
        ])->save();

        $this->auditLogger->fromRequest(
            action: 'document_uploaded',
            request: $request,
            document: $document,
            details: sprintf('Uploaded file for "%s".', $document->title)
        );

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'data' => $this->serializeDocument($document->fresh(['owner', 'createdBy', 'user', 'assignedBy', 'fields', 'comments.user', 'comments.invitation', 'signatures.user', 'signatures.invitation', 'invitations', 'assignments']), true, $request->user()),
        ]);
    }

    public function preview(Request $request, Document $document)
    {
        return $this->streamDocumentFile($request, $document, false);
    }

    public function download(Request $request, Document $document)
    {
        return $this->streamDocumentFile($request, $document, true);
    }

    public function downloadFile(Request $request, Document $document)
    {
        return $this->download($request, $document);
    }

    public function acknowledge(Request $request, Document $document)
    {
        $this->authorize('acknowledge', $document);

        $document->forceFill([
            'review_acknowledged' => true,
            'acknowledged_at' => now(),
            'status' => 'reviewed',
        ])->save();

        $document->assignments()->update([
            'review_acknowledged' => true,
            'acknowledged_at' => now(),
            'status' => 'reviewed',
        ]);

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'review_completed',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Marked "%s" as reviewed.', $document->title)
        );

        $this->completionNotifier->notify(
            request: $request,
            document: $document,
            completionType: 'review_completed',
            actorName: $request->user()->username
        );

        return response()->json([
            'message' => 'Review acknowledged successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function inviteSignature(Request $request, Document $document)
    {
        $this->authorize('inviteSignature', $document);

        $document->forceFill([
            'signature_invited' => true,
            'signature_invited_at' => now(),
            'status' => 'reviewed',
        ])->save();

        $document->assignments()->update([
            'signature_invited' => true,
            'signature_invited_at' => now(),
            'status' => 'reviewed',
        ]);

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'signature_invited',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Invited %s to sign "%s".', $document->user?->username, $document->title)
        );

        $this->queueInvitationEmail(
            document: $document,
            recipient: $document->user,
            sender: $request->user(),
            invitationType: 'signature'
        );

        return response()->json([
            'message' => 'Signature invitation sent successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function reassign(Request $request, Document $document)
    {
        $this->authorize('reassign', $document);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
        ]);

        $previousUser = $document->user;
        $newUser = User::query()->findOrFail($data['user_id']);

        $daysAllowed = (int) $document->days_allowed;

        $document->forceFill([
            'user_id' => $newUser->id,
            'assigned_at' => now(),
            'expires_at' => now()->copy()->addDays($daysAllowed),
            'review_acknowledged' => false,
            'acknowledged_at' => null,
            'signature_invited' => false,
            'signature_invited_at' => null,
            'signature_completed' => false,
            'signature_completed_at' => null,
            'status' => 'in-review',
        ])->save();

        $document->assignments()->delete();
        DocumentAssignment::query()->create([
            'document_id' => $document->id,
            'user_id' => $newUser->id,
            'assigned_by' => $request->user()->id,
            'assigned_at' => now(),
            'expires_at' => now()->copy()->addDays($daysAllowed),
            'days_allowed' => $daysAllowed,
            'status' => 'in-review',
        ]);

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'document_assigned',
            request: $request,
            targetUser: $newUser,
            document: $document,
            details: sprintf(
                'Reassigned "%s" from %s to %s.',
                $document->title,
                $previousUser?->username,
                $newUser->username
            )
        );

        $this->queueInvitationEmail(
            document: $document,
            recipient: $newUser,
            sender: $request->user(),
            invitationType: 'review'
        );

        return response()->json([
            'message' => 'Document reassigned successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function updateDays(Request $request, Document $document)
    {
        $this->authorize('updateDays', $document);

        $data = $request->validate([
            'days_allowed' => ['required', 'integer', 'min:1'],
        ]);

        $startDate = $document->assigned_at ?? now();

        $daysAllowed = (int) $data['days_allowed'];

        $document->forceFill([
            'days_allowed' => $daysAllowed,
            'expires_at' => $startDate->copy()->addDays($daysAllowed),
        ])->save();

        $document->assignments()->update([
            'days_allowed' => $daysAllowed,
            'expires_at' => $startDate->copy()->addDays($daysAllowed),
        ]);

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'document_assigned',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Updated review days for "%s" to %d.', $document->title, $document->days_allowed)
        );

        return response()->json([
            'message' => 'Review period updated successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function updateStatus(Request $request, Document $document)
    {
        $this->authorize('updateStatus', $document);

        $data = $request->validate([
            'status' => ['required', 'in:pending,in-review,reviewed,signed'],
        ]);

        $document->forceFill([
            'status' => $data['status'],
        ])->save();

        $document->load('user');

        $this->auditLogger->fromRequest(
            action: 'review_completed',
            request: $request,
            targetUser: $document->user,
            document: $document,
            details: sprintf('Updated status for "%s" to %s.', $document->title, $document->status)
        );

        return response()->json([
            'message' => 'Document status updated successfully.',
            'data' => $this->serializeDocument($document),
        ]);
    }

    public function destroy(Request $request, Document $document)
    {
        $this->authorize('delete', $document);

        $title = $document->title;
        $targetUser = $document->user;
        $this->deleteStoredFile($document);

        $document->delete();

        $this->auditLogger->fromRequest(
            action: 'document_deleted',
            request: $request,
            targetUser: $targetUser,
            documentTitle: $title,
            details: sprintf('Deleted document "%s".', $title)
        );

        return response()->json([
            'message' => 'Document deleted successfully.',
        ]);
    }

    private function serializeDocument(Document $document, bool $includeRelations = false, ?User $actingUser = null): array
    {
        $actingUser ??= request()->user();

        return [
            'id' => (string) $document->id,
            'documentId' => $document->document_uuid,
            'ownerId' => $document->owner_id ? (string) $document->owner_id : null,
            'createdById' => $document->created_by_id ? (string) $document->created_by_id : null,
            'userId' => $document->user_id ? (string) $document->user_id : null,
            'username' => $document->relationLoaded('user') && $document->user ? $document->user->username : null,
            'ownerUsername' => $document->relationLoaded('owner') && $document->owner ? $document->owner->username : null,
            'createdByUsername' => $document->relationLoaded('createdBy') && $document->createdBy ? $document->createdBy->username : null,
            'assignedAt' => optional($document->assigned_at)->toISOString(),
            'sentAt' => optional($document->sent_at)->toISOString(),
            'expiresAt' => optional($document->expires_at)->toISOString(),
            'daysAllowed' => $document->days_allowed,
            'title' => $document->title,
            'content' => $document->content,
            'fileType' => $document->file_type,
            'reviewAcknowledged' => (bool) $document->review_acknowledged,
            'acknowledgedAt' => optional($document->acknowledged_at)->toISOString(),
            'signatureInvited' => (bool) $document->signature_invited,
            'signatureInvitedAt' => optional($document->signature_invited_at)->toISOString(),
            'signatureCompleted' => (bool) $document->signature_completed,
            'signatureCompletedAt' => optional($document->signature_completed_at)->toISOString(),
            'completedAt' => optional($document->completed_at)->toISOString(),
            'archivedAt' => optional($document->archived_at)->toISOString(),
            'createdAt' => optional($document->created_at)->toISOString(),
            'updatedAt' => optional($document->updated_at)->toISOString(),
            'status' => $document->status,
            'fileName' => $document->file_name,
            'fileSize' => $document->file_size,
            'assignedBy' => $document->relationLoaded('assignedBy') && $document->assignedBy ? $document->assignedBy->username : null,
            'signatureFields' => $document->relationLoaded('fields')
                ? $document->fields->map(fn (DocumentField $field) => $this->serializeField($document, $field))->values()
                : [],
            'comments' => $includeRelations && $document->relationLoaded('comments')
                ? $document->comments->map(fn ($comment) => $this->serializeComment($document, $comment))->values()
                : [],
            'signatures' => $includeRelations && $document->relationLoaded('signatures')
                ? $document->signatures->map(fn ($signature) => $this->serializeSignature($document, $signature))->values()
                : [],
            'invitations' => $includeRelations && $document->relationLoaded('invitations')
                ? $document->invitations->map(fn (DocumentInvitation $invitation) => $this->serializeInvitation($document, $invitation))->values()
                : [],
            'permissions' => $this->serializePermissions($document, $actingUser),
            'storageMode' => $document->storage_mode,
            'fileData' => $this->resolveDocumentFileData($document),
        ];
    }

    private function serializeComment(Document $document, $comment): array
    {
        return [
            'id' => (string) $comment->id,
            'documentId' => $document->document_uuid,
            'invitationId' => $comment->invitation_id ? (string) $comment->invitation_id : null,
            'userId' => $comment->user_id ? (string) $comment->user_id : null,
            'username' => $comment->author_name ?: $comment->user?->username,
            'authorName' => $comment->author_name ?: $comment->user?->username,
            'authorEmail' => $comment->author_email,
            'selectedText' => $comment->selected_text,
            'comment' => $comment->comment,
            'parentCommentId' => $comment->parent_comment_id ? (string) $comment->parent_comment_id : null,
            'page' => $comment->page,
            'annotationMetadata' => $comment->annotation_metadata,
            'resolvedAt' => optional($comment->resolved_at)->toISOString(),
            'createdAt' => optional($comment->created_at)->toISOString(),
            'timestamp' => optional($comment->created_at)->toISOString(),
            'updatedAt' => optional($comment->updated_at)->toISOString(),
        ];
    }

    private function serializeSignature(Document $document, $signature): array
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

    private function serializeInvitation(Document $document, DocumentInvitation $invitation): array
    {
        return [
            'id' => (string) $invitation->id,
            'documentId' => $document->document_uuid,
            'recipientName' => $invitation->recipient_name,
            'recipientEmail' => $invitation->recipient_email,
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

    private function serializeField(Document $document, DocumentField $field): array
    {
        return [
            'id' => (string) $field->id,
            'documentId' => $document->document_uuid,
            'invitationId' => $field->invitation_id ? (string) $field->invitation_id : null,
            'assignedRecipientEmail' => $field->assigned_recipient_email,
            'fieldType' => $field->field_type,
            'page' => $field->page,
            'x' => (string) $field->x,
            'y' => (string) $field->y,
            'width' => (string) $field->width,
            'height' => (string) $field->height,
            'required' => (bool) $field->required,
            'metadata' => $field->metadata,
            'createdAt' => optional($field->created_at)->toISOString(),
            'updatedAt' => optional($field->updated_at)->toISOString(),
        ];
    }

    private function serializePermissions(Document $document, ?User $user): array
    {
        $isAdmin = $user?->role === 'admin';
        $isOwner = $user && ((string) $document->owner_id === (string) $user->id || (string) $document->created_by_id === (string) $user->id);
        $isAssigned = $user && (string) $document->user_id === (string) $user->id;
        $hasReviewInvitation = $this->hasInvitationAccess($document, $user, permission: 'review', allowCompleted: true);
        $hasCommentInvitation = $this->hasInvitationAccess($document, $user, permission: 'comment', allowCompleted: false)
            || $this->hasInvitationAccess($document, $user, permission: 'review', allowCompleted: false);
        $hasSignInvitation = $this->hasInvitationAccess($document, $user, permission: 'sign', allowCompleted: false);
        $hasAnyInvitation = $this->hasInvitationAccess($document, $user, allowCompleted: true);

        return [
            'canEdit' => $isAdmin || $isOwner,
            'canReview' => $isAdmin || $isOwner || $isAssigned || $hasReviewInvitation,
            'canComment' => $isAdmin || $isOwner || $isAssigned || $hasCommentInvitation,
            'canSign' => $isAdmin || $isOwner || $isAssigned || (bool) $document->signature_invited || $hasSignInvitation,
            'canDelete' => $isAdmin || $isOwner,
            'canDownload' => $isAdmin || $isOwner || $isAssigned || $hasAnyInvitation,
        ];
    }

    private function hasInvitationAccess(
        Document $document,
        ?User $user,
        ?string $permission = null,
        bool $allowCompleted = true
    ): bool {
        if (! $user || ! filled($user->email)) {
            return false;
        }

        $invitations = $document->relationLoaded('invitations')
            ? $document->invitations
            : $document->invitations()->get();

        return $invitations->contains(function (DocumentInvitation $invitation) use ($user, $permission, $allowCompleted) {
            if ((string) $invitation->recipient_email !== (string) $user->email) {
                return false;
            }

            if (in_array($invitation->status, ['revoked', 'expired'], true)) {
                return false;
            }

            if (! $allowCompleted && $invitation->status === 'completed') {
                return false;
            }

            if ($permission === 'review' && ! $invitation->can_review) {
                return false;
            }

            if ($permission === 'comment' && ! $invitation->can_comment && ! $invitation->can_review) {
                return false;
            }

            if ($permission === 'sign' && ! $invitation->can_sign) {
                return false;
            }

            return true;
        });
    }

    private function getStorageMode(): string
    {
        if (! Schema::hasTable('app_settings')) {
            return 'auto';
        }

        $setting = AppSetting::query()
            ->where('key', self::STORAGE_SETTING_KEY)
            ->value('value');

        return in_array($setting, ['base64', 'upload', 'auto'], true) ? $setting : 'auto';
    }

    private function queueInvitationEmail(Document $document, ?User $recipient, User $sender, string $invitationType): void
    {
        if (! $recipient || ! filled($recipient->email)) {
            return;
        }

        $data = $this->buildInvitationMailData($document, $recipient, $sender, $invitationType);

        Mail::to($recipient->email)->queue(
            (new DocumentInvitationMail($data))->afterCommit()
        );

        $eventType = $invitationType === 'signature' ? 'signature_invitation_sent' : 'review_invitation_sent';

        NotificationEvent::query()->create([
            'event_type' => $eventType,
            'action' => $eventType,
            'channel' => 'mail',
            'recipient_name' => $recipient->username,
            'recipient_email' => $recipient->email,
            'user_id' => $sender->id,
            'document_id' => $document->id,
            'invitation_id' => null,
            'subject' => $data['subject_line'],
            'payload' => $data,
            'status' => 'queued',
        ]);
    }

    private function buildInvitationMailData(Document $document, User $recipient, User $sender, string $invitationType): array
    {
        $isSignatureInvitation = $invitationType === 'signature';
        $frontendUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/');
        $actionPath = $isSignatureInvitation
            ? "/sign/{$document->document_uuid}"
            : "/review/{$document->document_uuid}";
        $typeLabel = $isSignatureInvitation ? 'Co-signee Invitation' : 'Review Invitation';
        $roleLabel = $isSignatureInvitation ? 'Co-signee' : 'Reviewer';
        $actionLabel = $isSignatureInvitation ? 'Open Signing Page' : 'Open Review Page';

        return [
            'subject_line' => sprintf('%s: %s', $typeLabel, $document->title),
            'portal_name' => config('app.name'),
            'type_label' => $typeLabel,
            'recipient_name' => $recipient->username,
            'sender_name' => $sender->username,
            'sender_email' => $sender->email,
            'recipient_email' => $recipient->email,
            'invitation_type' => $invitationType,
            'role_label' => $roleLabel,
            'document_title' => $document->title,
            'document_id' => $document->document_uuid,
            'document_status' => $document->status,
            'action_label' => $actionLabel,
            'action_url' => $frontendUrl . $actionPath,
            'details' => [
                ['label' => 'Document title', 'value' => $document->title],
                ['label' => 'Document ID', 'value' => $document->document_uuid],
                ['label' => 'Your role', 'value' => $roleLabel],
                ['label' => 'Assigned by', 'value' => $this->formatPersonLabel($sender)],
                ['label' => 'Assigned at', 'value' => optional($document->assigned_at)->toDayDateTimeString() ?? 'Not available'],
                ['label' => 'Expires at', 'value' => optional($document->expires_at)->toDayDateTimeString() ?? 'Not available'],
                ['label' => 'Days allowed', 'value' => (string) $document->days_allowed],
                ['label' => 'Current status', 'value' => $this->formatStatusLabel($document->status)],
                ['label' => 'File name', 'value' => $document->file_name ?: 'Not provided'],
                ['label' => 'File type', 'value' => $document->file_type ?: 'Not provided'],
                ['label' => 'File size', 'value' => $this->formatFileSize($document->file_size)],
            ],
            'intro_paragraph' => $isSignatureInvitation
                ? 'You have been invited as a co-signee. Please review the completed review context and apply your signature when you are ready.'
                : 'You have been invited as the reviewer for this document. Please open the review page, read the file, and leave any comments before completing the review.',
            'review_note' => $isSignatureInvitation
                ? 'Once you sign, the document workflow will continue for the next stage.'
                : 'Please complete your review before the expiration date shown below so the document can move forward.',
            'instructions' => $isSignatureInvitation
                ? [
                    'Sign in with your DRS account.',
                    'Open the signing page using the button below.',
                    'Review the document and apply your signature when you are satisfied.',
                ]
                : [
                    'Sign in with your DRS account.',
                    'Open the review page using the button below.',
                    'Read the document carefully, add comments where needed, and complete the review before the deadline.',
                ],
            'support_email' => config('mail.from.address'),
            'footer_note' => 'If you were not expecting this invitation, you can safely ignore this email.',
        ];
    }

    private function formatPersonLabel(User $user): string
    {
        return $user->email
            ? sprintf('%s (%s)', $user->username, $user->email)
            : $user->username;
    }

    private function formatStatusLabel(?string $status): string
    {
        if (! $status) {
            return 'Not available';
        }

        return str_replace('-', ' ', Str::headline($status));
    }

    private function formatFileSize(?int $fileSize): string
    {
        if (! $fileSize || $fileSize <= 0) {
            return 'Not provided';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $fileSize;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return $unitIndex === 0
            ? sprintf('%d %s', (int) round($size), $units[$unitIndex])
            : sprintf('%.1f %s', $size, $units[$unitIndex]);
    }

    private function storeUploadedFile(UploadedFile $file): array
    {
        $disk = config('filesystems.default', 'local');
        $fileName = $file->getClientOriginalName();
        $fileType = $file->getMimeType() ?: $file->getClientMimeType() ?: 'application/pdf';
        $fileSize = $file->getSize() ?: 0;
        $extension = strtolower($file->getClientOriginalExtension() ?: ($fileType === 'text/plain' ? 'txt' : 'pdf'));
        $safeName = Str::uuid()->toString() . '.' . $extension;
        $filePath = $file->storeAs('documents', $safeName, $disk);
        $fileContents = file_get_contents($file->getRealPath()) ?: '';

        return [
            'file_disk' => $disk,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'file_type' => $fileType,
            'file_data' => sprintf('data:%s;base64,%s', $fileType, base64_encode($fileContents)),
        ];
    }

    private function resolveDocumentFileData(Document $document): ?string
    {
        if ($document->file_data) {
            return $document->file_data;
        }

        if (! $document->file_path) {
            return null;
        }

        $disk = $document->file_disk ?: config('filesystems.default');

        if (! Storage::disk($disk)->exists($document->file_path)) {
            return null;
        }

        $mimeType = $document->file_type ?: Storage::disk($disk)->mimeType($document->file_path) ?: 'application/pdf';
        $contents = Storage::disk($disk)->get($document->file_path);
        $fileData = sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));

        $document->forceFill([
            'file_data' => $fileData,
        ])->saveQuietly();

        return $fileData;
    }

    private function deleteStoredFile(Document $document): void
    {
        $disk = $document->file_disk ?: config('filesystems.default');

        if ($document->file_path && Storage::disk($disk)->exists($document->file_path)) {
            Storage::disk($disk)->delete($document->file_path);
        }

        if ($document->signed_file_path) {
            $signedDisk = $document->signed_file_disk ?: $disk;

            if (Storage::disk($signedDisk)->exists($document->signed_file_path)) {
                Storage::disk($signedDisk)->delete($document->signed_file_path);
            }
        }
    }

    private function streamDocumentFile(Request $request, Document $document, bool $download = false)
    {
        $this->authorize('view', $document);

        $disk = $document->file_disk ?: config('filesystems.default');
        $filePath = $document->file_path;
        $downloadName = $document->file_name ?? ($document->title ? Str::slug($document->title) . '.pdf' : 'document.pdf');
        $isSignedDocument = (bool) $document->signature_completed || (bool) $document->signature_completed_at || filled($document->signed_file_path);

        if ($isSignedDocument) {
            $signedDocumentPath = $this->signedPdfService->ensure($document);

            if ($signedDocumentPath) {
                $filePath = $signedDocumentPath;
                $disk = $document->signed_file_disk ?: $disk;
                $downloadName = $this->resolveSignedDownloadName($document);
            }
        }

        if (! $filePath) {
            abort(404, 'File not found.');
        }

        if (! Storage::disk($disk)->exists($filePath)) {
            abort(404, 'File not found.');
        }

        $mimeType = $filePath === $document->signed_file_path
            ? 'application/pdf'
            : ($document->file_type
                ?: Storage::disk($disk)->mimeType($filePath)
                ?: 'application/pdf');

        $this->auditLogger->fromRequest(
            action: $download ? 'document_downloaded' : 'document_previewed',
            request: $request,
            document: $document,
            details: sprintf('%s document "%s".', $download ? 'Downloaded' : 'Previewed', $document->title)
        );

        if ($download) {
            return Storage::disk($disk)->download(
                $filePath,
                $downloadName,
                [
                    'Content-Type' => $mimeType,
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma' => 'no-cache',
                ]
            );
        }

        return Storage::disk($disk)->response(
            $filePath,
            $downloadName,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ]
        );
    }

    private function resolveSignedDownloadName(Document $document): string
    {
        $baseName = $document->file_name ?: ($document->title ?: 'document');
        $extension = pathinfo($baseName, PATHINFO_EXTENSION);

        if ($extension !== '' && strcasecmp($extension, 'pdf') === 0) {
            return $baseName;
        }

        $stem = pathinfo($baseName, PATHINFO_FILENAME);
        if ($stem === '') {
            $stem = Str::slug($document->title ?: 'document');
        }

        return $stem . '.pdf';
    }
}
