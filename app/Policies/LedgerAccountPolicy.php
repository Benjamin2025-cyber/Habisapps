<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LedgerAccount;
use App\Models\User;

final class LedgerAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('ledger.accounts.view');
    }

    public function view(User $user, LedgerAccount $ledgerAccount): bool
    {
        return $user->hasRole('platform-admin') || $user->can('ledger.accounts.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('ledger.accounts.create');
    }

    public function update(User $user, LedgerAccount $ledgerAccount): bool
    {
        return $user->hasRole('platform-admin') || $user->can('ledger.accounts.update');
    }

    public function delete(User $user, LedgerAccount $ledgerAccount): bool
    {
        return $user->hasRole('platform-admin') || $user->can('ledger.accounts.archive');
    }
}
