# Stakeholder Complete Migration Completion Audit

Objective restated as concrete deliverables:

1. Audit all stakeholder resources for schema requirements.
2. Create a robust backlog to finalize the migration layer.
3. Implement migrations that provide durable schema coverage for the audited requirements.
4. Verify the migrations with real commands, including the new migration rollback path.

## Prompt-To-Artifact Checklist

| Requirement | Evidence |
|---|---|
| Cover stakeholder resources, not only warning notes | Audited `stakeholderResources/Database-Schema&Entity-Relationship-(ER)-Mapping.md`, `stakeholderResources/definedModules.md`, and `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`. |
| Create complete migration-finalization backlog | `backlogs/stakeholder-complete-migration-finalization-backlog.md`. |
| Implement migration changes | `database/migrations/2026_05_11_000000_finalize_stakeholder_complete_schema.php`. |
| Add migration integrity tests like previous migrations | `tests/Feature/Database/StakeholderCompleteSchemaIntegrityTest.php`. |
| Link backlog for discoverability | `README.md` Backlog analysis section. |
| Verify fresh migration path | Initial run: `php artisan migrate:fresh --env=testing` passed. Post-implementation run on 2026-05-16: `php artisan migrate:fresh --env=testing` rebuilt the full testing schema through migration `2026_05_16_040000_add_retry_state_to_notification_and_otp_deliveries`. |
| Verify rollback path for new migration | `php artisan migrate:rollback --step=1 --env=testing` passed. |
| Verify re-apply after rollback | `php artisan migrate --env=testing` passed. |
| Verify created schema exists in test DB | `php artisan tinker --env=testing --execute="..."` listed the new tables including `account_products`, `loan_approvals`, `insurance_products`, `hr_employees`, `fx_transactions`, `islamic_financings`, `emf_regulatory_accounts`, `report_runs`, and `sms_messages`. |
| Run dedicated stakeholder schema integrity test | Initial run: `php artisan test tests/Feature/Database/StakeholderCompleteSchemaIntegrityTest.php` passed with 10 tests / 58 assertions. Post-implementation run on 2026-05-16 after fresh migration rebuild: `php artisan test tests/Feature/Database/FoundationSchemaIntegrityTest.php tests/Feature/Database/StakeholderCompleteSchemaIntegrityTest.php` passed with 34 tests / 96 assertions. |
| Run existing regression suite | `php artisan test` passed after adding dedicated schema tests: 155 tests / 920 assertions. A later full-suite rerun was intentionally cancelled on 2026-05-16 because it was too slow; use the post-implementation schema integrity, focused module tests, PHPStan, and Pint evidence for the current verification state. |

## Stakeholder Questionnaire Sections 1-30

