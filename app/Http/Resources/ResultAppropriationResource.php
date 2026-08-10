<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ResultAppropriation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ResultAppropriation
 */
final class ResultAppropriationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ResultAppropriation $appropriation */
        $appropriation = $this->resource;

        return [
            'public_id' => $appropriation->public_id,
            'agency_public_id' => $appropriation->relationLoaded('agency') ? $appropriation->agency?->public_id : null,
            'fiscal_year' => $appropriation->fiscal_year,
            'currency' => $appropriation->currency,
            'source_account_code' => $appropriation->source_account_code,
            'amount_minor' => $appropriation->amount_minor,
            'decided_on' => $appropriation->decided_on->toDateString(),
            'status' => $appropriation->status(),
            'posted' => $appropriation->isPosted(),
            'journal_entry_public_id' => $appropriation->relationLoaded('journalEntry') ? $appropriation->journalEntry?->public_id : null,
        ];
    }
}
