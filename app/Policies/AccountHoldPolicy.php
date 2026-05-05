<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountHold;
use App\Models\User;

final class AccountHoldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function view(User $user, AccountHold $accountHold): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function update(User $user, AccountHold $accountHold): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function delete(User $user, AccountHold $accountHold): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function release(User $user, AccountHold $accountHold): bool
    {
        return $user->hasRole('platform-admin');
    }
}
