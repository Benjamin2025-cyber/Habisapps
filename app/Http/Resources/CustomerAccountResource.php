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
            // Names, not only ids. The account screen resolved these by
            // searching a client-side list capped at 100 rows, so the 101st
            // client's account showed a ULID where the holder's name belongs,
            // and the agency showed one always. The holder name carries the
            // middle name too — dropping it made the name genuinely incomplete.
            'client_display_name' => $account->relationLoaded('client') ? $this->clientDisplayName($account) : null,
            'agency_public_id' => $account->relationLoaded('agency') ? $account->agency?->public_id : null,
            'agency_name' => $account->relationLoaded('agency') ? $account->agency?->name : null,
            'ledger_account_public_id' => $account->relationLoaded('ledgerAccount') ? $account->ledgerAccount?->public_id : null,
            // The GL code the entries carry — the client's own number, so the
            // accountant never has to look it up (« il sera très fastidieux de
            // devoir consulter ses informations »).
            'ledger_account_code' => $account->relationLoaded('ledgerAccount') ? $account->ledgerAccount?->code : null,
            'account_product_public_id' => $account->relationLoaded('accountProduct') ? $account->accountProduct?->public_id : null,
            'account_number' => $account->account_number,
            'account_title' => $account->account_title,
            'account_type' => $account->account_type,
            'currency' => $account->currency,
            'unavailable_amount_minor' => $account->unavailable_amount_minor,
            'opened_on' => $account->opened_on,
            'closed_on' => $account->closed_on,
            'status' => $account->status,
            'created_at' => $account->created_at?->toAtomString(),
            'updated_at' => $account->updated_at?->toAtomString(),
        ];
    }

    /**
     * NOM Prénoms, the order a French-language statement prints. Middle name
     * included: it is part of the prénoms and omitting it truncates the holder.
     * Falls back to the client reference when a record carries no name parts.
     */
    private function clientDisplayName(CustomerAccount $account): ?string
    {
        $client = $account->client;
        if ($client === null) {
            return null;
        }

        $parts = array_values(array_filter([
            mb_strtoupper(trim($client->last_name)),
            trim($client->first_name),
            trim((string) $client->middle_name),
        ], static fn (string $part): bool => $part !== ''));

        return $parts === [] ? $client->client_reference : implode(' ', $parts);
    }
}
