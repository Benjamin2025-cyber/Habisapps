<?php

declare(strict_types=1);

namespace App\Application\Agencies;

use App\Models\Agency;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssignAgencyManager
{
    public function execute(Agency $agency, User $manager, string $roleAtAgency): void
    {
        DB::transaction(function () use ($agency, $manager, $roleAtAgency): void {
            $primaryAssignment = StaffAgencyAssignment::query()
                ->where('user_id', $manager->id)
                ->where('agency_id', $agency->id)
                ->where('is_primary', true)
                ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
                ->latest('starts_on')
                ->first();

            if ($primaryAssignment === null) {
                StaffAgencyAssignment::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'user_id' => $manager->id,
                    'agency_id' => $agency->id,
                    'role_at_agency' => $roleAtAgency,
                    'starts_on' => now()->toDateString(),
                    'is_primary' => true,
                    'status' => StaffAgencyAssignment::STATUS_ACTIVE,
                ]);
            }

            $manager->forceFill([
                'agency_id' => $agency->id,
                'agency_code' => $agency->code,
                'agency_name' => $agency->name,
            ])->save();

            $agency->forceFill(['manager_id' => $manager->id])->save();
        });
    }
}
