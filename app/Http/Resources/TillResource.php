<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Till;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Till
 */
final class TillResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Till $till */
        $till = $this->resource;

        return [
            'public_id' => $till->public_id,
            'agency_public_id' => $till->relationLoaded('agency') ? $till->agency?->public_id : null,
            'code' => $till->code,
            'name' => $till->name,
            'type' => $till->type,
            'status' => $till->status,
            'assigned_user_public_id' => $till->relationLoaded('assignedUser') ? $till->assignedUser?->public_id : null,
            'created_at' => $till->created_at?->toAtomString(),
            'updated_at' => $till->updated_at?->toAtomString(),
        ];
    }
}
