<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountingDay;
use App\Models\User;
use App\Support\AccountingDay\AccountingScopeAccess;
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

    public function cancelClose(User $user, AccountingDay $accountingDay): bool
    {
        return $user->can('accounting.days.close') && $this->canAccessScope($user, $accountingDay);
    }

    public function reopen(User $user, AccountingDay $accountingDay): bool
    {
        return $user->can('accounting.days.reopen') && $this->canAccessScope($user, $accountingDay);
    }

    private function canAccessScope(User $user, AccountingDay $accountingDay): bool
    {
        if ($accountingDay->scope_type === AccountingDay::SCOPE_INSTITUTION) {
            // The institution's own accounting period belongs to head-office
            // accounting, so it is gated on the institution-scope permission
            // rather than on being a platform administrator.
            return app(AccountingScopeAccess::class)->canManageInstitutionScope($user);
        }

        if ($user->hasRole('platform-admin')) {
            return true;
        }

        // Head office reaches an agency's day as well as its own period. The
        // accounting team was explicit that some operations are keyed remotely from
        // the siège, directly into the agencies, and journal entries already work
        // that way — the same institution-scope permission grants cross-agency
        // posting. Withholding the day while granting the entry left the chef
        // comptable able to record into an agency's books but unable to see the day
        // governing them, and unable to close the agencies that the institution's
        // own close now waits on.
        if (app(AccountingScopeAccess::class)->canManageInstitutionScope($user)) {
            return true;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $accountingDay->agency_id;
    }
}
