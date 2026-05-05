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
        return $this->isAdmin($user) || $this->isAssignedReviewer($user, $document);
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->isAdmin($user);
    }

    public function reassign(User $user, Document $document): bool
    {
        return $this->isAdmin($user);
    }

    public function updateDays(User $user, Document $document): bool
    {
        return $this->isAdmin($user);
    }

    public function inviteSignature(User $user, Document $document): bool
    {
        return $this->isAdmin($user);
    }

    public function acknowledge(User $user, Document $document): bool
    {
        return $this->isAssignedReviewer($user, $document);
    }

    public function comment(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    public function deleteComment(User $user, Document $document, Comment $comment): bool
    {
        return $this->view($user, $document) && ($this->isAdmin($user) || $this->isOwnedComment($user, $comment));
    }

    public function sign(User $user, Document $document): bool
    {
        return $this->view($user, $document) && (bool) $document->signature_invited;
    }

    public function updateStatus(User $user, Document $document): bool
    {
        return $this->view($user, $document);
    }

    private function isAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }

    private function isAssignedReviewer(User $user, Document $document): bool
    {
        return (string) $document->user_id === (string) $user->id;
    }

    private function isOwnedComment(User $user, Comment $comment): bool
    {
        return (string) $comment->user_id === (string) $user->id;
    }
}
