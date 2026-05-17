<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loans.view');
    }

    public function view(User $user, Loan $loan): bool
    {
        return ($user->hasRole('platform-admin') || $user->hasPermissionTo('loans.view'))
            && $this->canReadInScope($user, $loan);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loans.create');
    }

    public function update(User $user, Loan $loan): bool
    {
        return ($user->hasRole('platform-admin') || $user->hasPermissionTo('loans.update'))
            && $this->canReadInScope($user, $loan);
    }

    private function canReadInScope(User $user, Loan $loan): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        if ($user->can('crm.scope.institution.read')
            || $user->can('crm.scope.institution.manage')
            || $user->can('loans.scope.institution.read')) {
            return true;
        }

        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);
        if ($currentAgencyId !== null && $currentAgencyId === $loan->agency_id) {
            return true;
        }

        return $loan->credit_agent_id !== null && $loan->credit_agent_id === $user->id;
    }
}
