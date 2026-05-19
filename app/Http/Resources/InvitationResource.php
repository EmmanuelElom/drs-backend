<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DocumentInvitation */
class InvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'documentId' => $this->document?->document_uuid,
            'recipientName' => $this->recipient_name,
            'recipientEmail' => $this->recipient_email,
            'invitationType' => $this->invitation_type,
            'status' => $this->status,
            'recipientOrder' => $this->recipient_order,
            'canReview' => (bool) $this->can_review,
            'canComment' => (bool) $this->can_comment,
            'canSign' => (bool) $this->can_sign,
            'viewedAt' => optional($this->viewed_at)->toISOString(),
            'completedAt' => optional($this->completed_at)->toISOString(),
            'revokedAt' => optional($this->revoked_at)->toISOString(),
            'expiresAt' => optional($this->expires_at)->toISOString(),
            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
        ];
    }
}
