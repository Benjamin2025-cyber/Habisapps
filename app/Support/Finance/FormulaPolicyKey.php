<?php

declare(strict_types=1);

namespace App\Support\Finance;

enum FormulaPolicyKey: string
{
    case XafRounding = 'xaf_rounding';
    case LoanInterestMethod = 'loan_interest_method';
    case LoanInstallmentAmount = 'loan_installment_amount';
    case RepaymentAllocationOrder = 'repayment_allocation_order';
    case FeesTaxesInsurance = 'fees_taxes_insurance';
    case PenaltiesAndArrears = 'penalties_and_arrears';
    case AccountBalances = 'account_balances';
    case CashTillReconciliation = 'cash_till_reconciliation';
    case PortfolioReportingMetrics = 'portfolio_reporting_metrics';
}
