<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Agency;
use App\Models\StaffAgencyAssignment;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffAgencyAssignment
 */
final class StaffAgencyAssignmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assignment = $this->resource;

        if (! $assignment instanceof StaffAgencyAssignment) {
            return [
                'public_id' => null,
                'agency_public_id' => null,
                'agency_code' => null,
                'role_at_agency' => null,
                'starts_on' => null,
                'ends_on' => null,
                'is_primary' => null,
                'status' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        $agency = $assignment->relationLoaded('agency') ? $assignment->agency : null;

        return [
            'public_id' => $assignment->public_id,
            'agency_public_id' => $agency instanceof Agency ? $agency->public_id : null,
            'agency_code' => $agency instanceof Agency ? $agency->code : null,
            'role_at_agency' => $assignment->role_at_agency,
            'starts_on' => $this->formatDate($assignment->starts_on),
            'ends_on' => $this->formatDate($assignment->ends_on),
            'is_primary' => $assignment->is_primary,
            'status' => $assignment->status,
            'created_at' => $this->formatDate($assignment->created_at),
            'updated_at' => $this->formatDate($assignment->updated_at),
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
