<?php

declare(strict_types=1);

namespace App\Application\Staff;

use App\Models\Agency;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ManageStaffAssignment
{
    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $actor, User $staffUser, array $validated): StaffAgencyAssignment
    {
        $agency = $this->resolveAgency(
            $actor,
            is_string($validated['agency_code'] ?? null) ? $validated['agency_code'] : null,
            $staffUser,
        );
        if ($agency === null) {
            throw ValidationException::withMessages(['agency_code' => [__('domain.staff_selected_agency_invalid')]]);
        }

        if (! $this->canManageAgency($actor, $agency->id)) {
            throw ValidationException::withMessages(['agency_code' => [__('domain.staff_within_agency_scope')]]);
        }

        $roleAtAgency = is_string($validated['role_at_agency']) ? $validated['role_at_agency'] : 'staff';
        $isPrimary = (bool) ($validated['is_primary'] ?? true);
        $status = is_string($validated['status'] ?? null) ? $validated['status'] : StaffAgencyAssignment::STATUS_ACTIVE;
        $startsOnValue = is_string($validated['starts_on']) ? $validated['starts_on'] : now()->toDateString();
        $newStartsOn = Carbon::parse($startsOnValue)->startOfDay();

        return DB::transaction(function () use ($staffUser, $agency, $validated, $roleAtAgency, $isPrimary, $status, $newStartsOn): StaffAgencyAssignment {
            if ($isPrimary) {
                $existingPrimary = StaffAgencyAssignment::query()
                    ->where('user_id', $staffUser->id)
                    ->where('is_primary', true)
                    ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
                    ->latest('starts_on')
                    ->first();

                if ($existingPrimary !== null) {
                    $existingStartsOn = Carbon::parse($existingPrimary->starts_on)->startOfDay();
                    if ($newStartsOn->lessThanOrEqualTo($existingStartsOn)) {
                        throw ValidationException::withMessages([
                            'starts_on' => [__('domain.staff_primary_assignment_transfer_after_current')],
                        ]);
                    }

                    $existingPrimary->forceFill([
                        'status' => StaffAgencyAssignment::STATUS_ENDED,
                        'ends_on' => $newStartsOn->copy()->subDay()->toDateString(),
                    ])->save();
                }
            }

            $assignment = StaffAgencyAssignment::query()->create([
                'public_id' => (string) Str::ulid(),
                'user_id' => $staffUser->id,
                'agency_id' => $agency->id,
                'role_at_agency' => $roleAtAgency,
                'starts_on' => $validated['starts_on'],
                'ends_on' => $validated['ends_on'] ?? null,
                'is_primary' => $isPrimary,
                'status' => $status,
            ]);

            if ($isPrimary) {
                $staffUser->forceFill([
                    'agency_id' => $agency->id,
                    'agency_code' => $agency->code,
                    'agency_name' => $agency->name,
                ])->save();
            }

            return $assignment->refresh()->loadMissing('agency');
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function end(User $staffUser, StaffAgencyAssignment $assignment, array $validated): StaffAgencyAssignment
    {
        $endsOnValue = is_string($validated['ends_on']) ? $validated['ends_on'] : now()->toDateString();
        $endsOn = Carbon::parse($endsOnValue)->startOfDay();
        $startsOn = Carbon::parse($assignment->starts_on)->startOfDay();
        if ($endsOn->lessThan($startsOn)) {
            throw ValidationException::withMessages([
                'ends_on' => [__('domain.staff_assignment_end_date_after_start')],
            ]);
        }

        $assignment->forceFill([
            'ends_on' => $endsOnValue,
            'status' => is_string($validated['status'] ?? null) ? $validated['status'] : StaffAgencyAssignment::STATUS_ENDED,
            'is_primary' => false,
        ])->save();

        return $assignment->refresh()->loadMissing('agency');
    }

    private function canManageAgency(User $actor, ?int $agencyId): bool
    {
        if ($agencyId === null) {
            return false;
        }

        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $actor->currentAgencyId() === $agencyId;
    }

    private function resolveAgency(User $actor, ?string $agencyCode, User $staffUser): ?Agency
    {
        if (is_string($agencyCode) && $agencyCode !== '') {
            $agency = Agency::query()->where('code', $agencyCode)->first();

            if ($agency !== null) {
                return $agency;
            }
        }

        $agencyId = $staffUser->currentAgencyId();
        if ($agencyId === null) {
            if ($actor->hasRole('platform-admin') && $actor->currentAgencyId() !== null) {
                return Agency::query()->whereKey($actor->currentAgencyId())->first();
            }

            return null;
        }

        return Agency::query()->whereKey($agencyId)->first();
    }
}
