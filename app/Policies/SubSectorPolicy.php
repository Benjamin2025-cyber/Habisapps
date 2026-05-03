<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SubSector;
use App\Models\User;

final class SubSectorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function view(User $user, SubSector $subSector): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function update(User $user, SubSector $subSector): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function delete(User $user, SubSector $subSector): bool
    {
        return $user->hasRole('platform-admin');
    }
}
