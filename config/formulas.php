<?php

declare(strict_types=1);
use App\Support\Finance\Engines\UnavailableFormulaEngine;

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

    /*
    |--------------------------------------------------------------------------
    | Formula Engine Drivers
    |--------------------------------------------------------------------------
    |
    | Each critical calculation area resolves through a named engine driver.
    | The default "unavailable" engine fails closed through the policy gate.
    | When stakeholders approve a rule, implement a driver class, register it
    | in "drivers", and map the relevant engine key to that driver.
    |
    */
    'engines' => [
        'rounding' => 'unavailable',
        'loan_interest' => 'unavailable',
        'installment' => 'unavailable',
        'repayment_allocation' => 'unavailable',
        'fee_tax_insurance' => 'unavailable',
        'penalty_arrears' => 'unavailable',
        'account_balance' => 'unavailable',
        'cash_till_reconciliation' => 'unavailable',
        'portfolio_reporting' => 'unavailable',
    ],

    'drivers' => [
        'unavailable' => UnavailableFormulaEngine::class,
    ],
];
