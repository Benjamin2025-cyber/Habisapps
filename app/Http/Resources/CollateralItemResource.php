<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CollateralItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CollateralItem */
final class CollateralItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        if (! $item instanceof CollateralItem) {
            return [
                'public_id' => null,
                'quantity' => null,
                'description' => null,
                'reference' => null,
                'chassis_number' => null,
                'registration_number' => null,
                'amount_minor' => null,
                'currency' => null,
                'metadata' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return [
            'public_id' => $item->public_id,
            'quantity' => $item->quantity,
            'description' => $item->description,
            'reference' => $item->reference,
            'chassis_number' => $item->chassis_number,
            'registration_number' => $item->registration_number,
            'amount_minor' => $item->amount_minor,
            'currency' => $item->currency,
            'metadata' => $item->metadata,
            'created_at' => $item->created_at?->toISOString(),
            'updated_at' => $item->updated_at?->toISOString(),
        ];
    }
}
