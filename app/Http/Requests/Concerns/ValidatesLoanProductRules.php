<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\LoanProduct;
use App\Support\Finance\FormulaPolicyKey;
use Illuminate\Validation\Rule;

/**
 * Validation for the `rules` JSON bag on a loan product.
 *
 * Two of its sub-keys are not decoration: `installment_charges` decides whether
 * a component is collected upfront or spread into the schedule, and
 * `formula_policies` names the calculation policies the engine gates on. Both
 * are read by value, so a wrong-typed or misspelled *value* reads as "not
 * configured" at calculation time — configured on the form, inert in the maths.
 *
 * Unknown *keys* are already rejected: AppServiceProvider enables
 * `FormRequest::failOnUnknownFields()`, so `installment_charges.taxes` comes
 * back as prohibited without any help from here. What this adds is the two
 * things that guard misses — the containers must be arrays rather than scalars,
 * and the values must be ones the readers actually honour.
 */
trait ValidatesLoanProductRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function loanProductRulesValidation(): array
    {
        $componentRule = ['sometimes', 'nullable', Rule::in(LoanProduct::INSTALLMENT_CHARGE_POLICIES)];

        return [
            'rules' => ['sometimes', 'nullable', 'array'],
            'rules.installment_charges' => ['sometimes', 'nullable', 'array'],
            'rules.installment_charges.fees' => $componentRule,
            'rules.installment_charges.tax' => $componentRule,
            'rules.installment_charges.insurance' => $componentRule,
            'rules.formula_policies' => ['sometimes', 'nullable', 'array'],
            'rules.formula_policies.rounding_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::XafRounding->value])],
            'rules.formula_policies.schedule_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::LoanInstallmentAmount->value])],
            'rules.formula_policies.reporting_policy_key' => ['sometimes', 'nullable', Rule::in([FormulaPolicyKey::PortfolioReportingMetrics->value])],
            'rules.*' => ['nullable'],
        ];
    }
}
