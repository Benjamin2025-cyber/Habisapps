<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class LedgerAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('ledger.accounts.view');
    }

    public function view(User $user, LedgerAccount $ledgerAccount): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        if (! $user->can('ledger.accounts.view')) {
            return false;
        }

        // Institution grouping accounts are the shared chart every agency files
        // its detail accounts under, so they stay readable from any agency —
        // which is what index() already returns. Reading the consolidated
        // figures behind one is gated separately, on the balance and statement
        // endpoints, since those aggregate other agencies' movements.
        if ($ledgerAccount->isInstitutionLevel()) {
            return true;
        }

        return $user->can('ledger.scope.institution.read')
            || $this->isCurrentAgency($user, $ledgerAccount->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('ledger.accounts.create');
    }

    public function update(User $user, LedgerAccount $ledgerAccount): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('ledger.accounts.update') && $this->canWrite($user, $ledgerAccount));
    }

    public function delete(User $user, LedgerAccount $ledgerAccount): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('ledger.accounts.archive') && $this->canWrite($user, $ledgerAccount));
    }

    /**
     * A write never crosses an agency boundary.
     *
     * Institution grouping accounts govern every agency chart beneath them, so
     * editing one is an institution-control action rather than something an
     * agency assignment confers.
     */
    private function canWrite(User $user, LedgerAccount $ledgerAccount): bool
    {
        if ($ledgerAccount->isInstitutionLevel()) {
            return $user->can('ledger.scope.institution.manage');
        }

        return $this->isCurrentAgency($user, $ledgerAccount->agency_id);
    }

    private function isCurrentAgency(User $user, ?int $agencyId): bool
    {
        if ($agencyId === null) {
            return false;
        }

        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $currentAgencyId !== null && $currentAgencyId === $agencyId;
    }
}
