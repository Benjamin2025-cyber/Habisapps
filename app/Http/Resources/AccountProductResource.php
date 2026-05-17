<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccountProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountProduct
 */
final class AccountProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AccountProduct $product */
        $product = $this->resource;

        return [
            'public_id' => $product->public_id,
            'agency_public_id' => $product->relationLoaded('agency') ? $product->agency?->public_id : null,
            'ledger_account_public_id' => $product->relationLoaded('ledgerAccount') ? $product->ledgerAccount?->public_id : null,
            'code' => $product->code,
            'name' => $product->name,
            'account_family' => $product->account_family,
            'minimum_balance_minor' => $product->minimum_balance_minor,
            'currency' => $product->currency,
            'allows_recovery_debit' => $product->allows_recovery_debit,
            'is_recovery_account' => $product->is_recovery_account,
            'is_ordinary_savings' => $product->is_ordinary_savings,
            'allows_overdraft' => $product->allows_overdraft,
            'overdraft_limit_minor' => $product->overdraft_limit_minor,
            'status' => $product->status,
            'rules' => $product->rules,
            'created_at' => $product->created_at?->toAtomString(),
            'updated_at' => $product->updated_at?->toAtomString(),
        ];
    }
}
