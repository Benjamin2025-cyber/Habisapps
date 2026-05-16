<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ReportRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin ReportRun
 */
final class ReportRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReportRun $run */
        $run = $this->resource;

        return [
            'public_id' => $run->public_id,
            'report_definition_public_id' => $run->relationLoaded('reportDefinition') ? $run->reportDefinition?->public_id : null,
            'agency_public_id' => $run->relationLoaded('agency') ? $run->agency?->public_id : null,
            'document_public_id' => $run->relationLoaded('document') ? $run->document?->public_id : null,
            'period_starts_on' => $run->period_starts_on !== null ? Carbon::parse($run->period_starts_on)->toDateString() : null,
            'period_ends_on' => $run->period_ends_on !== null ? Carbon::parse($run->period_ends_on)->toDateString() : null,
            'status' => $run->status,
            'generated_at' => $run->generated_at !== null ? Carbon::parse($run->generated_at)->toAtomString() : null,
            'parameters' => $run->parameters,
            'summary' => $run->summary,
            'created_at' => $run->created_at?->toAtomString(),
            'updated_at' => $run->updated_at?->toAtomString(),
        ];
    }
}
