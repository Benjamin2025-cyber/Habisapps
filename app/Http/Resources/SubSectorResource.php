<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SubSector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubSector
 */
final class SubSectorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SubSector $subSector */
        $subSector = $this->resource;

        return [
            'public_id' => $subSector->public_id,
            'sector_public_id' => $subSector->relationLoaded('sector') ? $subSector->sector?->public_id : null,
            'code' => $subSector->code,
            'name' => $subSector->name,
            'status' => $subSector->status,
            'created_at' => $subSector->created_at?->toAtomString(),
            'updated_at' => $subSector->updated_at?->toAtomString(),
        ];
    }
}
