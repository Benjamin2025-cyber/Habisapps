<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Collateral;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Collateral */
final class CollateralResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $collateral = $this->resource;
        if (! $collateral instanceof Collateral) {
            return [
                'public_id' => null,
                'agency_public_id' => null,
                'client_public_id' => null,
                'loan_public_id' => null,
                'document_public_id' => null,
                'collateral_type' => null,
                'description' => null,
                'owner_full_name' => null,
                'status' => null,
                'valuation_date' => null,
                'declared_value_minor' => null,
                'currency' => null,
                'items' => [],
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return [
            'public_id' => $collateral->public_id,
            'agency_public_id' => $collateral->agency?->public_id,
            'client_public_id' => $collateral->client?->public_id,
            'loan_public_id' => $collateral->loan?->public_id,
            'document_public_id' => $collateral->document?->public_id,
            'collateral_type' => $collateral->collateral_type,
            'description' => $collateral->description,
            'owner_full_name' => $collateral->owner_full_name,
            'status' => $collateral->status,
            'valuation_date' => $this->formatDateOnly($collateral->valuation_date),
            'declared_value_minor' => $collateral->declared_value_minor,
            'currency' => $collateral->currency,
            'items' => CollateralItemResource::collection($this->whenLoaded('items')),
            'created_at' => $collateral->created_at?->toISOString(),
            'updated_at' => $collateral->updated_at?->toISOString(),
        ];
    }

    private function formatDateOnly(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
