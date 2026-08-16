<?php

declare(strict_types=1);

namespace App\Support\Loans;

use App\Models\LoanProduct;

/**
 * A loan product carries three pairs of bounds — amount, term count and grace
 * period. Only the amount pair was ever checked against the loan being written,
 * so a product offering 6 to 24 instalments accepted a loan of 60 without
 * complaint. The amount bound refuses loudly; the other two accepted silently,
 * which is the worse failure: nobody reports a loan that was created.
 *
 * The rule lives here because two paths write these fields — creating or
 * updating a loan, and rescheduling one — and a bound enforced on only one of
 * them is a bound that can be walked around.
 */
final class LoanProductBounds
{
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, array<int, string>>
     */
    public static function termErrors(LoanProduct $product, array $values): array
    {
        $errors = [];

        $installments = self::intOrNull($values, 'number_of_installments');
        if ($installments !== null) {
            if ($product->min_term_count !== null && $installments < $product->min_term_count) {
                $errors['number_of_installments'] = [(string) __('Number of instalments is below the loan product minimum term.')];
            }

            if ($product->max_term_count !== null && $installments > $product->max_term_count) {
                $errors['number_of_installments'] = [(string) __('Number of instalments exceeds the loan product maximum term.')];
            }
        }

        $grace = self::intOrNull($values, 'grace_period_duration');
        if ($grace !== null) {
            if ($product->min_grace_period_days !== null && $grace < $product->min_grace_period_days) {
                $errors['grace_period_duration'] = [(string) __('Grace period is below the loan product minimum.')];
            }

            if ($product->max_grace_period_days !== null && $grace > $product->max_grace_period_days) {
                $errors['grace_period_duration'] = [(string) __('Grace period exceeds the loan product maximum.')];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function intOrNull(array $values, string $key): ?int
    {
        if (! array_key_exists($key, $values)) {
            return null;
        }

        $value = $values[$key];

        return is_numeric($value) ? (int) $value : null;
    }
}
