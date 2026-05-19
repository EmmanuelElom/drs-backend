<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Signature */
class SignatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'documentId' => $this->document?->document_uuid,
            'invitationId' => $this->invitation_id ? (string) $this->invitation_id : null,
            'documentFieldId' => $this->document_field_id ? (string) $this->document_field_id : null,
            'userId' => $this->user_id ? (string) $this->user_id : null,
            'username' => $this->signer_name ?: $this->user?->username,
            'signerName' => $this->signer_name ?: $this->user?->username,
            'signerEmail' => $this->signer_email,
            'signatureData' => $this->signature_data,
            'signedAt' => optional($this->signed_at)->toISOString(),
            'ipAddress' => $this->ip_address,
            'metadata' => $this->metadata,
            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
        ];
    }
}
