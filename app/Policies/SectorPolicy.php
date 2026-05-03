<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Sector;
use App\Models\User;

final class SectorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function view(User $user, Sector $sector): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function update(User $user, Sector $sector): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function delete(User $user, Sector $sector): bool
    {
        return $user->hasRole('platform-admin');
    }
}
