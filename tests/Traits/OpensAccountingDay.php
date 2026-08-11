<?php

declare(strict_types=1);

namespace Tests\Traits;

use App\Models\AccountingDay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test helper for opening, closing, and reopening accounting days.
 *
 * Financial feature tests must open an accounting day before exercising
 * registration writes; this trait makes that one call instead of repeating
 * the lifecycle setup in every test.
 */
trait OpensAccountingDay
{
    protected function openAccountingDayForAgency(int $agencyId, ?string $businessDate = null): AccountingDay
    {
        // "The agency is trading on this date" now implies the institution is on
        // it too, so this helper puts it there. Callers asked for an agency day,
        // not for a lesson in the hierarchy.
        $this->ensureOpenInstitutionAccountingDay($businessDate ?? now()->toDateString());

        return AccountingDay::query()->create([
            'public_id' => (string) Str::ulid(),
            'scope_type' => AccountingDay::SCOPE_AGENCY,
            'agency_id' => $agencyId,
            'business_date' => $businessDate ?? now()->toDateString(),
            'calendar_opened_at' => now(),
            'status' => AccountingDay::STATUS_OPEN,
            'is_holiday' => false,
            'origin' => AccountingDay::ORIGIN_MANUAL,
            'write_lock_version' => 0,
        ]);
    }

    protected function openInstitutionAccountingDay(?string $businessDate = null): AccountingDay
    {
        return AccountingDay::query()->create([
            'public_id' => (string) Str::ulid(),
            'scope_type' => AccountingDay::SCOPE_INSTITUTION,
            'agency_id' => null,
            'business_date' => $businessDate ?? now()->toDateString(),
            'calendar_opened_at' => now(),
            'status' => AccountingDay::STATUS_OPEN,
            'is_holiday' => false,
            'origin' => AccountingDay::ORIGIN_MANUAL,
            'write_lock_version' => 0,
        ]);
    }

    /**
     * Ensure an open accounting day exists for the agency on the given date,
     * preserving the single-active-day invariant: any active day on a different
     * date is closed first. Returns the open day governing $businessDate.
     */
    protected function ensureOpenAccountingDay(int $agencyId, ?string $businessDate = null): AccountingDay
    {
        $businessDate ??= now()->toDateString();

        // An agency day only exists inside the institution's date, so the
        // institution has to be on this date first. Done here rather than in every
        // test: a database trigger enforces it, and the alternative is each test
        // opening two days by hand to say "the agency is trading".
        $this->ensureOpenInstitutionAccountingDay($businessDate);

        $activeQuery = AccountingDay::query()
            ->where('scope_type', AccountingDay::SCOPE_AGENCY)
            ->where('agency_id', $agencyId);
        $activeQuery->getQuery()->whereIn('status', [
            AccountingDay::STATUS_OPEN,
            AccountingDay::STATUS_REOPENED,
            AccountingDay::STATUS_CLOSING,
        ]);
        $active = $activeQuery->first();

        if ($active instanceof AccountingDay) {
            if ($active->business_date->toDateString() === $businessDate) {
                return $active;
            }

            $this->closeAccountingDay($active);
        }

        $existing = AccountingDay::query()
            ->where('scope_type', AccountingDay::SCOPE_AGENCY)
            ->where('agency_id', $agencyId)
            ->where('business_date', $businessDate)
            ->first();

        if ($existing instanceof AccountingDay) {
            $existing->forceFill([
                'status' => AccountingDay::STATUS_OPEN,
                'calendar_closed_at' => null,
            ])->save();

            return $existing->refresh();
        }

        return $this->openAccountingDayForAgency($agencyId, $businessDate);
    }

    /**
     * Ensure the institution is on $businessDate, rolling it there if it is on
     * another date. Agency days on the old date are closed first, because the
     * institution cannot leave a date its agencies are still trading in.
     */
    protected function ensureOpenInstitutionAccountingDay(?string $businessDate = null): AccountingDay
    {
        $businessDate ??= now()->toDateString();

        $activeQuery = AccountingDay::query()->where('scope_type', AccountingDay::SCOPE_INSTITUTION);
        $activeQuery->getQuery()->whereIn('status', [
            AccountingDay::STATUS_OPEN,
            AccountingDay::STATUS_REOPENED,
            AccountingDay::STATUS_CLOSING,
        ]);
        $active = $activeQuery->first();

        if ($active instanceof AccountingDay) {
            if ($active->business_date->toDateString() === $businessDate) {
                return $active;
            }

            $staleQuery = AccountingDay::query()
                ->where('scope_type', AccountingDay::SCOPE_AGENCY)
                ->where('business_date', $active->business_date->toDateString());
            $staleQuery->getQuery()->whereIn('status', [
                AccountingDay::STATUS_OPEN,
                AccountingDay::STATUS_REOPENED,
                AccountingDay::STATUS_CLOSING,
            ]);
            foreach ($staleQuery->get() as $agencyDay) {
                $this->closeAccountingDay($agencyDay);
            }

            $this->closeAccountingDay($active);
        }

        $existing = AccountingDay::query()
            ->where('scope_type', AccountingDay::SCOPE_INSTITUTION)
            ->where('business_date', $businessDate)
            ->first();

        if ($existing instanceof AccountingDay) {
            $existing->forceFill([
                'status' => AccountingDay::STATUS_OPEN,
                'calendar_closed_at' => null,
            ])->save();

            return $existing->refresh();
        }

        return $this->openInstitutionAccountingDay($businessDate);
    }

    /**
     * Resolve an agency's primary key from its public id (test convenience).
     */
    protected function agencyIdFromPublicId(string $agencyPublicId): int
    {
        $id = DB::table('agencies')->where('public_id', $agencyPublicId)->value('id');

        return is_numeric($id) ? (int) $id : 0;
    }

    protected function closeAccountingDay(AccountingDay $day): AccountingDay
    {
        $day->forceFill([
            'status' => AccountingDay::STATUS_CLOSED,
            'calendar_closed_at' => now(),
            'write_lock_version' => $day->write_lock_version + 1,
        ])->save();

        return $day->refresh();
    }

    protected function setAccountingDayClosing(AccountingDay $day): AccountingDay
    {
        $day->forceFill([
            'status' => AccountingDay::STATUS_CLOSING,
            'write_lock_version' => $day->write_lock_version + 1,
        ])->save();

        return $day->refresh();
    }
}
