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
            'agency_public_id' => $ledgerAccount->relationLoaded('agency') ? $ledgerAccount->agency?->public_id : null,
            'parent_account_public_id' => $ledgerAccount->relationLoaded('parentAccount') ? $ledgerAccount->parentAccount?->public_id : null,
            'code' => $ledgerAccount->code,
            'name' => $ledgerAccount->name,
            'account_class' => $ledgerAccount->account_class,
            'account_type' => $ledgerAccount->account_type,
            'normal_balance_side' => $ledgerAccount->normal_balance_side,
            'status' => $ledgerAccount->status,
            'created_at' => $ledgerAccount->created_at?->toAtomString(),
            'updated_at' => $ledgerAccount->updated_at?->toAtomString(),
        ];
    }
}
