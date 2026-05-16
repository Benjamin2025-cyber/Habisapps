<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanTransfer;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LoanTransfer */
final class LoanTransferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $transfer = $this->resource;
        if (! $transfer instanceof LoanTransfer) {
            return [
                'public_id' => null,
                'agency_public_id' => null,
                'loan_public_id' => null,
                'initial_manager_public_id' => null,
                'new_manager_public_id' => null,
                'transfer_reason' => null,
                'transfer_date' => null,
                'approved_by_user_public_id' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return [
            'public_id' => $transfer->public_id,
            'agency_public_id' => $transfer->agency?->public_id,
            'loan_public_id' => $transfer->loan?->public_id,
            'initial_manager_public_id' => $transfer->initialManager?->public_id,
            'new_manager_public_id' => $transfer->newManager?->public_id,
            'transfer_reason' => $transfer->transfer_reason,
            'transfer_date' => $this->formatDateOnly($transfer->transfer_date),
            'approved_by_user_public_id' => $transfer->approvedBy?->public_id,
            'created_at' => $transfer->created_at?->toISOString(),
            'updated_at' => $transfer->updated_at?->toISOString(),
        ];
    }

    private function formatDateOnly(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
