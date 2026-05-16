<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DelinquencyTracking;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DelinquencyTracking */
final class DelinquencyTrackingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tracking = $this->resource;
        if (! $tracking instanceof DelinquencyTracking) {
            return [
                'public_id' => null,
                'client_public_id' => null,
                'loan_public_id' => null,
                'agency_public_id' => null,
                'tracking_date' => null,
                'reason_code' => null,
                'appointment_type' => null,
                'appointment_date' => null,
                'promised_amount_minor' => null,
                'currency' => null,
                'comments' => null,
                'created_by_user_public_id' => null,
                'created_at' => null,
                'updated_at' => null,
            ];
        }

        return [
            'public_id' => $tracking->public_id,
            'client_public_id' => $tracking->client?->public_id,
            'loan_public_id' => $tracking->loan?->public_id,
            'agency_public_id' => $tracking->agency?->public_id,
            'tracking_date' => $this->formatDateOnly($tracking->tracking_date),
            'reason_code' => $tracking->reason_code,
            'appointment_type' => $tracking->appointment_type,
            'appointment_date' => $this->formatDateOnly($tracking->appointment_date),
            'promised_amount_minor' => $tracking->promised_amount_minor,
            'currency' => $tracking->currency,
            'comments' => $tracking->comments,
            'created_by_user_public_id' => $tracking->createdBy?->public_id,
            'created_at' => $tracking->created_at?->toISOString(),
            'updated_at' => $tracking->updated_at?->toISOString(),
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
