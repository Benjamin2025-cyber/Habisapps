<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TellerSession;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TellerSession
 */
final class TellerSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TellerSession $session */
        $session = $this->resource;
        $businessDate = $session->getAttribute('business_date');
        $openedAt = $session->getAttribute('opened_at');
        $closedAt = $session->getAttribute('closed_at');
        $createdAt = $session->getAttribute('created_at');
        $updatedAt = $session->getAttribute('updated_at');
        $summary = $session->getAttribute('cash_summary');

        return [
            'public_id' => $session->getAttribute('public_id'),
            'agency_public_id' => $session->relationLoaded('agency') ? $session->agency?->getAttribute('public_id') : null,
            'accounting_day_public_id' => $session->relationLoaded('accountingDay') ? $session->accountingDay?->getAttribute('public_id') : null,
            'till_public_id' => $session->relationLoaded('till') ? $session->till?->getAttribute('public_id') : null,
            'teller_user_public_id' => $session->relationLoaded('teller') ? $session->teller?->getAttribute('public_id') : null,
            'business_date' => $businessDate instanceof CarbonInterface ? $businessDate->toDateString() : null,
            'opened_at' => $openedAt instanceof CarbonInterface ? $openedAt->toAtomString() : null,
            'closed_at' => $closedAt instanceof CarbonInterface ? $closedAt->toAtomString() : null,
            'opening_declaration_minor' => $session->getAttribute('opening_declaration_minor'),
            'closing_declaration_minor' => $session->getAttribute('closing_declaration_minor'),
            'currency' => $session->getAttribute('currency'),
            'status' => $session->getAttribute('status'),
            'summary' => $this->summaryPayload($summary),
            'created_at' => $createdAt instanceof CarbonInterface ? $createdAt->toAtomString() : null,
            'updated_at' => $updatedAt instanceof CarbonInterface ? $updatedAt->toAtomString() : null,
        ];
    }

    /**
     * @return array{
     *     deposits_total_minor: int,
     *     withdrawals_total_minor: int,
     *     manual_journals_total_minor: int,
     *     reversals_total_minor: int,
     *     transaction_count: int,
     *     posted_transaction_count: int,
     *     pending_transaction_count: int,
     *     expected_cash_balance_minor: int,
     *     last_transaction_at: string|null
     * }|null
     */
    private function summaryPayload(mixed $summary): ?array
    {
        if (! is_array($summary)) {
            return null;
        }

        return [
            'deposits_total_minor' => $this->summaryInt($summary, 'deposits_total_minor'),
            'withdrawals_total_minor' => $this->summaryInt($summary, 'withdrawals_total_minor'),
            'manual_journals_total_minor' => $this->summaryInt($summary, 'manual_journals_total_minor'),
            'reversals_total_minor' => $this->summaryInt($summary, 'reversals_total_minor'),
            'transaction_count' => $this->summaryInt($summary, 'transaction_count'),
            'posted_transaction_count' => $this->summaryInt($summary, 'posted_transaction_count'),
            'pending_transaction_count' => $this->summaryInt($summary, 'pending_transaction_count'),
            'expected_cash_balance_minor' => $this->summaryInt($summary, 'expected_cash_balance_minor'),
            'last_transaction_at' => is_string($summary['last_transaction_at'] ?? null) ? $summary['last_transaction_at'] : null,
        ];
    }

    /**
     * @param  array<mixed, mixed>  $summary
     */
    private function summaryInt(array $summary, string $key): int
    {
        $value = $summary[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
