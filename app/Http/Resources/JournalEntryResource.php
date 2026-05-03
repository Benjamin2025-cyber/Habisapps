<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin JournalEntry
 */
final class JournalEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var JournalEntry $entry */
        $entry = $this->resource;

        return [
            'public_id' => $entry->public_id,
            'reference' => $entry->reference,
            'business_date' => $entry->business_date,
            'posted_at' => $entry->posted_at !== null ? Carbon::parse($entry->posted_at)->toAtomString() : null,
            'agency_public_id' => $entry->relationLoaded('agency') ? $entry->agency?->public_id : null,
            'source_module' => $entry->source_module,
            'source_type' => $entry->source_type,
            'source_public_id' => $entry->source_public_id,
            'status' => $entry->status,
            'description' => $entry->description,
            'reversal_of_public_id' => $entry->relationLoaded('reversalOf') ? $entry->reversalOf?->public_id : null,
            'lines' => JournalLineResource::collection($entry->relationLoaded('lines') ? $entry->lines : []),
            'created_at' => $entry->created_at?->toAtomString(),
            'updated_at' => $entry->updated_at?->toAtomString(),
        ];
    }
}
