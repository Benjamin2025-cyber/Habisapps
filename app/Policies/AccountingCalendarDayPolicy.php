<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountingCalendarDay;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class AccountingCalendarDayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounting.calendar.view');
    }

    public function view(User $user, AccountingCalendarDay $calendarDay): bool
    {
        return $user->can('accounting.calendar.view') && $this->canAccessScope($user, $calendarDay);
    }

    public function create(User $user): bool
    {
        return $user->can('accounting.calendar.manage');
    }

    public function update(User $user, AccountingCalendarDay $calendarDay): bool
    {
        return $user->can('accounting.calendar.manage') && $this->canAccessScope($user, $calendarDay);
    }

    private function canAccessScope(User $user, AccountingCalendarDay $calendarDay): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        if ($calendarDay->scope_type === AccountingCalendarDay::SCOPE_INSTITUTION) {
            return false;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $calendarDay->agency_id;
    }
}
