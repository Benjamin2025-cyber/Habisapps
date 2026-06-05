<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\LoanProduct;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Resolves the penalty terms the arrears engine applies for a loan.
 *
 * Source precedence (so historical loans are not changed by later product edits):
 *   1. the loan's `formula_policy_snapshot.product_terms` captured at creation,
 *   2. the current loan product fields,
 *   3. the approved global `penalties_and_arrears` formula-policy config.
 *
 * Product penalty-field semantics (validated by loan-product requests):
 *  - `penalty_value_type`:
 *      - `amount`     → `penalty_value` is a flat money amount in MINOR units
 *                       (the decimal column is rounded HALF_UP to whole minor
 *                       units); no percentage component.
 *      - `percentage` → `penalty_value` is a percentage rate applied to the
 *                       selected `penalty_formula_base`; no fixed component.
 *  - `penalty_formula_type` (descriptive shape, must agree with value type):
 *      - `fixed`                    ⇔ value type `amount`.
 *      - `flat_rate` / `percentage` ⇔ value type `percentage` (a flat percent
 *                                     of the base each penalty period).
 *      - `variable_rate`            ⇔ value type `percentage`; for v1 it is
 *                                     applied as a flat percent of the base each
 *                                     period (no time-varying multiplier yet).
 *  - `penalty_formula_base` (what a percentage applies to):
 *      - `unpaid_scheduled_due` → the still-unpaid scheduled due of the
 *                                 installment, excluding prior penalties
 *                                 (scheduled due minus allocated payments).
 *                                 This is the global-config default base.
 *      - `overdue_amount`       → the gross scheduled due of the installment
 *                                 that fell overdue, before crediting partial
 *                                 payments.
 *      - `principal`            → the installment principal.
 *      - `outstanding_principal`→ the remaining principal carried on the line.
 */
final class LoanPenaltyTermsResolver
{
    /**
     * @param  array<array-key, mixed>|null  $snapshotProductTerms
     */
    public function resolve(?array $snapshotProductTerms, ?LoanProduct $product): ResolvedPenaltyTerms
    {
        $fromSnapshot = $this->fromTerms($snapshotProductTerms, ResolvedPenaltyTerms::SOURCE_SNAPSHOT);
        if ($fromSnapshot instanceof ResolvedPenaltyTerms) {
            return $fromSnapshot;
        }

        if ($product instanceof LoanProduct) {
            $fromProduct = $this->fromTerms($this->productTerms($product), ResolvedPenaltyTerms::SOURCE_PRODUCT);
            if ($fromProduct instanceof ResolvedPenaltyTerms) {
                return $fromProduct;
            }
        }

        return $this->fromConfig();
    }

    /**
     * @param  array<array-key, mixed>|null  $terms
     */
    private function fromTerms(?array $terms, string $source): ?ResolvedPenaltyTerms
    {
        if ($terms === null) {
            return null;
        }

        $valueType = $terms['penalty_value_type'] ?? null;
        $rawValue = $terms['penalty_value'] ?? null;

        if (! is_string($valueType) || $valueType === '' || $rawValue === null || $rawValue === '') {
            return null;
        }

        $baseValue = $terms['penalty_formula_base'] ?? null;
        $base = is_string($baseValue) && $baseValue !== ''
            ? $baseValue
            : 'unpaid_scheduled_due';

        if ($valueType === 'amount') {
            return new ResolvedPenaltyTerms($this->toMinorAmount($rawValue), '0', $base, $source);
        }

        if ($valueType === 'percentage') {
            return new ResolvedPenaltyTerms(0, $this->toRate($rawValue), $base, $source);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function productTerms(LoanProduct $product): array
    {
        return [
            'penalty_value_type' => $product->penalty_value_type,
            'penalty_value' => $product->penalty_value,
            'penalty_formula_base' => $product->penalty_formula_base,
            'penalty_formula_type' => $product->penalty_formula_type,
        ];
    }

    private function fromConfig(): ResolvedPenaltyTerms
    {
        $fixed = config('formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.fixed_amount_minor', 5000);
        $rate = config('formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.variable_rate_percent', '2');

        return new ResolvedPenaltyTerms(
            is_int($fixed) ? $fixed : 5000,
            is_string($rate) && $rate !== '' ? $rate : '2',
            // The config base ("unpaid_scheduled_due_excluding_prior_penalties")
            // maps to the still-unpaid scheduled due of the installment.
            'unpaid_scheduled_due',
            ResolvedPenaltyTerms::SOURCE_CONFIG,
        );
    }

    private function toMinorAmount(mixed $value): int
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return 0;
        }

        try {
            return BigDecimal::of($value)
                ->toScale(0, RoundingMode::HALF_UP)
                ->toInt();
        } catch (MathException) {
            return 0;
        }
    }

    private function toRate(mixed $value): string
    {
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return '0';
        }

        try {
            return (string) BigDecimal::of($value);
        } catch (MathException) {
            return '0';
        }
    }
}
