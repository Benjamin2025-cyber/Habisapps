<?php

return [
    'unknown_linked_account_fields' => 'Unknown linked-account field(s): :fields.',
    'provide_linked_account_field' => 'Provide at least one linked-account field: :fields.',
    'setup_charges_must_be_collected' => 'Setup charges must be collected before disbursement: :types.',
    'unsupported_setup_charge_type' => 'Unsupported setup charge type: :type.',
    'setup_charges_not_assessable_after_disbursement' => 'Setup charges can only be assessed before disbursement.',
    'first_installment_date_implies_out_of_bounds_grace' => 'The chosen first installment date implies a deferral outside the loan product\'s minimum/maximum grace period.',
    'loan_divisionary_account_unusable' => 'The loan\'s :role account must be active and belong to the loan agency.',
    'unsupported_repayment_component' => 'Unsupported loan repayment component: :component.',
    'credit_ledger_mapping_required' => 'Active credit ledger mapping is required for :code.',
    'amount_must_be_integer' => ':field must be an integer amount.',
    'status_invalid_transition' => 'Loan status cannot transition from :from to :to.',
    'schedule_rate_must_be_numeric' => 'Schedule :label rate must be numeric.',
    'schedule_not_whole_minor_units' => 'Schedule :label cannot be represented in whole minor units.',
    'schedule_component_shares_do_not_reconcile' => 'Generated schedule component shares do not reconcile for :component.',
    'schedule_persisted_totals_do_not_reconcile' => 'Persisted schedule totals do not reconcile for :component.',
];
