<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExerciseClosing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExerciseClosing
 */
final class ExerciseClosingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ExerciseClosing $closing */
        $closing = $this->resource;

        return [
            'public_id' => $closing->public_id,
            'agency_public_id' => $closing->relationLoaded('agency') ? $closing->agency?->public_id : null,
            'fiscal_year' => $closing->fiscal_year,
            'opens_on' => $closing->opens_on->toDateString(),
            'closes_on' => $closing->closes_on->toDateString(),
            'currency' => $closing->currency,
            'net_result_minor' => $closing->net_result_minor,
            'result_account_code' => $closing->result_account_code,
            // The state of the entry that performs the transfer, not a copy of it:
            // a closing whose entry is still awaiting review has not moved
            // anything yet, and saying otherwise would be the worst kind of wrong.
            'status' => $closing->status(),
            'posted' => $closing->isPosted(),
            'journal_entry_public_id' => $closing->relationLoaded('journalEntry') ? $closing->journalEntry?->public_id : null,
        ];
    }
}
