<?php

declare(strict_types=1);

namespace App\Application\Staff;

use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SyncStaffUser
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(User $actor, array $attributes): User
    {
        return DB::transaction(function () use ($actor, $attributes): User {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'phone_number' => $attributes['phone_number'],
                'email' => $attributes['email'] ?? null,
                'matricule' => $attributes['matricule'] ?? null,
                'job_title' => $attributes['job_title'] ?? null,
                'agency_id' => $attributes['agency_id'],
                'agency_code' => $attributes['agency_code'],
                'agency_name' => $attributes['agency_name'],
                'status' => User::STATUS_PENDING_VERIFICATION,
                'invited_by_user_id' => $actor->id,
            ]);

            if (is_int($attributes['agency_id'])) {
                $this->createPrimaryAssignment($user, $attributes['agency_id'], $user->job_title ?? 'staff');
            }

            $this->syncProfessionalProfile($user, $attributes);
            $user->assignRole('staff');

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $staffUser, array $attributes): void
    {
        DB::transaction(function () use ($attributes, $staffUser): void {
            $staffUser->update($attributes);

            if (array_key_exists('agency_id', $attributes)) {
                $agencyId = $attributes['agency_id'];
                $this->replacePrimaryAssignment($staffUser, is_int($agencyId) ? $agencyId : null, $staffUser->job_title ?? 'staff');
            }

            $this->syncProfessionalProfile($staffUser->refresh(), $attributes);
        });
    }

    /**
     * @return array{agency_id:int|null, agency_code:string|null, agency_name:string|null}
     */
    public function resolveAgencyAttributes(?string $agencyCode): array
    {
        if (! is_string($agencyCode) || $agencyCode === '') {
            return [
                'agency_id' => null,
                'agency_code' => null,
                'agency_name' => null,
            ];
        }

        $agency = DB::table('agencies')
            ->where('code', $agencyCode)
            ->first(['id', 'code', 'name']);

        if ($agency === null) {
            return [
                'agency_id' => null,
                'agency_code' => $agencyCode,
                'agency_name' => null,
            ];
        }

        return [
            'agency_id' => property_exists($agency, 'id') && is_numeric($agency->id) ? (int) $agency->id : null,
            'agency_code' => property_exists($agency, 'code') && is_string($agency->code) ? $agency->code : $agencyCode,
            'agency_name' => property_exists($agency, 'name') && is_string($agency->name) ? $agency->name : null,
        ];
    }

    public function canAssignAgency(User $actor, ?int $agencyId, int $actorCurrentAgencyId): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $agencyId !== null && $actorCurrentAgencyId === $agencyId;
    }

    public function wouldRemoveLastActivePlatformAdmin(User $target): bool
    {
        if (! $target->hasRole('platform-admin') || $target->status !== User::STATUS_ACTIVE) {
            return false;
        }

        $activePlatformAdmins = DB::table('users')
            ->join('model_has_roles', static function ($join): void {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'platform-admin')
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereNotNull('users.phone_verified_at')
            ->count();

        return $activePlatformAdmins <= 1;
    }

    private function createPrimaryAssignment(User $user, int $agencyId, string $roleAtAgency): void
    {
        (new StaffAgencyAssignment)->newQuery()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => $roleAtAgency,
            'starts_on' => now()->toDateString(),
            'is_primary' => true,
            'status' => StaffAgencyAssignment::STATUS_ACTIVE,
        ]);
    }

    private function replacePrimaryAssignment(User $user, ?int $agencyId, string $roleAtAgency): void
    {
        $today = now()->toDateString();
        $currentAssignment = DB::table('staff_agency_assignments')
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
            ->where('starts_on', '<=', $today)
            ->whereRaw('(ends_on IS NULL OR ends_on >= ?)', [$today])
            ->latest('starts_on')
            ->first(['agency_id']);

        if (is_object($currentAssignment)
            && property_exists($currentAssignment, 'agency_id')
            && is_numeric($currentAssignment->agency_id)
            && (int) $currentAssignment->agency_id === $agencyId) {
            return;
        }

        (new StaffAgencyAssignment)->newQuery()
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
            ->update([
                'status' => StaffAgencyAssignment::STATUS_ENDED,
                'ends_on' => now()->toDateString(),
                'updated_at' => now(),
            ]);

        if ($agencyId !== null) {
            $this->createPrimaryAssignment($user, $agencyId, $roleAtAgency);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncProfessionalProfile(User $user, array $attributes): void
    {
        $profileKeys = [
            'gender',
            'birth_date',
            'birth_place',
            'service_name',
            'supervisor_id',
            'portfolio_code',
        ];

        $hasProfileInput = array_key_exists('name', $attributes)
            || array_key_exists('phone_number', $attributes)
            || array_key_exists('email', $attributes)
            || array_key_exists('job_title', $attributes)
            || array_key_exists('agency_id', $attributes);

        foreach ($profileKeys as $key) {
            $hasProfileInput = $hasProfileInput || array_key_exists($key, $attributes);
        }

        if (! $hasProfileInput) {
            return;
        }

        [$firstName, $lastName] = $this->splitName($user->name);
        $employeeNumber = $user->matricule;
        if (! is_string($employeeNumber) || $employeeNumber === '') {
            $employeeNumber = 'STAFF-'.$user->public_id;
        }

        $profile = [
            'agency_id' => $user->agency_id,
            'employee_number' => $employeeNumber,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone_number' => $user->phone_number,
            'email' => $user->email,
            'job_title' => $user->job_title,
            'status' => $user->status === User::STATUS_DEACTIVATED ? 'inactive' : 'active',
            'metadata' => [
                'source' => 'module_1_staff_profile_handoff',
                'hr_owned_fields_not_exposed' => [
                    'identity_number',
                    'base_salary_minor',
                    'emergency_contact',
                    'professional_history',
                ],
            ],
        ];

        foreach ($profileKeys as $key) {
            if (array_key_exists($key, $attributes)) {
                $profile[$key] = $attributes[$key];
            }
        }

        $profile['metadata'] = json_encode($profile['metadata'], JSON_THROW_ON_ERROR);
        $existingId = DB::table('hr_employees')->where('user_id', $user->id)->value('id');

        if (is_int($existingId)) {
            DB::table('hr_employees')
                ->where('id', $existingId)
                ->update(array_merge($profile, ['updated_at' => now()]));

            return;
        }

        DB::table('hr_employees')->insert(array_merge($profile, [
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name));
        if ($parts === false) {
            $parts = [];
        }
        $firstName = is_string($parts[0] ?? null) && $parts[0] !== '' ? $parts[0] : $name;
        $lastName = trim(implode(' ', array_slice($parts, 1)));

        return [$firstName, $lastName !== '' ? $lastName : $firstName];
    }
}
