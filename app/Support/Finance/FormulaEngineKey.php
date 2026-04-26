<?php

declare(strict_types=1);

namespace App\Support\Finance;

enum FormulaEngineKey: string
{
    case Rounding = 'rounding';
    case LoanInterest = 'loan_interest';
    case Installment = 'installment';
    case RepaymentAllocation = 'repayment_allocation';
    case FeeTaxInsurance = 'fee_tax_insurance';
    case PenaltyArrears = 'penalty_arrears';
    case AccountBalance = 'account_balance';
    case CashTillReconciliation = 'cash_till_reconciliation';
    case PortfolioReporting = 'portfolio_reporting';

    public function requiredPolicy(): FormulaPolicyKey
    {
        return match ($this) {
            self::Rounding => FormulaPolicyKey::XafRounding,
            self::LoanInterest => FormulaPolicyKey::LoanInterestMethod,
            self::Installment => FormulaPolicyKey::LoanInstallmentAmount,
            self::RepaymentAllocation => FormulaPolicyKey::RepaymentAllocationOrder,
            self::FeeTaxInsurance => FormulaPolicyKey::FeesTaxesInsurance,
            self::PenaltyArrears => FormulaPolicyKey::PenaltiesAndArrears,
            self::AccountBalance => FormulaPolicyKey::AccountBalances,
            self::CashTillReconciliation => FormulaPolicyKey::CashTillReconciliation,
            self::PortfolioReporting => FormulaPolicyKey::PortfolioReportingMetrics,
        };
    }
}
