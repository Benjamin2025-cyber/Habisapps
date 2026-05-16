<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\EmfLedgerAccountMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmfLedgerAccountMapping
 */
final class EmfLedgerAccountMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmfLedgerAccountMapping $mapping */
        $mapping = $this->resource;
        $ledgerAccount = $mapping->relationLoaded('ledgerAccount') ? $mapping->ledgerAccount : null;
        $ledgerAccountAgencyPublicId = null;

        if ($ledgerAccount !== null && $ledgerAccount->relationLoaded('agency')) {
            $ledgerAccountAgencyPublicId = $ledgerAccount->agency?->public_id;
        }

        return [
            'public_id' => $mapping->public_id,
            'emf_regulatory_account_public_id' => $mapping->relationLoaded('emfRegulatoryAccount')
                ? $mapping->emfRegulatoryAccount?->public_id
                : null,
            'ledger_account_public_id' => $ledgerAccount?->public_id,
            'ledger_account_agency_public_id' => $ledgerAccountAgencyPublicId,
            'status' => $mapping->status,
            'created_at' => $mapping->created_at?->toAtomString(),
            'updated_at' => $mapping->updated_at?->toAtomString(),
        ];
    }
}
