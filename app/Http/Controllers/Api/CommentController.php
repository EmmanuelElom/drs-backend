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
                ->with('user')
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
            'selected_text' => ['required', 'string'],
            'comment' => ['required', 'string'],
        ]);

        $comment = Comment::query()->create([
            'document_id' => $document->id,
            'user_id' => $request->user()->id,
            'selected_text' => $data['selected_text'],
            'comment' => $data['comment'],
        ]);

        $comment->load('user');

        $this->auditLogger->fromRequest(
            action: 'comment_added',
            request: $request,
            targetUser: $comment->user,
            document: $document,
            details: sprintf('Added comment to "%s".', $document->title)
        );

        return response()->json([
            'message' => 'Comment added successfully.',
            'data' => $this->serializeComment($document, $comment),
        ], 201);
    }

    public function destroy(Request $request, Document $document, Comment $comment)
    {
        $this->authorize('view', $document);

        abort_unless($comment->document_id === $document->id, 404, 'Comment not found.');

        $this->authorize('deleteComment', [$document, $comment]);

        $commentUser = $comment->user;
        $commentText = $comment->comment;
        $comment->delete();

        $this->auditLogger->fromRequest(
            action: 'comment_deleted',
            request: $request,
            targetUser: $commentUser,
            document: $document,
            details: sprintf('Deleted comment: %s', $commentText)
        );

        return response()->json([
            'message' => 'Comment deleted successfully.',
        ]);
    }

    private function serializeComment(Document $document, Comment $comment): array
    {
        return [
            'id' => (string) $comment->id,
            'documentId' => $document->document_uuid,
            'userId' => (string) $comment->user_id,
            'username' => $comment->user?->username,
            'selectedText' => $comment->selected_text,
            'comment' => $comment->comment,
            'timestamp' => optional($comment->created_at)->toISOString(),
        ];
    }
}
