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
            'opening_fee_minor' => $product->opening_fee_minor,
            // The income sub-account this product's fee is credited to. Null means
            // the agency's operation mapping decides, which is what a structure
            // running a single opening-fee account wants.
            'opening_fee_ledger_account_public_id' => $product->relationLoaded('openingFeeLedgerAccount')
                ? $product->openingFeeLedgerAccount?->public_id
                : null,
            'currency' => $product->currency,
            'allows_overdraft' => $product->allows_overdraft,
            'overdraft_limit_minor' => $product->overdraft_limit_minor,
            'status' => $product->status,
            'rules' => $product->rules,
            'created_at' => $product->created_at?->toAtomString(),
            'updated_at' => $product->updated_at?->toAtomString(),
        ];
    }
}
