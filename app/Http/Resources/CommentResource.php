<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Comment */
class CommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'documentId' => $this->document?->document_uuid,
            'invitationId' => $this->invitation_id ? (string) $this->invitation_id : null,
            'userId' => $this->user_id ? (string) $this->user_id : null,
            'username' => $this->author_name ?: $this->user?->username,
            'authorName' => $this->author_name ?: $this->user?->username,
            'authorEmail' => $this->author_email,
            'selectedText' => $this->selected_text,
            'comment' => $this->comment,
            'parentCommentId' => $this->parent_comment_id ? (string) $this->parent_comment_id : null,
            'page' => $this->page,
            'annotationMetadata' => $this->annotation_metadata,
            'resolvedAt' => optional($this->resolved_at)->toISOString(),
            'createdAt' => optional($this->created_at)->toISOString(),
            'timestamp' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
        ];
    }
}
