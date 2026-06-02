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
            'payment_method' => $transaction->payment_method ?? TellerTransaction::PAYMENT_CASH,
            'cash_amount_minor' => $transaction->cash_amount_minor ?? $transaction->amount_minor,
            'cheque_amount_minor' => $transaction->cheque_amount_minor ?? 0,
            'transfer_amount_minor' => $transaction->transfer_amount_minor ?? 0,
            'channel' => $transaction->channel,
            'external_reference' => $transaction->external_reference,
            'fee_policy_key' => $transaction->fee_policy_key,
            'fees_applied' => $transaction->fees_applied ?? false,
            'fee_amount_minor' => $transaction->fee_amount_minor ?? 0,
            'notify_customer' => $transaction->notify_customer ?? false,
            'notification_channels' => $transaction->notification_channels ?? [],
            'notification_status' => $transaction->notification_status ?? TellerTransaction::NOTIFICATION_NOT_REQUESTED,
            'tenders' => $this->tenderPayload($transaction),
            'depositor_name' => $transaction->depositor_name,
            'depositor_address' => $transaction->depositor_address,
            'initiator_type' => $transaction->initiator_type,
            'initiator_proxy_public_id' => $transaction->relationLoaded('initiatorProxy') ? $transaction->initiatorProxy?->public_id : null,
            'customer_account_signature_public_id' => $transaction->relationLoaded('customerAccountSignature') ? $transaction->customerAccountSignature?->public_id : null,
            'signature_checked_at' => $transaction->signature_checked_at,
            'signature_checked_by_user_public_id' => $transaction->relationLoaded('signatureCheckedBy') ? $transaction->signatureCheckedBy?->public_id : null,
            'signature_verification_method' => $transaction->signature_verification_method,
            'description' => $transaction->description,
            'created_at' => $transaction->created_at?->toAtomString(),
            'updated_at' => $transaction->updated_at?->toAtomString(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function tenderPayload(TellerTransaction $transaction): array
    {
        if (! $transaction->relationLoaded('tenders')) {
            return [];
        }

        $payload = [];
        foreach ($transaction->tenders as $tender) {
            $payload[] = [
                'public_id' => $tender->public_id,
                'method' => $tender->method,
                'amount_minor' => $tender->amount_minor,
                'currency' => $tender->currency,
                'status' => $tender->status,
                'channel' => $tender->channel,
                'external_reference' => $tender->external_reference,
                'cheque_number' => $tender->cheque_number,
                'cheque_bank_name' => $tender->cheque_bank_name,
                'cheque_issue_date' => $tender->cheque_issue_date,
                'ledger_mapping_evidence' => $tender->ledger_mapping_evidence,
                'denomination_counts' => $tender->denomination_counts,
            ];
        }

        return $payload;
    }
}
