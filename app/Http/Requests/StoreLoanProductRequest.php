<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesLoanProductRules;
use App\Models\LoanProduct;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreLoanProductRequest extends FormRequest
{
    use ValidatesLoanProductRules;

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('create', LoanProduct::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64', Rule::unique('loan_products', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([LoanProduct::STATUS_ACTIVE, LoanProduct::STATUS_INACTIVE])],
            'min_term_count' => ['nullable', 'integer', 'min:1'],
            'max_term_count' => ['nullable', 'integer', 'min:1', 'gte:min_term_count'],
            'term_unit' => ['nullable', Rule::in([LoanProduct::TERM_UNIT_DAY, LoanProduct::TERM_UNIT_WEEK, LoanProduct::TERM_UNIT_MONTH])],
            'allowed_repayment_frequencies' => ['nullable', 'array'],
            'allowed_repayment_frequencies.*' => ['string', Rule::in(['daily', 'weekly', 'monthly', 'custom'])],
            'requires_guarantor' => ['nullable', 'boolean'],
            'requires_collateral' => ['nullable', 'boolean'],
            'min_amount_minor' => ['nullable', 'integer', 'min:1'],
            'max_amount_minor' => ['nullable', 'integer', 'min:1', 'gte:min_amount_minor'],
            'due_date_day' => ['nullable', 'integer', 'between:1,31'],
            'penalty_grace_days' => ['nullable', 'integer', 'min:0'],
            'min_grace_period_days' => ['nullable', 'integer', 'min:0'],
            'max_grace_period_days' => ['nullable', 'integer', 'min:0', 'gte:min_grace_period_days'],
            'interest_rate' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0'],
            'insurance_rate' => ['nullable', 'numeric', 'min:0'],
            'fee_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'dossier_fee_tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'guarantee_deposit_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'guarantee_deposit_value' => ['nullable', 'numeric', 'min:0'],
            'operation_type' => ['nullable', 'string', 'max:64'],
            'constant_value' => ['nullable', 'numeric', 'min:0'],
            ...$this->loanProductRulesValidation(),
        ];
    }
}
