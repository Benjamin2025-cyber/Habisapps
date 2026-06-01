<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountingDay;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class AccountingDayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.days.view');
    }

    public function view(User $user, AccountingDay $accountingDay): bool
    {
        return $user->can('accounting.days.view') && $this->canAccessScope($user, $accountingDay);
    }

    public function open(User $user): bool
    {
        return $user->can('accounting.days.open');
    }

    public function startClose(User $user, AccountingDay $accountingDay): bool
    {
        return $user->can('accounting.days.close') && $this->canAccessScope($user, $accountingDay);
    }

    public function close(User $user, AccountingDay $accountingDay): bool
    {
        return $user->can('accounting.days.close') && $this->canAccessScope($user, $accountingDay);
    }

    public function reopen(User $user, AccountingDay $accountingDay): bool
    {
        return $user->can('accounting.days.reopen') && $this->canAccessScope($user, $accountingDay);
    }

    private function canAccessScope(User $user, AccountingDay $accountingDay): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        if ($accountingDay->scope_type === AccountingDay::SCOPE_INSTITUTION) {
            // Institution-scoped lifecycle is reserved for platform administrators.
            return false;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $accountingDay->agency_id;
    }
}
