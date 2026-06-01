<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccountingDay;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AccountingDay
 */
final class AccountingDayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AccountingDay $day */
        $day = $this->resource;
        $businessDate = $day->getAttribute('business_date');
        $openedAt = $day->getAttribute('calendar_opened_at');
        $closedAt = $day->getAttribute('calendar_closed_at');
        $canViewReopenReason = $request->user()?->can('reopen', $day) === true;

        return [
            'public_id' => $day->public_id,
            'scope' => $day->scope_type,
            'agency_public_id' => $day->relationLoaded('agency') ? $day->agency?->public_id : null,
            'business_date' => $businessDate instanceof CarbonInterface ? $businessDate->toDateString() : null,
            'status' => $day->status,
            'can_register' => $day->allowsRegistration(),
            'is_holiday' => $day->is_holiday,
            'holiday_name' => $day->holiday_name,
            'origin' => $day->origin,
            'calendar_opened_at' => $openedAt instanceof CarbonInterface ? $openedAt->toAtomString() : null,
            'calendar_closed_at' => $closedAt instanceof CarbonInterface ? $closedAt->toAtomString() : null,
            'opened_by_public_id' => $day->relationLoaded('openedBy') ? $day->openedBy?->public_id : null,
            'closed_by_public_id' => $day->relationLoaded('closedBy') ? $day->closedBy?->public_id : null,
            'reopened_by_public_id' => $day->relationLoaded('reopenedBy') ? $day->reopenedBy?->public_id : null,
            'close_summary' => $day->close_summary_payload,
            'close_failure_reason' => $day->close_failure_reason,
            'reopen_reason' => $canViewReopenReason ? $day->reopen_reason : null,
            'created_at' => $day->created_at?->toAtomString(),
            'updated_at' => $day->updated_at?->toAtomString(),
        ];
    }
}
