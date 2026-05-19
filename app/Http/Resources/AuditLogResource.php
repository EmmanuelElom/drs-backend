<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'timestamp' => optional($this->timestamp)->toISOString(),
            'eventType' => $this->event_type,
            'action' => $this->action,
            'performedBy' => $this->performed_by,
            'performedById' => $this->performed_by_id ? (string) $this->performed_by_id : null,
            'actorName' => $this->actor_name,
            'actorId' => $this->actor_id ? (string) $this->actor_id : null,
            'targetUser' => $this->target_user,
            'targetUserId' => $this->target_user_id ? (string) $this->target_user_id : null,
            'documentTitle' => $this->document_title,
            'documentId' => $this->document_id ? (string) $this->document_id : null,
            'invitationId' => $this->invitation_id ? (string) $this->invitation_id : null,
            'details' => $this->details,
            'metadata' => $this->metadata,
            'ipAddress' => $this->ip_address,
        ];
    }
}

