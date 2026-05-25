<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Agency;
use App\Models\HrEmployee;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class StaffUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        if (! $user instanceof User) {
            return [
                'public_id' => null,
                'name' => null,
                'phone_number' => null,
                'email' => null,
                'status' => null,
                'matricule' => null,
                'job_title' => null,
                'agency_public_id' => null,
                'agency_code' => null,
                'agency_name' => null,
                'phone_verified_at' => null,
                'activated_at' => null,
                'last_login_at' => null,
                'professional_profile' => null,
                'roles' => [],
                'permissions' => [],
                'direct_permissions' => [],
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'phone_number' => $user->phone_number,
            'email' => $user->email,
            'status' => $user->status,
            'matricule' => $user->matricule,
            'job_title' => $user->job_title,
            'agency_public_id' => $this->agencyPublicId($user),
            'agency_code' => $this->agencyCode($user),
            'agency_name' => $this->agencyName($user),
            'phone_verified_at' => $this->formatDate($user->phone_verified_at),
            'activated_at' => $this->formatDate($user->activated_at),
            'last_login_at' => $this->formatDate($user->last_login_at),
            'professional_profile' => $this->professionalProfile($user),
            'roles' => $user->getRoleNames()->values()->all(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            'direct_permissions' => $user->getDirectPermissions()->pluck('name')->values()->all(),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }

    private function agencyCode(User $user): ?string
    {
        $agency = $user->relationLoaded('agency') ? $user->agency : null;

        if ($agency instanceof Agency) {
            return $agency->code;
        }

        return $user->agency_code;
    }

    private function agencyPublicId(User $user): ?string
    {
        $agency = $user->relationLoaded('agency') ? $user->agency : null;

        if ($agency instanceof Agency) {
            return $agency->public_id;
        }

        if (! is_int($user->agency_id) || $user->agency_id <= 0) {
            return null;
        }

        $publicId = Agency::query()->whereKey($user->agency_id)->value('public_id');

        return is_string($publicId) ? $publicId : null;
    }

    private function agencyName(User $user): ?string
    {
        $agency = $user->relationLoaded('agency') ? $user->agency : null;

        if ($agency instanceof Agency) {
            return $agency->name;
        }

        return $user->agency_name;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function professionalProfile(User $user): ?array
    {
        $employee = $user->relationLoaded('hrEmployee') ? $user->hrEmployee : null;
        if (! $employee instanceof HrEmployee) {
            return null;
        }

        return [
            'gender' => $employee->gender,
            'birth_date' => $employee->birth_date?->toDateString(),
            'birth_place' => $employee->birth_place,
            'job_title' => $employee->job_title,
            'service_name' => $employee->service_name,
            'supervisor_public_id' => $employee->relationLoaded('supervisor') ? $employee->supervisor?->public_id : null,
            'portfolio_code' => $employee->portfolio_code,
            'source' => 'hr_handoff',
        ];
    }
}
