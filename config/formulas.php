<?php

declare(strict_types=1);
use App\Support\Finance\Engines\UnavailableFormulaEngine;

return [
    /*
    |--------------------------------------------------------------------------
    | Formula Policy Approval Gates
    |--------------------------------------------------------------------------
    |
    | These flags intentionally default to false until a rule is explicit,
    | finance-aligned, and testable. Any service that calculates money owed,
    | repayment schedules, balances, penalties, or reports must require the
    | relevant policy before executing.
    |
    */
    'policies' => [
        'xaf_rounding' => [
            'approved' => true,
            'owner' => 'implementation',
            'approved_at' => '2026-05-14',
            'rule' => [
                'account_scale' => 2,
                'physical_cash_scale' => 0,
                'round_debt_to_cash_denomination' => false,
                'final_installment_absorbs_residual' => true,
                'excess_deposit_retained_on_customer_account' => true,
            ],
        ],
        'loan_interest_method' => [
            'approved' => true,
            'owner' => 'implementation',
            'approved_at' => '2026-05-14',
            'rule' => [
                'method' => 'flat_initial_principal',
                'rate_basis' => 'total_loan_term_percent',
                'total_interest_formula' => 'initial_principal * interest_rate / 100',
                'per_installment_interest_formula' => 'total_interest / number_of_installments',
                'standard_schedule_partial_month_policy' => 'no_proration',
                'day_count_convention_for_explicit_day_based_calculations' => '360_day',
                'early_repayment_default' => 'collect_remaining_scheduled_flat_interest',
                'direction_future_interest_waiver_allowed' => true,
                'direction_negotiated_interest_concession_allowed' => true,
                'negotiated_interest_concession_policy' => 'reduce_future_scheduled_interest_first_never_refund_interest_already_paid_without_separate_approval',
                'rescheduling_default' => 'preserve_flat_interest_logic_with_new_schedule_version',
            ],
        ],
        'loan_installment_amount' => [
            'approved' => true,
            'owner' => 'implementation',
            'approved_at' => '2026-05-14',
            'rule' => [
                'installment_type' => 'equal_total_installments',
                'included_components' => ['principal', 'interest', 'fees', 'insurance', 'tax'],
                'component_precision_scale' => 2,
                'ordinary_partial_month_policy' => 'no_proration',
                'residual_strategy' => 'final_installment',
            ],
        ],
        'repayment_allocation_order' => [
            'approved' => true,
            'owner' => 'implementation',
            'approved_at' => '2026-05-14',
            'rule' => [
                'installment_order' => 'oldest_first',
                'component_order' => ['principal', 'interest', 'fees', 'insurance', 'tax', 'penalty'],
                'penalty_collection_order' => 'after_scheduled_dues_oldest_penalty_first',
                'principal_reduces_remaining_balance_on_allocation' => true,
                'interest_base_remains_original_principal' => true,
                'overpayment_policy' => 'retain_on_customer_account',
                'same_day_payment_order' => 'same_as_standard_allocation_order',
                'early_repayment_recovery_priority' => [
                    'loan_credit_or_repayment_account_first',
                    'other_linked_client_accounts_by_configured_priority',
                ],
                'recovery_requires_same_client_linked_accounts' => true,
            ],
        ],
        'fees_taxes_insurance' => [
            'approved' => true,
            'owner' => 'implementation',
            'approved_at' => '2026-05-14',
            'rules' => [
                'dossier_fee' => [
                    'approved' => true,
                    'rate_percent' => '3',
                    'base' => 'granted_principal',
                    'assessment_trigger' => 'credit_committee_validation',
                    'collection_timing' => 'before_disbursement',
                    'collection_method' => 'separate_cash_or_account_payment',
                    'refund_policy' => 'non_refundable_after_setup_approval',
                    'exception_policy' => 'direction_manual_decision',
                ],
                'setup_tax' => [
                    'approved' => true,
                    'rate_percent' => '19.25',
                    'base' => 'granted_principal_plus_total_flat_interest',
                    'assessment_timing' => 'upfront_setup',
                    'rounding_policy' => 'exact_account_precision_no_cash_rounding',
                    'accounting_timing' => 'setup',
                ],
                'loan_insurance' => [
                    'approved' => true,
                    'rate_percent' => '2',
                    'base' => 'granted_principal',
                    'assessment_timing' => 'upfront_setup',
                    'refund_policy' => 'non_refundable_on_early_closure',
                    'accounting_timing' => 'setup',
                ],
                'guarantee_deposit' => [
                    'approved' => true,
                    'rate_percent' => '10',
                    'base' => 'granted_principal',
                    'collection_method' => 'cash_before_disbursement',
                    'holding_treatment' => 'restricted_liability_or_customer_guarantee_balance',
                    'release_trigger' => 'loan_closure_after_full_settlement',
                    'can_settle_unpaid_loans' => false,
                    'offset_policy' => 'not_offset_against_last_installment',
                    'accounting_timing' => 'setup',
                ],
            ],
        ],
        'penalties_and_arrears' => [
            'approved' => false,
            'owner' => null,
            'approved_at' => null,
            'rules' => [
                'monthly_arrears_penalty' => [
                    'approved' => true,
                    'fixed_amount_minor' => 5000,
                    'variable_rate_percent' => '2',
                    'base' => 'unpaid_scheduled_due_excluding_prior_penalties',
                    'minimum_unpaid_amount_minor' => 1000,
                    'frequency' => 'monthly',
                    'trigger' => 'after_5_grace_days_on_monthly_arrears_batch',
                    'prior_penalties_remain_due' => true,
                    'prior_penalties_generate_new_penalties' => false,
                    'collection_priority' => 'scheduled_principal_interest_fees_insurance_tax_before_penalties_then_oldest_penalty_first',
                    'only_prior_penalties_unpaid_policy' => 'collect_without_new_penalty',
                    'capitalized_unpaid_amounts_policy' => 'separate_section_14_workflow',
                    'rounding_policy' => 'exact_account_precision_no_cash_rounding',
                ],
                'arrears_unpaid_amount' => [
                    'approved' => true,
                    'late_boundary' => 'due_date_plus_5_days',
                    'unpaid_formula' => 'scheduled_due_excluding_prior_penalties - allocated_payment',
                    'partial_payment_treatment' => 'allocate_by_standard_order_without_separate_classification',
                    'overdeposit_treatment' => 'customer_account_balance_until_allocated',
                ],
                'grace_period' => [
                    'approved' => true,
                    'principal_deferred' => false,
                    'interest_continues' => true,
                    'interest_capitalized' => false,
                    'penalties_during_grace' => false,
                    'schedule_rewrite_policy' => 'none_for_standard_flat_schedule',
                ],
                'capitalized_unpaid_amounts' => [
                    'approved' => true,
                    'policy' => 'no_automatic_capitalization_use_arrears_carry_forward_view',
                    'classic_interest_capitalization_allowed' => false,
                    'changes_original_flat_interest_base' => false,
                    'prior_penalties_generate_new_penalties' => false,
                    'carry_forward_type' => 'calculated_arrears_view',
                    'carry_forward_formula' => 'open_scheduled_due_excluding_prior_penalties',
                    'accounting_posting_treatment' => 'no_journal_entry_for_normal_carry_forward',
                    'reschedule_or_refinance_exception' => 'credit_committee_workflow_required_before_capitalizing_any_unpaid_amount',
                ],
                'rescheduling_refinancing' => [
                    'approved' => true,
                    'same_loan_identity' => true,
                    'preserve_schedule_history' => true,
                    'credit_committee_approval_required' => true,
                    'capitalization_default' => 'blocked',
                    'capitalization_allowed_only_with_dedicated_accounting_workflow' => true,
                    'new_schedule_formula' => 'approved_flat_interest_logic_unless_explicitly_overridden_by_committee_workflow',
                ],
            ],
        ],
        'account_balances' => [
            'approved' => true,
            'owner' => 'implementation',
            'approved_at' => '2026-05-14',
            'rule' => [
                'accounting_balance_source' => 'posted_validated_journal_entries_only',
                'debit_normal_balance_formula' => 'debit_total - credit_total',
                'credit_normal_balance_formula' => 'credit_total - debit_total',
                'draft_pending_entries_affect_accounting_balance' => false,
                'reversal_policy' => 'original_entry_kept_reversal_entry_posts_opposite_effect',
                'available_balance_formula' => 'accounting_balance - minimum_balance - unavailable_amount - active_holds',
                'pending_withdrawal_policy' => 'reduce_available_balance_only_when_recorded_as_active_hold_or_unavailable_amount',
                'ordinary_savings_minimum_balance_minor' => 5000,
                'current_account_minimum_balance_minor' => 0,
                'accounting_movement_date_basis' => 'business_date',
                'operational_movement_date_basis' => 'transaction_date_when_available',
                'day_close_boundary' => 'batch_business_date',
            ],
        ],
        'cash_till_reconciliation' => [
            'approved' => true,
            'owner' => 'implementation',
            'approved_at' => '2026-05-14',
            'rule' => [
                'denomination_line_total_formula' => 'denomination_value * count',
                'accept_all_active_denominations' => true,
                'track_coins' => true,
                'track_damaged_cash_separately' => false,
                'opening_count_required' => true,
                'closing_count_required' => true,
                'theoretical_balance_formula' => 'opening_balance + posted_cash_inflows - posted_cash_outflows',
                'opening_balance_source' => 'previous_business_day_closing_balance_or_initial_session_balance',
                'include_pending_transactions_in_theoretical_balance' => false,
                'pending_transactions_close_policy' => 'display_and_block_close_until_posted_cancelled_or_supervisor_carried_forward',
                'reconciliation_difference_formula' => 'counted_cash - theoretical_balance',
                'posted_cash_difference_tolerance_minor' => 0,
                'unresolved_posted_difference_close_policy' => 'block_close',
            ],
        ],
        'portfolio_reporting_metrics' => [
            'approved' => true,
            'owner' => 'implementation',
            'approved_at' => '2026-05-14',
            'rule' => [
                'portfolio_outstanding_formula' => 'remaining_principal + scheduled_due_interest + assessed_unpaid_penalties',
                'written_off_loans_in_outstanding' => false,
                'rescheduled_loans_portfolio_policy' => 'retain_original_portfolio_and_report_restructured_status_separately',
                'par30_standard_numerator' => 'outstanding_balance_of_loans_with_any_installment_more_than_30_days_past_due',
                'par30_standard_denominator' => 'gross_outstanding_loan_portfolio_excluding_written_off_loans',
                'delinquent_amount_metric' => 'overdue_amount_only',
                'do_not_label_overdue_amount_as_par' => true,
                'written_off_loans_in_par30' => false,
                'formally_rescheduled_current_loans_in_par30' => false,
                'rescheduled_delinquent_loans_report_policy' => 'exclude_from_main_par30_only_if_formally_current_and_report_in_restructured_watchlist',
                'collection_expected_formula' => 'scheduled_principal_due + scheduled_interest_due + assessed_penalties_due',
                'collection_expected_excludes' => ['fees'],
                'collection_recognized_formula' => 'allocated_cash_and_account_debit_repayments_to_principal_interest_penalties',
                'collection_recognized_excludes' => ['unallocated_customer_deposits', 'fees'],
                'partial_payments_count_immediately' => true,
                'collection_performance_rate_formula' => 'recognized_collection / expected_collection * 100',
                'collection_shortfall_formula' => 'max(expected_collection - recognized_collection, 0)',
                'collection_surplus_formula' => 'max(recognized_collection - expected_collection, 0)',
                'zero_expected_collection_rate_policy' => 'null_not_zero',
            ],
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
