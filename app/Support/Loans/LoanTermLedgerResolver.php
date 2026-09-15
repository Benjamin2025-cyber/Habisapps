<?php

declare(strict_types=1);

namespace App\Support\Loans;

use App\Models\LedgerAccount;
use App\Models\LoanProduct;
use Carbon\CarbonImmutable;

/**
 * Which general-ledger account a loan's own account belongs to, from
 * CORRECTIONS HABISLOAN 04/09/2026:
 *
 *   3282  crédits à court terme   (0 à 24 mois)
 *   3181  crédits à moyen terme   (24 à 84 mois)
 *   3081  crédits à long terme    (plus de 84 mois)
 *
 * The bands as written overlap at 24 and at 84. Read as COBAC does — court
 * terme up to and including 24 months, moyen terme above 24 up to and
 * including 84, long terme beyond — which is also the only reading under which
 * every loan lands in exactly one family.
 *
 * The term is measured in whole months between the loan's base date and its
 * last instalment, stepped by the product's own periodicity rather than by
 * dividing a day count: a 24-instalment monthly loan spans 730 days, and
 * 730 / 30 is 24.3, which would file a court terme loan as moyen terme.
 */
final class LoanTermLedgerResolver
{
    public const string COURT_TERME = '3282';

    public const string MOYEN_TERME = '3181';

    public const string LONG_TERME = '3081';

    private const int COURT_TERME_MAX_MONTHS = 24;

    private const int MOYEN_TERME_MAX_MONTHS = 84;

    /**
     * @param  int  $installments  The loan's number of instalments.
     */
    public function ledgerCodeFor(LoanProduct $product, int $installments, CarbonImmutable $base): string
    {
        return self::ledgerCodeForMonths($this->termInMonths($product, $installments, $base));
    }

    public static function ledgerCodeForMonths(int $months): string
    {
        if ($months <= self::COURT_TERME_MAX_MONTHS) {
            return self::COURT_TERME;
        }

        if ($months <= self::MOYEN_TERME_MAX_MONTHS) {
            return self::MOYEN_TERME;
        }

        return self::LONG_TERME;
    }

    /**
     * Whole months from the base date to the last instalment. Rounded up,
     * because a term of 24 months and one day is no longer court terme.
     */
    public function termInMonths(LoanProduct $product, int $installments, CarbonImmutable $base): int
    {
        $maturity = $product->addTerms($base, max(1, $installments));
        $months = (int) $base->diffInMonths($maturity, absolute: true);

        // diffInMonths truncates, so a maturity part-way through a month counts
        // as the month it falls in.
        return $base->addMonthsNoOverflow($months)->lt($maturity) ? $months + 1 : $months;
    }

    /**
     * The account that actually receives the postings: the agency's own detail
     * account under that family. Null when the chart has not been seeded for
     * the agency, which the caller reports rather than silently skipping.
     */
    public function resolve(LoanProduct $product, int $installments, CarbonImmutable $base, int $agencyId): ?LedgerAccount
    {
        return LedgerAccount::query()
            ->where('agency_id', $agencyId)
            ->where('code', $this->ledgerCodeFor($product, $installments, $base))
            ->where('status', LedgerAccount::STATUS_ACTIVE)
            ->first();
    }
}
