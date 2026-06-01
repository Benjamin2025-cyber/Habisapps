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

        return [
            'public_id' => $session->public_id,
            'agency_public_id' => $session->relationLoaded('agency') ? $session->agency?->public_id : null,
            'accounting_day_public_id' => $session->relationLoaded('accountingDay') ? $session->accountingDay?->public_id : null,
            'till_public_id' => $session->relationLoaded('till') ? $session->till?->public_id : null,
            'teller_user_public_id' => $session->relationLoaded('teller') ? $session->teller?->public_id : null,
            'business_date' => $businessDate instanceof CarbonInterface ? $businessDate->toDateString() : null,
            'opened_at' => $openedAt instanceof CarbonInterface ? $openedAt->toAtomString() : null,
            'closed_at' => $closedAt instanceof CarbonInterface ? $closedAt->toAtomString() : null,
            'opening_declaration_minor' => $session->opening_declaration_minor,
            'closing_declaration_minor' => $session->closing_declaration_minor,
            'currency' => $session->currency,
            'status' => $session->status,
            'created_at' => $session->created_at?->toAtomString(),
            'updated_at' => $session->updated_at?->toAtomString(),
        ];
    }
}
