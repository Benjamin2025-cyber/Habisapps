<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LedgerAccount
 */
final class LedgerAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LedgerAccount $ledgerAccount */
        $ledgerAccount = $this->resource;

        return [
            'public_id' => $ledgerAccount->public_id,
            'scope' => $ledgerAccount->accountScope(),
            'agency_public_id' => $ledgerAccount->relationLoaded('agency') ? $ledgerAccount->agency?->public_id : null,
            // The chart repeats every detail account per agency, so a reader with
            // institution scope sees `3712 Comptes courants clients` once per
            // agency, identically. Without a name to tell them apart the list is
            // unusable and the choice is a guess.
            'agency_code' => $ledgerAccount->relationLoaded('agency') ? $ledgerAccount->agency?->code : null,
            'agency_name' => $ledgerAccount->relationLoaded('agency') ? $ledgerAccount->agency?->name : null,
            'parent_account_public_id' => $ledgerAccount->relationLoaded('parentAccount') ? $ledgerAccount->parentAccount?->public_id : null,
            'code' => $ledgerAccount->code,
            'name' => $ledgerAccount->name,
            'account_class' => $ledgerAccount->account_class,
            'account_type' => $ledgerAccount->account_type,
            'is_postable' => $ledgerAccount->is_postable,
            'normal_balance_side' => $ledgerAccount->normal_balance_side,
            'status' => $ledgerAccount->status,
            'created_at' => $ledgerAccount->created_at?->toAtomString(),
            'updated_at' => $ledgerAccount->updated_at?->toAtomString(),
        ];
    }
}
