<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Agency;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Agency
 */
final class AgencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Agency $agency */
        $agency = $this->resource;

        $manager = $agency->relationLoaded('manager') ? $agency->manager : null;

        return [
            'public_id' => $agency->public_id,
            'code' => $agency->code,
            'name' => $agency->name,
            'region' => $agency->region,
            'city' => $agency->city,
            'branch_name' => $agency->branch_name,
            'branch_type' => $agency->branch_type,
            'phone_number' => $agency->phone_number,
            'fax_number' => $agency->fax_number,
            'email' => $agency->email,
            'address_line_1' => $agency->address_line_1,
            'address_line_2' => $agency->address_line_2,
            'po_box' => $agency->po_box,
            'geographic_description' => $agency->geographic_description,
            'creation_date' => $agency->creation_date,
            'status' => $agency->status,
            'manager_public_id' => $manager instanceof User ? $manager->public_id : null,
            'manager_name' => $manager instanceof User ? $manager->name : null,
            'created_at' => $this->formatDate($agency->created_at),
            'updated_at' => $this->formatDate($agency->updated_at),
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
