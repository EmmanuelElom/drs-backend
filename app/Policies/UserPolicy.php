<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function view(User $user, User $targetUser): bool
    {
        return $user->role === 'admin' || $user->id === $targetUser->id;
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, User $targetUser): bool
    {
        return $user->role === 'admin' || $user->id === $targetUser->id;
    }

    public function delete(User $user, User $targetUser): bool
    {
        return $user->role === 'admin' && $user->id !== $targetUser->id;
    }
}
