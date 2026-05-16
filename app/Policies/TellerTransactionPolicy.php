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
        return $user->can('cash.transactions.manage') && $this->canAccessAgency($user, $tellerTransaction->agency_id);
    }

    private function canAccessAgency(User $user, int $agencyId): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $agencyId;
    }
}
