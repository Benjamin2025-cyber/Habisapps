<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Formula Policy Approval Gates
    |--------------------------------------------------------------------------
    |
    | These flags intentionally default to false. Any service that calculates
    | money owed, repayment schedules, balances, penalties, or reports must
    | require the relevant policy before executing. This prevents accidental
    | implementation from assumptions while stakeholder sign-off is pending.
    |
    */
    'policies' => [
        'xaf_rounding' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
        'loan_interest_method' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
        'loan_installment_amount' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
        'repayment_allocation_order' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
        'fees_taxes_insurance' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
        'penalties_and_arrears' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
        'account_balances' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
        'cash_till_reconciliation' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
        'portfolio_reporting_metrics' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
        ],
    ],
];
