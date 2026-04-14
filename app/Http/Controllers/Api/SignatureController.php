<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Signature;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SignatureController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'data' => $document->signatures()
                ->with('user')
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
        ]);

        $signature = Signature::query()->create([
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'signature_data' => $data['signature_data'],
            'signed_at' => now(),
            'ip_address' => $data['ip_address'] ?? $request->ip(),
        ]);

        $document->forceFill([
            'status' => 'signed',
        ])->save();

        $signature->load('user');

        $this->auditLogger->fromRequest(
            action: 'signature_added',
            request: $request,
            targetUser: $signature->user,
            document: $document,
            details: sprintf('Signed "%s".', $document->title)
        );

        return response()->json([
            'message' => 'Signature saved successfully.',
            'data' => $this->serializeSignature($document, $signature),
        ], 201);
    }

    private function serializeSignature(Document $document, Signature $signature): array
    {
        return [
            'id' => (string) $signature->id,
            'documentId' => $document->document_uuid,
            'userId' => (string) $signature->user_id,
            'username' => $signature->user?->username,
            'signatureData' => $signature->signature_data,
            'signedAt' => optional($signature->signed_at)->toISOString(),
            'ipAddress' => $signature->ip_address,
        ];
    }
}
