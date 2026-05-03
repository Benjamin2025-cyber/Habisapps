<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerAccount
 */
final class CustomerAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CustomerAccount $account */
        $account = $this->resource;

        return [
            'public_id' => $account->public_id,
            'client_public_id' => $account->relationLoaded('client') ? $account->client?->public_id : null,
            'agency_public_id' => $account->relationLoaded('agency') ? $account->agency?->public_id : null,
            'ledger_account_public_id' => $account->relationLoaded('ledgerAccount') ? $account->ledgerAccount?->public_id : null,
            'account_number' => $account->account_number,
            'account_type' => $account->account_type,
            'opened_on' => $account->opened_on,
            'closed_on' => $account->closed_on,
            'status' => $account->status,
            'created_at' => $account->created_at?->toAtomString(),
            'updated_at' => $account->updated_at?->toAtomString(),
        ];
    }
}
