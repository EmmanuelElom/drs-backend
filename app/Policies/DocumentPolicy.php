<?php

namespace App\Policies;

use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentInvitation;
use App\Models\User;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Document $document): bool
    {
        return $this->isAdmin($user)
            || $this->isOwner($user, $document)
            || $this->isAssignedReviewer($user, $document)
            || $this->hasInvitationAccess($user, $document, allowCompleted: true);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function delete(User $user, Document $document): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $document);
    }

    public function reassign(User $user, Document $document): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $document);
    }

    public function updateDays(User $user, Document $document): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $document);
    }

    public function inviteSignature(User $user, Document $document): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $document);
    }

    public function update(User $user, Document $document): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $document);
    }

    public function archive(User $user, Document $document): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $document);
    }

    public function acknowledge(User $user, Document $document): bool
    {
        return $this->isAssignedReviewer($user, $document)
            || $this->hasInvitationAccess($user, $document, permission: 'review', allowCompleted: false);
    }

    public function comment(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && (
                $this->isAdmin($user)
                || $this->isOwner($user, $document)
                || $this->isAssignedReviewer($user, $document)
                || $this->hasInvitationAccess($user, $document, permission: 'comment', allowCompleted: false)
                || $this->hasInvitationAccess($user, $document, permission: 'review', allowCompleted: false)
            );
    }

    public function deleteComment(User $user, Document $document, Comment $comment): bool
    {
        return $this->view($user, $document)
            && (
                $this->isAdmin($user)
                || $this->isOwner($user, $document)
                || $this->isOwnedComment($user, $comment)
            );
    }

    public function updateComment(User $user, Document $document, Comment $comment): bool
    {
        return $this->deleteComment($user, $document, $comment);
    }

    public function sign(User $user, Document $document): bool
    {
        return $this->view($user, $document)
            && (
                (bool) $document->signature_invited
                || $this->hasInvitationAccess($user, $document, permission: 'sign', allowCompleted: false)
            );
    }

    public function updateStatus(User $user, Document $document): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $document);
    }

    private function isAdmin(User $user): bool
    {
        return $user->role === 'admin';
    }

    private function isAssignedReviewer(User $user, Document $document): bool
    {
        return (string) $document->user_id === (string) $user->id;
    }

    private function hasInvitationAccess(
        User $user,
        Document $document,
        ?string $permission = null,
        bool $allowCompleted = true
    ): bool {
        if (! filled($user->email)) {
            return false;
        }

        $query = $document->invitations()
            ->where('recipient_email', $user->email)
            ->whereNotIn('status', ['revoked', 'expired']);

        if (! $allowCompleted) {
            $query->where('status', '!=', 'completed');
        }

        if ($permission === 'review') {
            $query->where('can_review', true);
        }

        if ($permission === 'comment') {
            $query->where('can_comment', true);
        }

        if ($permission === 'sign') {
            $query->where('can_sign', true);
        }

        return $query->exists();
    }

    private function isOwner(User $user, Document $document): bool
    {
        return (string) $document->owner_id === (string) $user->id
            || (string) $document->created_by_id === (string) $user->id;
    }

    private function isOwnedComment(User $user, Comment $comment): bool
    {
        return (string) $comment->user_id === (string) $user->id;
    }
}
