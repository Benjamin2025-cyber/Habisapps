<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountHold;
use App\Models\User;

final class AccountHoldPolicy
{
    /*
     * Each method pairs the platform-admin bypass with the permission it is
     * named for, the way the rest of the app does. These returned the role alone,
     * so a permission could be granted, seeded and shown in the role editor and
     * still refuse the request — the grant looked applied and did nothing.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('account.holds.view');
    }

    public function view(User $user, AccountHold $accountHold): bool
    {
        return $user->hasRole('platform-admin') || $user->can('account.holds.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('account.holds.create');
    }

    public function update(User $user, AccountHold $accountHold): bool
    {
        return $user->hasRole('platform-admin') || $user->can('account.holds.update');
    }

    public function delete(User $user, AccountHold $accountHold): bool
    {
        return $user->hasRole('platform-admin') || $user->can('account.holds.archive');
    }

    public function release(User $user, AccountHold $accountHold): bool
    {
        return $user->hasRole('platform-admin') || $user->can('account.holds.release');
    }
}
