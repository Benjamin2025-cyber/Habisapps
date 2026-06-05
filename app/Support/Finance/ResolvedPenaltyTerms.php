<?php

declare(strict_types=1);

namespace App\Support\Finance;

/**
 * The normalized penalty configuration the arrears engine actually applies for
 * a single loan, after resolving the loan snapshot, the current product, and
 * the approved global formula-policy config in that order of precedence.
 *
 * Penalty for an overdue installment is:
 *   fixedAmountMinor + round( baseAmount(base) * ratePercent / 100 )
 *
 * where baseAmount(base) is supplied by the engine per schedule line.
 */
final class ResolvedPenaltyTerms
{
    public const string SOURCE_SNAPSHOT = 'snapshot';

    public const string SOURCE_PRODUCT = 'product';

    public const string SOURCE_CONFIG = 'config';

    public function __construct(
        public readonly int $fixedAmountMinor,
        public readonly string $ratePercent,
        public readonly string $base,
        public readonly string $source,
    ) {}
}
