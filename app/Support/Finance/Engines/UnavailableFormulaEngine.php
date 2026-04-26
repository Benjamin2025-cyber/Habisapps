<?php

declare(strict_types=1);

namespace App\Support\Finance\Engines;

use App\Support\Finance\Contracts\AccountBalanceEngine;
use App\Support\Finance\Contracts\CashTillReconciliationEngine;
use App\Support\Finance\Contracts\FeeTaxInsuranceEngine;
use App\Support\Finance\Contracts\InstallmentEngine;
use App\Support\Finance\Contracts\LoanInterestEngine;
use App\Support\Finance\Contracts\PenaltyArrearsEngine;
use App\Support\Finance\Contracts\PortfolioReportingEngine;
use App\Support\Finance\Contracts\RepaymentAllocationEngine;
use App\Support\Finance\Contracts\RoundingEngine;
use App\Support\Finance\DateRange;
use App\Support\Finance\FormulaEngineKey;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\FormulaPolicyRegistry;
use App\Support\Finance\MoneyAmount;
use App\Support\Finance\PercentageRate;

final readonly class UnavailableFormulaEngine implements AccountBalanceEngine, CashTillReconciliationEngine, FeeTaxInsuranceEngine, InstallmentEngine, LoanInterestEngine, PenaltyArrearsEngine, PortfolioReportingEngine, RepaymentAllocationEngine, RoundingEngine
{
    public function __construct(
        private FormulaEngineKey $key,
        private FormulaPolicyRegistry $policies,
    ) {}

    public function key(): FormulaEngineKey
    {
        return $this->key;
    }

    public function requiredPolicy(): FormulaPolicyKey
    {
        return $this->key->requiredPolicy();
    }

    public function round(MoneyAmount $amount): MoneyAmount
    {
        $this->policies->requireApproved($this->requiredPolicy());

        return $amount;
    }

    public function calculate(MoneyAmount $principal, PercentageRate $rate, DateRange $period): MoneyAmount
    {
        $this->policies->requireApproved($this->requiredPolicy());

        return MoneyAmount::zero($principal->currency());
    }
}
