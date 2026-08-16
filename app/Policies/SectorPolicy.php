<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sector;
use App\Models\User;

final class SectorPolicy
{
    /*
     * Each method pairs the platform-admin bypass with the permission it is
     * named for, the way the rest of the app does. These returned the role alone,
     * so a permission could be granted, seeded and shown in the role editor and
     * still refuse the request — the grant looked applied and did nothing.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sectors.view');
    }

    public function view(User $user, Sector $sector): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sectors.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sectors.create');
    }

    public function update(User $user, Sector $sector): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sectors.update');
    }

    public function delete(User $user, Sector $sector): bool
    {
        return $user->hasRole('platform-admin') || $user->can('sectors.archive');
    }
}
