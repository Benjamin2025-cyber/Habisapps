<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmfRegulatoryAccount;
use App\Models\User;

final class EmfRegulatoryAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.accounts.view');
    }

    public function view(User $user, EmfRegulatoryAccount $account): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.accounts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.accounts.create');
    }

    public function update(User $user, EmfRegulatoryAccount $account): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.accounts.update');
    }

    public function delete(User $user, EmfRegulatoryAccount $account): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.accounts.archive');
    }
}
