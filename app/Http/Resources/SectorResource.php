<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Sector;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Sector
 */
final class SectorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Sector $sector */
        $sector = $this->resource;

        return [
            'public_id' => $sector->public_id,
            'code' => $sector->code,
            'name' => $sector->name,
            'status' => $sector->status,
            'created_at' => $sector->created_at?->toAtomString(),
            'updated_at' => $sector->updated_at?->toAtomString(),
        ];
    }
}
