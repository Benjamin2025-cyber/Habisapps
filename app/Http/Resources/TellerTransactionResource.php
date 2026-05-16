<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TellerTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TellerTransaction
 */
final class TellerTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TellerTransaction $transaction */
        $transaction = $this->resource;

        return [
            'public_id' => $transaction->public_id,
            'teller_session_public_id' => $transaction->relationLoaded('tellerSession') ? $transaction->tellerSession?->public_id : null,
            'till_public_id' => $transaction->relationLoaded('till') ? $transaction->till?->public_id : null,
            'customer_account_public_id' => $transaction->relationLoaded('customerAccount') ? $transaction->customerAccount?->public_id : null,
            'journal_entry_public_id' => $transaction->relationLoaded('journalEntry') ? $transaction->journalEntry?->public_id : null,
            'transaction_date' => $transaction->transaction_date,
            'transaction_type' => $transaction->transaction_type,
            'amount_minor' => $transaction->amount_minor,
            'currency' => $transaction->currency,
            'status' => $transaction->status,
            'reference' => $transaction->reference,
            'event_number' => $transaction->event_number,
            'operation_code' => $transaction->operation_code,
            'depositor_name' => $transaction->depositor_name,
            'depositor_address' => $transaction->depositor_address,
            'description' => $transaction->description,
            'created_at' => $transaction->created_at?->toAtomString(),
            'updated_at' => $transaction->updated_at?->toAtomString(),
        ];
    }
}
