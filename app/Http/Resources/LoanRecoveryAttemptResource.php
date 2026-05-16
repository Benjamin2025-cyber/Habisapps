<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanRecoveryAttempt;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LoanRecoveryAttempt */
final class LoanRecoveryAttemptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attempt = $this->resource;
        if (! $attempt instanceof LoanRecoveryAttempt) {
            return [];
        }

        return [
            'public_id' => $attempt->public_id,
            'loan_public_id' => $attempt->loan?->public_id,
            'customer_account_public_id' => $attempt->customerAccount?->public_id,
            'loan_recovery_account_public_id' => $attempt->recoveryAccount?->public_id,
            'journal_entry_public_id' => $attempt->journalEntry?->public_id,
            'requested_amount_minor' => $attempt->requested_amount_minor,
            'recovered_amount_minor' => $attempt->recovered_amount_minor,
            'currency' => $attempt->currency,
            'status' => $attempt->status,
            'attempted_at' => $this->formatDate($attempt->attempted_at),
            'failure_reason' => $attempt->failure_reason,
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
