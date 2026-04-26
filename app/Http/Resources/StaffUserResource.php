<?php

declare(strict_types=1);

namespace App\Http\Resources;

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
            return [];
        }

        return [
            'public_id' => $user->public_id,
            'name' => $user->name,
            'phone_number' => $user->phone_number,
            'email' => $user->email,
            'status' => $user->status,
            'matricule' => $user->matricule,
            'job_title' => $user->job_title,
            'agency_code' => $user->agency_code,
            'agency_name' => $user->agency_name,
            'phone_verified_at' => $this->formatDate($user->phone_verified_at),
            'activated_at' => $this->formatDate($user->activated_at),
            'last_login_at' => $this->formatDate($user->last_login_at),
            'roles' => $user->getRoleNames()->values()->all(),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
