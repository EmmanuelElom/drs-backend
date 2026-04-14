<?php

namespace App\Policies;

use App\Models\AppSetting;
use App\Models\User;

class AppSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, AppSetting $appSetting): bool
    {
        return $user->role === 'admin';
    }
}
