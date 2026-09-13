<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AvailableBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $balance */
        $balance = $this->resource;

        return [
            'scope' => $balance['scope'],
            'public_id' => $balance['public_id'],
            'currency' => $balance['currency'],
            'accounting_balance_minor' => $balance['accounting_balance_minor'],
            // « Solde réel » — the account's own money — alongside « solde
            // disponible », the part of it that can leave the counter. Served
            // rather than derived, so the screen cannot disagree with the
            // ledger about the same account.
            'real_balance_minor' => $balance['real_balance_minor'],
            'minimum_balance_minor' => $balance['minimum_balance_minor'],
            'unavailable_amount_minor' => $balance['unavailable_amount_minor'],
            'active_hold_amount_minor' => $balance['active_hold_amount_minor'],
            // Part of the available figure, so it has to be visible for the
            // screen to explain how the figure was reached.
            'overdraft_limit_minor' => $balance['overdraft_limit_minor'],
            'available_balance_minor' => $balance['available_balance_minor'],
        ];
    }
}
