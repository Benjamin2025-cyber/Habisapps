<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TillReconciliation;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TillReconciliation
 */
final class TillReconciliationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TillReconciliation $reconciliation */
        $reconciliation = $this->resource;
        $countedAt = $reconciliation->getAttribute('counted_at');
        $reconciliationDate = $reconciliation->getAttribute('reconciliation_date');

        return [
            'public_id' => $reconciliation->public_id,
            'teller_session_public_id' => $reconciliation->relationLoaded('tellerSession') ? $reconciliation->tellerSession?->public_id : null,
            'counted_by_user_public_id' => $reconciliation->relationLoaded('countedBy') ? $reconciliation->countedBy?->public_id : null,
            'counted_at' => $countedAt instanceof CarbonInterface ? $countedAt->toAtomString() : null,
            'reconciliation_date' => $reconciliationDate instanceof CarbonInterface ? $reconciliationDate->toAtomString() : null,
            'theoretical_balance_minor' => $reconciliation->theoretical_balance_minor,
            'actual_balance_minor' => $reconciliation->actual_balance_minor,
            'difference_minor' => $reconciliation->difference_minor,
            'currency' => $reconciliation->currency,
            'status' => $reconciliation->status,
            'notes' => $reconciliation->notes,
            'lines' => $reconciliation->relationLoaded('lines') ? $reconciliation->lines->map(static function ($line): array {
                return [
                    'denomination_public_id' => $line->relationLoaded('denomination') ? $line->denomination?->public_id : null,
                    'count' => $line->count,
                    'declared_amount_minor' => $line->declared_amount_minor,
                ];
            })->values()->all() : [],
            'created_at' => $reconciliation->created_at?->toAtomString(),
            'updated_at' => $reconciliation->updated_at?->toAtomString(),
        ];
    }
}
