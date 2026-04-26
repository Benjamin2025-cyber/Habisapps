<?php

declare(strict_types=1);

namespace App\Support\Finance\Contracts;

use App\Support\Finance\MoneyAmount;

interface RoundingEngine extends FormulaEngine
{
    public function round(MoneyAmount $amount): MoneyAmount;
}
