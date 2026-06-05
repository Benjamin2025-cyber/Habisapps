<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesLoanProductPenaltyTerms;
use App\Models\LoanProduct;
use App\Models\User;
use App\Support\Finance\FormulaPolicyKey;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateLoanProductRequest extends FormRequest
{
    use ValidatesLoanProductPenaltyTerms;

    public function authorize(): bool
    {
        $user = $this->user();
        $loanProduct = $this->route('loanProduct');

        return $user instanceof User
            && $loanProduct instanceof LoanProduct
            && $user->can('update', $loanProduct);
    }

    public function withValidator(Validator $validator): void
    {
        $loanProduct = $this->route('loanProduct');
        $existing = $loanProduct instanceof LoanProduct ? [
            'penalty_value_type' => $loanProduct->penalty_value_type,
            'penalty_value' => $loanProduct->penalty_value,
            'penalty_formula_base' => $loanProduct->penalty_formula_base,
            'penalty_formula_type' => $loanProduct->penalty_formula_type,
        ] : [];

        $validator->after(function (Validator $validator) use ($existing): void {
            $this->validatePenaltyTerms($validator, $existing);
        });
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $loanProduct = $this->route('loanProduct');
        $ignoreId = $loanProduct instanceof LoanProduct ? $loanProduct->id : null;

        return [
            'ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'code' => ['sometimes', 'string', 'max:64', Rule::unique('loan_products', 'code')->ignore($ignoreId)],
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in([LoanProduct::STATUS_ACTIVE, LoanProduct::STATUS_INACTIVE, LoanProduct::STATUS_ARCHIVED])],
            'min_term_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_term_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'term_unit' => ['sometimes', 'nullable', Rule::in([LoanProduct::TERM_UNIT_DAY, LoanProduct::TERM_UNIT_WEEK, LoanProduct::TERM_UNIT_MONTH])],
            'allowed_repayment_frequencies' => ['sometimes', 'nullable', 'array'],
            'allowed_repayment_frequencies.*' => ['string', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'requires_guarantor' => ['sometimes', 'boolean'],
            'requires_collateral' => ['sometimes', 'boolean'],
            'interest_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::LoanInterestMethod->value])],
            'penalty_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::PenaltiesAndArrears->value])],
            'repayment_allocation_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::RepaymentAllocationOrder->value])],
            'fee_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::FeesTaxesInsurance->value])],
            'min_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'max_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'due_date_day' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'penalty_grace_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'min_grace_period_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_grace_period_days' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'interest_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'insurance_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fee_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'floor_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tax_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::FeesTaxesInsurance->value])],
            'insurance_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::FeesTaxesInsurance->value])],
            'guarantee_deposit_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::FeesTaxesInsurance->value])],
            'guarantee_deposit_type' => ['sometimes', 'nullable', Rule::in(['percentage', 'fixed'])],
            'guarantee_deposit_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'penalty_formula_type' => ['sometimes', 'nullable', 'string', Rule::in(LoanProduct::PENALTY_FORMULA_TYPES)],
            'penalty_formula_base' => ['sometimes', 'nullable', 'string', Rule::in(LoanProduct::PENALTY_FORMULA_BASES)],
            'penalty_value_type' => ['sometimes', 'nullable', 'string', Rule::in(LoanProduct::PENALTY_VALUE_TYPES)],
            'penalty_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'operation_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'constant_value' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.formula_policies' => ['sometimes', 'array'],
            'rules.formula_policies.rounding_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::XafRounding->value])],
            'rules.formula_policies.schedule_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::LoanInstallmentAmount->value])],
            'rules.formula_policies.reporting_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::PortfolioReportingMetrics->value])],
            'rules.*' => ['nullable'],
        ];
    }
}
