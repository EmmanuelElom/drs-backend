<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DocumentField */
class SignatureFieldResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'documentId' => $this->document?->document_uuid,
            'invitationId' => $this->invitation_id ? (string) $this->invitation_id : null,
            'assignedRecipientEmail' => $this->assigned_recipient_email,
            'fieldType' => $this->field_type,
            'page' => $this->page,
            'x' => (string) $this->x,
            'y' => (string) $this->y,
            'width' => (string) $this->width,
            'height' => (string) $this->height,
            'required' => (bool) $this->required,
            'metadata' => $this->metadata,
            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
        ];
    }
}
