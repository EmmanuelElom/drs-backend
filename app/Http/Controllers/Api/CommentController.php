<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Document;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function index(Request $request, Document $document)
    {
        $this->authorize('view', $document);

        return response()->json([
            'data' => $document->comments()
                ->with(['user', 'invitation', 'parentComment'])
                ->orderByDesc('id')
                ->get()
                ->map(fn (Comment $comment) => $this->serializeComment($document, $comment))
                ->values(),
        ]);
    }

    public function store(Request $request, Document $document)
    {
        $this->authorize('comment', $document);

        $data = $request->validate([
            'selected_text' => ['nullable', 'string'],
            'comment' => ['required', 'string'],
            'parent_comment_id' => ['nullable', 'exists:comments,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'annotation_metadata' => ['nullable', 'array'],
            'invitation_id' => ['nullable', 'exists:document_invitations,id'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'author_email' => ['nullable', 'email', 'max:255'],
        ]);

        if (! empty($data['parent_comment_id'])) {
            $parentComment = Comment::query()->findOrFail($data['parent_comment_id']);
            abort_unless($parentComment->document_id === $document->id, 422, 'The parent comment must belong to the same document.');
        }

        $author = $request->user();

        $comment = Comment::query()->create([
            'document_id' => $document->id,
            'invitation_id' => $data['invitation_id'] ?? null,
            'user_id' => $author?->id,
            'author_name' => $data['author_name'] ?? $author?->username ?? 'guest',
            'author_email' => $data['author_email'] ?? $author?->email,
            'selected_text' => $data['selected_text'] ?? null,
            'comment' => $data['comment'],
            'parent_comment_id' => $data['parent_comment_id'] ?? null,
            'page' => $data['page'] ?? null,
            'annotation_metadata' => $data['annotation_metadata'] ?? null,
        ]);

        $comment->load('user');

        $this->auditLogger->fromRequest(
            action: 'comment_added',
            request: $request,
            targetUser: $comment->user,
            document: $document,
            details: sprintf('Added comment to "%s" by %s.', $document->title, $comment->author_name)
        );

        return response()->json([
            'message' => 'Comment added successfully.',
            'data' => $this->serializeComment($document, $comment),
        ], 201);
    }

    public function update(Request $request, Comment $comment)
    {
        $document = $comment->document()->with(['owner', 'createdBy', 'user', 'assignedBy'])->firstOrFail();
        $this->authorize('updateComment', [$document, $comment]);

        $data = $request->validate([
            'selected_text' => ['nullable', 'string'],
            'comment' => ['required', 'string'],
            'parent_comment_id' => ['nullable', 'exists:comments,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'annotation_metadata' => ['nullable', 'array'],
        ]);

        if (! empty($data['parent_comment_id'])) {
            $parentComment = Comment::query()->findOrFail($data['parent_comment_id']);
            abort_unless($parentComment->document_id === $document->id, 422, 'The parent comment must belong to the same document.');
        }

        $comment->forceFill([
            'selected_text' => $data['selected_text'] ?? $comment->selected_text,
            'comment' => $data['comment'],
            'parent_comment_id' => $data['parent_comment_id'] ?? $comment->parent_comment_id,
            'page' => $data['page'] ?? $comment->page,
            'annotation_metadata' => $data['annotation_metadata'] ?? $comment->annotation_metadata,
        ])->save();

        $comment->load('user');

        $this->auditLogger->fromRequest(
            action: 'comment_updated',
            request: $request,
            targetUser: $comment->user,
            document: $document,
            details: sprintf('Updated comment on "%s".', $document->title)
        );

        return response()->json([
            'message' => 'Comment updated successfully.',
            'data' => $this->serializeComment($document, $comment),
        ]);
    }

    public function destroy(Request $request, Document $document, Comment $comment)
    {
        $this->authorize('view', $document);

        abort_unless($comment->document_id === $document->id, 404, 'Comment not found.');

        $this->authorize('deleteComment', [$document, $comment]);

        $commentUser = $comment->user;
        $commentAuthor = $comment->author_name ?: $comment->user?->username;
        $commentText = $comment->comment;
        $comment->delete();

        $this->auditLogger->fromRequest(
            action: 'comment_deleted',
            request: $request,
            targetUser: $commentUser,
            document: $document,
            details: sprintf('Deleted comment by %s: %s', $commentAuthor, $commentText)
        );

        return response()->json([
            'message' => 'Comment deleted successfully.',
        ]);
    }

    public function destroyByComment(Request $request, Comment $comment)
    {
        $document = $comment->document()->with(['owner', 'createdBy', 'user', 'assignedBy'])->firstOrFail();

        return $this->destroy($request, $document, $comment);
    }

    private function serializeComment(Document $document, Comment $comment): array
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
}
