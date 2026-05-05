<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.view') || $user->can('roles.manage');
    }

    public function updatePermissions(User $user, string $role): bool
    {
        return $user->can('roles.manage');
    }
}
