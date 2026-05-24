<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use RuntimeException;

final class StandardsBaselineFailure extends RuntimeException
{
    /**
     * @param  array<int, string>  $failures
     */
    public function __construct(public readonly array $failures)
    {
        parent::__construct(implode(' ', $failures));
    }
}
