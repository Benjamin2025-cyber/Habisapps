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
