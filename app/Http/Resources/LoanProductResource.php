<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanProduct;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanProduct
 */
final class LoanProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var LoanProduct $product */
        $product = $this->resource;

        return [
            'public_id' => $product->public_id,
            'ledger_account_public_id' => $product->relationLoaded('ledgerAccount') ? $product->ledgerAccount?->public_id : null,
            'code' => $product->code,
            'name' => $product->name,
            'status' => $product->status,
            'min_term_count' => $product->min_term_count,
            'max_term_count' => $product->max_term_count,
            'term_unit' => $product->term_unit,
            'allowed_repayment_frequencies' => $product->allowed_repayment_frequencies,
            'requires_guarantor' => $product->requires_guarantor,
            'requires_collateral' => $product->requires_collateral,
            'interest_policy_key' => $product->interest_policy_key,
            'penalty_policy_key' => $product->penalty_policy_key,
            'repayment_allocation_policy_key' => $product->repayment_allocation_policy_key,
            'fee_policy_key' => $product->fee_policy_key,
            'min_amount_minor' => $product->min_amount_minor,
            'max_amount_minor' => $product->max_amount_minor,
            'due_date_day' => $product->due_date_day,
            'penalty_grace_days' => $product->penalty_grace_days,
            'min_grace_period_days' => $product->min_grace_period_days,
            'max_grace_period_days' => $product->max_grace_period_days,
            'interest_rate' => $product->interest_rate,
            'tax_rate' => $product->tax_rate,
            'insurance_rate' => $product->insurance_rate,
            'fee_amount_minor' => $product->fee_amount_minor,
            'floor_amount_minor' => $product->floor_amount_minor,
            'tax_policy_key' => $product->tax_policy_key,
            'insurance_policy_key' => $product->insurance_policy_key,
            'guarantee_deposit_policy_key' => $product->guarantee_deposit_policy_key,
            'guarantee_deposit_type' => $product->guarantee_deposit_type,
            'guarantee_deposit_value' => $product->guarantee_deposit_value,
            'penalty_formula_type' => $product->penalty_formula_type,
            'penalty_formula_base' => $product->penalty_formula_base,
            'penalty_value_type' => $product->penalty_value_type,
            'penalty_value' => $product->penalty_value,
            'operation_type' => $product->operation_type,
            'constant_value' => $product->constant_value,
            'rules' => $product->rules,
            'created_at' => $product->created_at?->toAtomString(),
            'updated_at' => $product->updated_at?->toAtomString(),
        ];
    }
}
