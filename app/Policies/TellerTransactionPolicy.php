<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TellerTransaction;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class TellerTransactionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cash.transactions.view');
    }

    public function view(User $user, TellerTransaction $tellerTransaction): bool
    {
        return $user->can('cash.transactions.view') && $this->canAccessAgency($user, $tellerTransaction->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->can('cash.transactions.manage');
    }

    public function reverse(User $user, TellerTransaction $tellerTransaction): bool
    {
        if (! $this->canAccessAgency($user, $tellerTransaction->agency_id)) {
            return false;
        }

        if ($user->hasRole('platform-admin')) {
            return true;
        }

        if (! $user->can('cash.transactions.reverse')) {
            return false;
        }

        if ($this->isSelfReversal($user, $tellerTransaction) && ! $user->can('cash.transactions.reverse.self_override')) {
            return false;
        }

        return true;
    }

    private function isSelfReversal(User $user, TellerTransaction $tellerTransaction): bool
    {
        $tellerTransaction->loadMissing('tellerSession');
        $tellerSession = $tellerTransaction->tellerSession;

        return $tellerSession !== null && $tellerSession->teller_user_id === $user->id;
    }

    private function canAccessAgency(User $user, int $agencyId): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $agencyId;
    }
}
