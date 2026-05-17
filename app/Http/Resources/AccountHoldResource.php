<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccountHold;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin AccountHold
 */
final class AccountHoldResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AccountHold $hold */
        $hold = $this->resource;

        return [
            'public_id' => $hold->public_id,
            'customer_account_public_id' => $hold->relationLoaded('customerAccount') ? $hold->customerAccount?->public_id : null,
            'amount_minor' => $hold->amount_minor,
            'currency' => $hold->currency,
            'reason_type' => $hold->reason_type,
            'source_type' => $hold->source_type,
            'source_public_id' => $hold->source_public_id,
            'status' => $hold->status,
            'placed_at' => $hold->placed_at !== null ? Carbon::parse($hold->placed_at)->toAtomString() : null,
            'expires_at' => $hold->expires_at !== null ? Carbon::parse($hold->expires_at)->toAtomString() : null,
            'released_at' => $hold->released_at !== null ? Carbon::parse($hold->released_at)->toAtomString() : null,
            'release_reason' => $hold->release_reason,
            'reference' => $hold->reference,
            'created_at' => $hold->created_at?->toAtomString(),
            'updated_at' => $hold->updated_at?->toAtomString(),
        ];
    }
}
