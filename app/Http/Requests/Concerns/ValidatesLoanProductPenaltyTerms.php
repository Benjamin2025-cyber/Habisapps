<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Support\Finance\LoanPenaltyTermsResolver;
use App\Support\Finance\LoanProductPenaltyTermsValidator;
use Illuminate\Contracts\Validation\Validator;

/**
 * Cross-field validation for loan-product penalty configuration.
 *
 * Penalty fields are individually nullable, but if any one of them is provided
 * the group must form a coherent, complete combination so the arrears engine
 * (via {@see LoanPenaltyTermsResolver}) can interpret it.
 */
trait ValidatesLoanProductPenaltyTerms
{
    private const PENALTY_FIELDS = ['penalty_value_type', 'penalty_value', 'penalty_formula_base', 'penalty_formula_type'];

    /**
     * @param  array<string, mixed>  $existing  current persisted values, used so
     *                                          partial updates validate the
     *                                          effective (merged) combination.
     */
    protected function validatePenaltyTerms(Validator $validator, array $existing = []): void
    {
        $input = $this->all();

        // Only enforce coherence when this request actually submits a penalty
        // field. A legacy product may carry partial penalty data from before
        // these rules existed; an update that never touches penalty config must
        // not be rejected for that pre-existing state.
        $touchesPenalty = false;
        foreach (self::PENALTY_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $touchesPenalty = true;
                break;
            }
        }
        if (! $touchesPenalty) {
            return;
        }

        $effective = static function (string $key) use ($input, $existing): mixed {
            if (array_key_exists($key, $input)) {
                return $input[$key];
            }

            return $existing[$key] ?? null;
        };

        $errors = LoanProductPenaltyTermsValidator::errors([
            'penalty_value_type' => $effective('penalty_value_type'),
            'penalty_value' => $effective('penalty_value'),
            'penalty_formula_base' => $effective('penalty_formula_base'),
            'penalty_formula_type' => $effective('penalty_formula_type'),
        ]);

        foreach ($errors as $field => $messages) {
            foreach ($messages as $message) {
                $validator->errors()->add($field, $message);
            }
        }
    }
}
