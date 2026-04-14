<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return $user->role === 'admin' || $document->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->role === 'admin';
    }

    public function reassign(User $user, Document $document): bool
    {
        return $user->role === 'admin';
    }

    public function updateDays(User $user, Document $document): bool
    {
        return $user->role === 'admin';
    }

    public function inviteSignature(User $user, Document $document): bool
    {
        return $user->role === 'admin';
    }

    public function acknowledge(User $user, Document $document): bool
    {
        return $document->user_id === $user->id;
    }

    public function comment(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function deleteComment(User $user, Document $document, Comment $comment): bool
    {
        return $this->view($user, $document) && ($user->role === 'admin' || $comment->user_id === $user->id);
    }

    public function sign(User $user, Document $document): bool
    {
        return ($user->role === 'admin' || $document->user_id === $user->id) && (bool) $document->signature_invited;
    }

    public function updateStatus(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }
}