| Section | Schema coverage |
|---|---|
| 1. XAF precision and rounding | Existing minor-unit money columns across ledger, loans, cash, charges, HR, FX, and insurance tables. |
| 2. Loan interest method | `loan_products.interest_policy_key`, `loan_products.interest_rate`, `loans.formula_policy_snapshot`, `loan_schedule_snapshots`, `loan_schedule_lines.interest_minor`. |
| 3. Day-count convention | Standard flat-interest schedules do not require day-count or partial-month proration. If future day-based/value-date workflows are implemented, use `loan_products.rules`, `loans.formula_policy_snapshot`, and `loan_schedule_snapshots.formula_engine_key/version/policy_snapshot_hash`. |
| 4. Installment amount | `loan_schedule_lines.total_installment_minor`, component columns, `loans.installment_amount_minor`. |
| 5. Principal and interest split | `loan_schedule_lines.principal_minor`, `interest_minor`, `remaining_principal_minor`, `loans.outstanding_principal_minor`, repayment projection totals. |
| 6. Dossier/application fees | `loan_products.fee_policy_key`, `loans.dossier_fees_minor`, `loans.dossier_fees_tax_minor`, `loan_charge_assessments`. |
| 7. VAT/tax | `loan_products.tax_policy_key`, `loan_products.tax_rate`, `loan_schedule_lines.tax_minor`, `loan_charge_assessments`. |
| 8. Insurance | `insurance_partners`, `insurance_products`, `insurance_subscriptions`, `insurance_premium_assessments`, `insurance_premium_payments`, plus `loan_products.insurance_policy_key` and `loan_schedule_lines.insurance_minor`. |
| 9. Guarantee deposit | `loan_products.guarantee_deposit_policy_key/type/value`, `loans.guarantee_deposit_amount_minor`, `loan_charge_assessments`. |
| 10. Penalty formula | `loan_products.penalty_policy_key`, penalty formula columns, `loan_schedule_lines.penalty_minor`, `loan_charge_assessments`, `loan_arrears`. |
| 11. Arrears/unpaid amount | `loan_arrears`, `loan_schedule_lines.status`, `loans.total_unpaid_amount_minor`, `loans.due_amount_minor`. |
| 12. Repayment allocation order | `loan_products.repayment_allocation_policy_key`, `loan_recovery_attempts`, `teller_transactions`, `journal_entries/lines`. |
| 13. Grace period | `loan_products.min_grace_period_days`, `max_grace_period_days`, `loans.grace_period_duration`, `loan_schedule_snapshots`. |
| 14. Capitalized unpaid amounts | `loan_arrears`, `loan_schedule_lines.capitalized_interest_minor`, `loans.capitalized_interest_minor`, `cumulative_capitalized_interest_minor`. |
| 15. Early repayment and automated recoveries | `loan_recovery_accounts`, `loan_recovery_attempts`, linked loan customer accounts, charge/payment/journal links. |
| 16. Rescheduling/refinancing | Existing `loan_schedule_snapshots` versioning/status and `loan_status_transitions`; extended projection fields on `loans`. |
| 17. Accounting balance | Existing `journal_entries`, `journal_lines`, `ledger_accounts`; new `account_products` account taxonomy. |
| 18. Available balance | `account_products.minimum_balance_minor`, `customer_accounts.unavailable_amount_minor`, existing `account_holds`. |
| 19. Daily/cumulative movements | Existing journal/teller transaction dates plus `teller_transactions.transaction_date`, batch/day-close tables. |
| 20. Billetage | Existing `denominations`, `till_reconciliations`, `till_reconciliation_lines`; extended till and reconciliation fields. |
| 21. Till theoretical balance | `teller_sessions`, `teller_transactions`, `till_reconciliations.theoretical_balance_minor`, till balance fields. |
| 22. Till reconciliation difference | `till_reconciliations.actual_balance_minor`, `difference_minor`, line-level denominations. |
| 23. Portfolio outstanding | `loans.global_outstanding_amount_minor`, `loan_arrears`, schedule components, status fields. |
| 24. Portfolio at risk / delinquency | `delinquency_trackings`, `loan_arrears`, loan status/date fields for PAR reports. |
| 25. Collection performance | `loan_recovery_attempts`, `teller_transactions`, `journal_entries/lines`, `report_definitions`, `report_runs`. |
| 26. HR management | `hr_employees`, `hr_contracts`, `hr_employee_documents`, `hr_attendance_records`, `hr_leave_requests`, `hr_payroll_runs`, `hr_payroll_slips`, `hr_payroll_lines`, `hr_salary_advances`, `hr_sanctions`. |
| 27. Bancassurance | `insurance_partners`, `insurance_products`, `insurance_product_coverages`, `insurance_subscriptions`, `insurance_premium_assessments`, `insurance_premium_payments`, `insurance_claims`, `insurance_claim_documents`. |
| 28. Foreign exchange | `currencies`, `exchange_rates`, `till_currency_balances`, `fx_transactions`, `fx_stock_movements`, extended `tills`. |
| 29. Islamic finance | `islamic_products`, `islamic_financings`, `islamic_financed_assets`, `islamic_profit_sharing_terms`, `islamic_compliance_reviews`. |
| 30. EMF chart, reporting, SMS, alerts, dashboards | `emf_regulatory_accounts`, `emf_ledger_account_mappings`, `operation_codes`, `operation_account_mappings`, `report_definitions`, `report_runs`, `dashboard_definitions`, `dashboard_widgets`, `notification_templates`, `notification_deliveries`, `sms_messages`. |

## ER Mapping Tables 1.1-5.5

| ER item | Schema coverage |
|---|---|
| 1.1 users | Existing `users`, `staff_agency_assignments`, HR link through `hr_employees.user_id`. |
| 1.2 otp_codes | Existing `otp_challenges` and `otp_deliveries`. |
| 1.3 agencies | Existing `agencies`. |
| 1.4 batch_procedures | Existing `batch_procedures`, `batch_runs`. |
| 2.1 clients | Existing `clients` plus module-2 CRM/KYC extension migration. |
| 2.2 guarantors | Existing `client_guarantors`. |
| 2.3 proxies | Existing `client_proxies`. |
| 3.1 accounts | Existing `customer_accounts`, extended with product, title, currency, unavailable amount, manager, signature path; `account_products`. |
| 3.2 general ledger accounts | Existing `ledger_accounts`; EMF mapping added through `emf_regulatory_accounts` and `emf_ledger_account_mappings`. |
| 3.3 sectors | Existing `sectors`. |
| 3.4 sub_sectors | Existing `sub_sectors`. |
| 4.1 loan_products | Existing `loan_products`, extended with ER and formula fields. |
| 4.2 loans | Existing `loans`, extended with setup, account, rate, schedule, charge, and projection fields. |
| 4.3 loan_approvals | New `loan_approvals`. |
| 4.4 loan_schedules | Existing `loan_schedule_snapshots` and `loan_schedule_lines`, extended with penalty, capitalized interest, remaining principal, and total installment. |
| 4.5 loan_collaterals | Existing `collaterals`. |
| 4.6 loan_collateral_items | New `collateral_items`. |
| 4.7 loan_transfers | New `loan_transfers`. |
| 4.8 delinquency_trackings | New `delinquency_trackings`. |
| 5.1 currency_denominations | Existing `denominations`. |
| 5.2 tills | Existing `tills`, extended with ledger account, daily state, balances, denomination requirement, nature, central-till flag, limits, and currency. |
| 5.3 teller_transactions | Existing `teller_transactions`, extended with transaction date, till, event number, offset account, operation code, remitter/tirer fields, and description. |
| 5.4 manual_journal_entries | Existing `journal_entries` and `journal_lines`, plus `operation_codes` and `operation_account_mappings`. |
| 5.5 till_reconciliations | Existing `till_reconciliations` and `till_reconciliation_lines`, extended with reconciliation date, theoretical balance, actual balance, difference, and currency. |

## Residual Risk

This completes the migration layer for stakeholder resources. It does not implement models, controllers, policies, seeders, calculation engines, product configuration values, or workflow services. Those should be handled in separate implementation backlogs after the schema is accepted.
