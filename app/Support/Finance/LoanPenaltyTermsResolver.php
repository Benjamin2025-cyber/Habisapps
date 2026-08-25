<?php

declare(strict_types=1);

namespace App\Support\Finance;

/**
 * Resolves the penalty terms the arrears engine applies for a loan.
 *
 * The accounting team's formula is hybrid and universal — « le même principe
 * pour tous les crédits »: a fixed 5 000 FCFA part plus a variable part of
 * 2% of the unpaid amount, applied together on every overdue installment.
 * Nothing about it is configurable per product or captured per loan, so there
 * is no snapshot/product precedence chain anymore; the values come from the
 * approved `penalties_and_arrears` formula-policy config, with the same
 * constants hardcoded as fail-safe defaults.
 */
final class LoanPenaltyTermsResolver
{
    /** 5 000 FCFA at the account scale (`money.default_scale`, 2). */
    private const int DEFAULT_FIXED_AMOUNT_MINOR = 500000;

    private const string DEFAULT_VARIABLE_RATE_PERCENT = '2';

    public function resolve(): ResolvedPenaltyTerms
    {
        return new ResolvedPenaltyTerms(
            $this->configInt(
                'formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.fixed_amount_minor',
                self::DEFAULT_FIXED_AMOUNT_MINOR,
            ),
            $this->configRate(
                'formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.variable_rate_percent',
                self::DEFAULT_VARIABLE_RATE_PERCENT,
            ),
            // The config base ("unpaid_scheduled_due_excluding_prior_penalties")
            // maps to the still-unpaid scheduled due of the installment.
            'unpaid_scheduled_due',
            ResolvedPenaltyTerms::SOURCE_CONFIG,
        );
    }

    private function configInt(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }

    private function configRate(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
