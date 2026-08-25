<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\LoanProduct;
use Illuminate\Validation\Rule;

/**
 * Validation for the `rules` JSON bag on a loan product.
 *
 * One of its sub-keys is not decoration: `installment_charges` decides whether
 * a component is collected upfront or spread into the schedule. It is read by
 * value, so a wrong-typed or misspelled *value* reads as "not configured" at
 * calculation time — configured on the form, inert in the maths.
 *
 * The calculation policies (`rules.formula_policies`, and the policy-key
 * columns) are deliberately absent: they are the same for every credit —
 * « les politiques de calcul rattachées ne sont plus à sélectionner vu qu'elles
 * sont les mêmes pour tous les crédits » — so the model imposes them on every
 * save and the request layer does not accept them at all. With
 * `FormRequest::failOnUnknownFields()` enabled, sending them comes back as
 * prohibited instead of being silently overwritten.
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
            'rules.*' => ['nullable'],
        ];
    }
}
