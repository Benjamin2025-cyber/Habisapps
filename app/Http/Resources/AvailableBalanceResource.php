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
            'minimum_balance_minor' => $balance['minimum_balance_minor'],
            'unavailable_amount_minor' => $balance['unavailable_amount_minor'],
            'active_hold_amount_minor' => $balance['active_hold_amount_minor'],
            'available_balance_minor' => $balance['available_balance_minor'],
        ];
    }
}
