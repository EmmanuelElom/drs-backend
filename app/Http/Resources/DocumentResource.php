<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Document */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAdmin = $user?->role === 'admin';
        $isOwner = $user && ((string) $this->owner_id === (string) $user->id || (string) $this->created_by_id === (string) $user->id);
        $isAssigned = $user && (string) $this->user_id === (string) $user->id;

        return [
            'id' => (string) $this->id,
            'documentId' => $this->document_uuid,
            'ownerId' => $this->owner_id ? (string) $this->owner_id : null,
            'createdById' => $this->created_by_id ? (string) $this->created_by_id : null,
            'userId' => $this->user_id ? (string) $this->user_id : null,
            'title' => $this->title,
            'content' => $this->content,
            'fileName' => $this->file_name,
            'fileType' => $this->file_type,
            'fileSize' => $this->file_size,
            'fileData' => $this->file_data,
            'storageMode' => $this->storage_mode,
            'daysAllowed' => $this->days_allowed,
            'assignedAt' => optional($this->assigned_at)->toISOString(),
            'sentAt' => optional($this->sent_at)->toISOString(),
            'expiresAt' => optional($this->expires_at)->toISOString(),
            'status' => $this->status,
            'reviewAcknowledged' => (bool) $this->review_acknowledged,
            'acknowledgedAt' => optional($this->acknowledged_at)->toISOString(),
            'signatureInvited' => (bool) $this->signature_invited,
            'signatureInvitedAt' => optional($this->signature_invited_at)->toISOString(),
            'signatureCompleted' => (bool) $this->signature_completed,
            'signatureCompletedAt' => optional($this->signature_completed_at)->toISOString(),
            'completedAt' => optional($this->completed_at)->toISOString(),
            'archivedAt' => optional($this->archived_at)->toISOString(),
            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
            'owner' => new UserResource($this->whenLoaded('owner')),
            'createdBy' => new UserResource($this->whenLoaded('createdBy')),
            'assignedBy' => new UserResource($this->whenLoaded('assignedBy')),
            'signatureFields' => SignatureFieldResource::collection($this->whenLoaded('fields')),
            'comments' => CommentResource::collection($this->whenLoaded('comments')),
            'signatures' => SignatureResource::collection($this->whenLoaded('signatures')),
            'invitations' => InvitationResource::collection($this->whenLoaded('invitations')),
            'permissions' => [
                'canEdit' => $isAdmin || $isOwner,
                'canReview' => $isAdmin || $isOwner || $isAssigned,
                'canComment' => $isAdmin || $isOwner || $isAssigned,
                'canSign' => $isAdmin || $isOwner || $isAssigned || (bool) $this->signature_invited,
                'canDelete' => $isAdmin || $isOwner,
                'canDownload' => $isAdmin || $isOwner || $isAssigned,
            ],
        ];
    }
}
