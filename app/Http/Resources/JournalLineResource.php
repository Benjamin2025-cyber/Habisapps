<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JournalLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JournalLine
 */
final class JournalLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JournalLine $line */
        $line = $this->resource;

        return [
            'public_id' => $line->public_id,
            'journal_entry_public_id' => $line->relationLoaded('journalEntry') ? $line->journalEntry?->public_id : null,
            'ledger_account_public_id' => $line->relationLoaded('ledgerAccount') ? $line->ledgerAccount?->public_id : null,
            // Sent with the line so a client can label it without resolving the
            // foreign key itself. It used to do that by loading a page of the
            // chart and matching locally, which silently stops working once the
            // chart is larger than one page — and a real PCEMF chart is ~1 400
            // accounts per agency.
            'ledger_account_code' => $line->relationLoaded('ledgerAccount') ? $line->ledgerAccount?->code : null,
            'ledger_account_name' => $line->relationLoaded('ledgerAccount') ? $line->ledgerAccount?->name : null,
            'customer_account_public_id' => $line->relationLoaded('customerAccount') ? $line->customerAccount?->public_id : null,
            'debit_minor' => $line->debit_minor,
            'credit_minor' => $line->credit_minor,
            'currency' => $line->currency,
            'line_memo' => $line->line_memo,
            'created_at' => $line->created_at?->toAtomString(),
            'updated_at' => $line->updated_at?->toAtomString(),
        ];
    }
}
