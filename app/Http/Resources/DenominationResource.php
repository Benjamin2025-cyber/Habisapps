<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Denomination;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Denomination
 */
final class DenominationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Denomination $denomination */
        $denomination = $this->resource;

        return [
            'public_id' => $denomination->public_id,
            'code' => $denomination->code,
            'label' => $denomination->label,
            'value_minor' => $denomination->value_minor,
            'currency' => $denomination->currency,
            'type' => $denomination->type,
            'status' => $denomination->status,
            'created_at' => $denomination->created_at?->toAtomString(),
            'updated_at' => $denomination->updated_at?->toAtomString(),
        ];
    }
}
