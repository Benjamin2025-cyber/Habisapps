<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use RuntimeException;

final class ReadinessGateFailure extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $failures  keyed by gate (e.g. 'islamic_standards_baseline', 'islamic_regulatory_signoff')
     */
    public function __construct(public readonly array $failures)
    {
        $flat = [];
        foreach ($failures as $messages) {
            foreach ($messages as $msg) {
                $flat[] = $msg;
            }
        }
        parent::__construct(implode(' ', $flat));
    }
}
