<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Till;
use Carbon\CarbonInterface;
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
        $lastClosingAt = $till->getAttribute('last_closing_at');

        return [
            'public_id' => $till->public_id,
            'agency_public_id' => $till->relationLoaded('agency') ? $till->agency?->public_id : null,
            'code' => $till->code,
            'name' => $till->name,
            'type' => $till->type,
            'status' => $till->status,
            'daily_state' => $till->daily_state,
            'opening_balance_minor' => $till->opening_balance_minor,
            'last_closing_balance_minor' => $till->last_closing_balance_minor,
            'last_closing_at' => $lastClosingAt instanceof CarbonInterface ? $lastClosingAt->toAtomString() : null,
            'requires_denominations' => $till->requires_denominations,
            'nature' => $till->nature,
            'is_central_till' => $till->is_central_till,
            'max_balance_limit_minor' => $till->max_balance_limit_minor,
            'max_withdrawal_limit_minor' => $till->max_withdrawal_limit_minor,
            'currency' => $till->currency,
            'assigned_user_public_id' => $till->relationLoaded('assignedUser') ? $till->assignedUser?->public_id : null,
            'ledger_account_public_id' => $till->relationLoaded('ledgerAccount') ? $till->ledgerAccount?->public_id : null,
            'created_at' => $till->created_at?->toAtomString(),
            'updated_at' => $till->updated_at?->toAtomString(),
        ];
    }
}
