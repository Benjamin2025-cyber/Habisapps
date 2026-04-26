<?php

declare(strict_types=1);

namespace App\Support\Finance\Contracts;

use App\Support\Finance\DateRange;
use App\Support\Finance\MoneyAmount;
use App\Support\Finance\PercentageRate;

interface LoanInterestEngine extends FormulaEngine
{
    public function calculate(MoneyAmount $principal, PercentageRate $rate, DateRange $period): MoneyAmount;
}
