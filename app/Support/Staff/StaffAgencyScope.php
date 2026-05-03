<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class StaffAgencyScope
{
    public function currentAgencyId(User $user): ?int
    {
        $today = now()->toDateString();
        $assignment = DB::table('staff_agency_assignments')
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
            ->where('starts_on', '<=', $today)
            ->whereRaw('(ends_on IS NULL OR ends_on >= ?)', [$today])
            ->latest('starts_on')
            ->first(['agency_id']);

        if (is_object($assignment) && property_exists($assignment, 'agency_id') && is_numeric($assignment->agency_id)) {
            return (int) $assignment->agency_id;
        }

        return null;
    }

    public function currentAgencyStaffIds(int $agencyId): Builder
    {
        $today = now()->toDateString();

        return DB::table('staff_agency_assignments')
            ->select('user_id')
            ->where('staff_agency_assignments.agency_id', $agencyId)
            ->where('staff_agency_assignments.is_primary', true)
            ->where('staff_agency_assignments.status', StaffAgencyAssignment::STATUS_ACTIVE)
            ->where('staff_agency_assignments.starts_on', '<=', $today)
            ->whereRaw('(staff_agency_assignments.ends_on IS NULL OR staff_agency_assignments.ends_on >= ?)', [$today]);
    }

    /**
     * @return array<int, int>
     */
    public function currentAgencyStaffIdList(int $agencyId): array
    {
        return $this->currentAgencyStaffIds($agencyId)
            ->pluck('user_id')
            ->filter(static fn (mixed $userId): bool => is_numeric($userId))
            ->map(static fn (mixed $userId): int => (int) $userId)
            ->values()
            ->all();
    }
}
