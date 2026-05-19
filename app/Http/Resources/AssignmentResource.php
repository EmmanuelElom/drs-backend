<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\DocumentAssignment */
class AssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'documentId' => $this->document?->document_uuid,
            'userId' => $this->user_id ? (string) $this->user_id : null,
            'username' => $this->user?->username,
            'assignedBy' => $this->assignedByUser?->username,
            'assignedAt' => optional($this->assigned_at)->toISOString(),
            'expiresAt' => optional($this->expires_at)->toISOString(),
            'daysAllowed' => $this->days_allowed,
            'title' => $this->document?->title,
            'content' => $this->document?->content,
            'fileType' => $this->document?->file_type,
            'fileName' => $this->document?->file_name,
            'fileSize' => $this->document?->file_size,
            'reviewAcknowledged' => (bool) $this->review_acknowledged,
            'acknowledgedAt' => optional($this->acknowledged_at)->toISOString(),
            'signatureInvited' => (bool) $this->signature_invited,
            'signatureInvitedAt' => optional($this->signature_invited_at)->toISOString(),
            'signatureCompleted' => (bool) $this->signature_completed,
            'signatureCompletedAt' => optional($this->signature_completed_at)->toISOString(),
            'status' => $this->status,
            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
        ];
    }
}
