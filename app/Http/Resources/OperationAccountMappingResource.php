<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\OperationAccountMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OperationAccountMapping
 */
final class OperationAccountMappingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OperationAccountMapping $mapping */
        $mapping = $this->resource;

        return [
            'public_id' => $mapping->public_id,
            'operation_code_public_id' => $mapping->relationLoaded('operationCode') ? $mapping->operationCode?->public_id : null,
            'debit_ledger_account_public_id' => $mapping->relationLoaded('debitLedgerAccount') ? $mapping->debitLedgerAccount?->public_id : null,
            'credit_ledger_account_public_id' => $mapping->relationLoaded('creditLedgerAccount') ? $mapping->creditLedgerAccount?->public_id : null,
            'currency' => $mapping->currency,
            'status' => $mapping->status,
            'rules' => $mapping->rules,
            'created_at' => $mapping->created_at?->toAtomString(),
            'updated_at' => $mapping->updated_at?->toAtomString(),
        ];
    }
}
