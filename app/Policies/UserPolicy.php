<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('users.view');
    }

    public function view(User $user, User $staffUser): bool
    {
        return $user->can('users.view') && $this->canAccessStaffUser($user, $staffUser);
    }

    public function create(User $user): bool
    {
        return $user->can('users.create');
    }

    public function update(User $user, User $staffUser): bool
    {
        return $user->can('users.update') && $this->canAccessStaffUser($user, $staffUser);
    }

    public function updateStatus(User $user, User $staffUser): bool
    {
        return $user->can('users.status.manage') && $this->canAccessStaffUser($user, $staffUser);
    }

    public function updateRoles(User $user, User $staffUser): bool
    {
        return $user->can('users.roles.manage') && $this->canAccessStaffUser($user, $staffUser);
    }

    private function canAccessStaffUser(User $actor, User $target): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        $actorAgencyId = app(StaffAgencyScope::class)->currentAgencyId($actor);

        return $actorAgencyId !== null && app(StaffAgencyScope::class)->currentAgencyId($target) === $actorAgencyId;
    }
}
