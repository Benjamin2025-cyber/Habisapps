<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SubSector;
use App\Models\User;

final class SubSectorPolicy
{
    /*
     * Each method pairs the platform-admin bypass with the permission it is
     * named for, the way the rest of the app does. These returned the role alone,
     * so a permission could be granted, seeded and shown in the role editor and
     * still refuse the request — the grant looked applied and did nothing.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sub-sectors.view');
    }

    public function view(User $user, SubSector $subSector): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sub-sectors.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sub-sectors.create');
    }

    public function update(User $user, SubSector $subSector): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sub-sectors.update');
    }

    public function delete(User $user, SubSector $subSector): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sub-sectors.archive');
    }
}
