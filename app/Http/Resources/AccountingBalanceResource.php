<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class AccountingBalanceResource extends JsonResource
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
            'from' => $balance['from'],
            'to' => $balance['to'],
            'debit_total_minor' => $balance['debit_total_minor'],
            'credit_total_minor' => $balance['credit_total_minor'],
            'balance_minor' => $balance['balance_minor'],
            'normal_balance_side' => $balance['normal_balance_side'],
        ];
    }
}
