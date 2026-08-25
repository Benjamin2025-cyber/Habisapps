<?php

declare(strict_types=1);

namespace App\Support\Finance;

/**
 * The penalty terms the arrears engine applies, resolved from the approved
 * `penalties_and_arrears` formula-policy config.
 *
 * Penalty for an overdue installment is:
 *   fixedAmountMinor + round( baseAmount(base) * ratePercent / 100 )
 *
 * where baseAmount(base) is supplied by the engine per schedule line.
 */
final class ResolvedPenaltyTerms
{
    public const string SOURCE_CONFIG = 'config';

    public function __construct(
        public readonly int $fixedAmountMinor,
        public readonly string $ratePercent,
        public readonly string $base,
        public readonly string $source,
    ) {}
}
