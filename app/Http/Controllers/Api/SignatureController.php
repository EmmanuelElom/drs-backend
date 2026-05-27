<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentField;
use App\Models\Signature;
use App\Services\AuditLogger;
use App\Services\DocumentCompletionNotifier;
use App\Services\SignedPdfService;
use Illuminate\Http\Request;

class SignatureController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentCompletionNotifier $completionNotifier,
        private readonly SignedPdfService $signedPdfService
    )
    {
    }

    public function index(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'data' => $document->signatures()
                ->with(['user', 'invitation', 'documentField'])
                ->orderByDesc('id')
                ->get()
                ->map(fn (Signature $signature) => $this->serializeSignature($document, $signature))
                ->values(),
        ]);
    }

    public function store(Request $request, Document $document)
    {
        $this->authorize('sign', $document);

        $data = $request->validate([
            'signature_data' => ['required', 'string'],
            'ip_address' => ['nullable', 'string', 'max:45'],
            'invitation_id' => ['nullable', 'exists:document_invitations,id'],
            'document_field_id' => ['nullable', 'exists:document_fields,id'],
            'signer_name' => ['nullable', 'string', 'max:255'],
            'signer_email' => ['nullable', 'email', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $eligibleFields = $this->signedPdfService->resolveSignatureFieldsForSigner($document, $request->user()?->email, null);
        abort_if($eligibleFields->isEmpty(), 422, 'No signature fields are assigned to this signer.');

        if (! empty($data['document_field_id'])) {
            $field = DocumentField::query()->findOrFail($data['document_field_id']);
            abort_unless($field->document_id === $document->id, 422, 'The signature field must belong to the same document.');
            abort_unless(
                $eligibleFields->contains(fn (DocumentField $eligibleField) => (string) $eligibleField->id === (string) $field->id),
                422,
                'The signature field is not assigned to this signer.'
            );
        }

        $signatures = $eligibleFields->map(function (DocumentField $field) use ($request, $document, $data) {
            return Signature::query()->create([
                'document_id' => $document->id,
                'invitation_id' => $data['invitation_id'] ?? null,
                'document_field_id' => $field->id,
                'user_id' => $request->user()?->id,
                'signer_name' => $data['signer_name'] ?? $request->user()?->username,
                'signer_email' => $data['signer_email'] ?? $request->user()?->email,
                'signature_data' => $data['signature_data'],
                'signed_at' => now(),
                'ip_address' => $data['ip_address'] ?? $request->ip(),
                'metadata' => $data['metadata'] ?? null,
            ]);
        })->values();

        $this->signedPdfService->refresh($document);
        $this->signedPdfService->finalizeDocumentIfComplete($document);

        $signature = $signatures->first();
        $signature?->load('user');

        $this->auditLogger->fromRequest(
            action: 'signature_added',
            request: $request,
            targetUser: $signature->user,
            document: $document,
            details: sprintf('Signed "%s" by %s (%s fields).', $document->title, $signature->signer_name, $signatures->count())
        );

        $this->auditLogger->fromRequest(
            action: 'signing_completed',
            request: $request,
            targetUser: $signature->user,
            document: $document,
            details: sprintf('Signing completed for %s.', $document->title)
        );

        $this->completionNotifier->notify(
            request: $request,
            document: $document,
            completionType: 'signing_completed',
            actorName: $signature->signer_name ?? $request->user()?->username ?? 'signer'
        );

        return response()->json([
            'message' => 'Signature saved successfully.',
            'data' => $signatures->map(fn (Signature $createdSignature) => $this->serializeSignature($document, $createdSignature))->values(),
        ], 201);
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
