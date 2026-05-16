# Module 4 Backlog: Credit And Loan Engine

This backlog covers stakeholder Module 4 from `stakeholderResources/definedModules.md`: loan products, loan setup/instruction, approval workflow, amortization, collateral, recovery, delinquency, and portfolio transfer.

Current status: core credit application/API slices are implemented and covered by the Module 4 feature suite. Cash loan disbursement is integrated with Module 5 teller sessions; cash repayment remains modeled as teller cash deposit to the customer account followed by the existing account-debit loan repayment workflow.

## Guiding Rules

- [x] Do not open formula gates until the rule is converted into exact implementation policy and tested. `xaf_rounding` is approved because the cash-versus-ledger precision rule is now explicit.
- [x] Loan workflows must be agency-scoped and audit logged.
- [x] Approval workflow must use explicit transition/history records, not direct status overwrites.
- [x] Disbursement, repayment, fees, penalties, insurance, guarantee deposit, and recovery must integrate with Module 3 posting.
- [x] Cash-originated loan disbursement must integrate with Module 5 teller sessions; cash-originated repayment must use Module 5 account deposit before loan allocation.
- [x] Responses must expose public IDs/business references, not internal integer IDs.

## Epic 1: Loan Product Configuration

- [x] DEV-0101: Implement loan product model, policy, resource, requests, controller, and routes.
  - [x] Manage code, name, status, amount limits, duration limits, due date day, rates, fees, tax, insurance, guarantee deposit, penalty policy, repayment allocation policy, and ledger mapping.
  - [x] Validate min/max and due date constraints.
  - [x] Tests cover authorization, validation, lifecycle, and response contract.
  - Evidence: `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 2 tests / 30 assertions.

- [x] DEV-0102: Implement loan product formula-policy configuration.
  - [x] Bind interest, fee/tax/insurance, penalty, repayment allocation, schedule, and reporting policy keys.
  - [x] Preserve approved policy snapshot on loans.
  - [x] Tests prove unapproved formula policies fail closed.
  - Evidence: `app/Support/Finance/LoanProductFormulaPolicySnapshotter.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 3 tests / 41 assertions.

## Epic 2: Loan Application And Setup

- [x] DEV-0201: Implement loan application CRUD.
  - [x] Create loans for verified clients only.
  - [x] Assign credit agent, product, agency, requested amount, purpose, sector/sub-sector, activity address, and linked accounts.
  - [x] Tests cover KYC requirement, agency scope, product status, and account compatibility.
  - Evidence: `app/Http/Controllers/Api/V1/LoanController.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 4 tests / 78 assertions.

- [x] DEV-0202: Implement setup charge assessment.
  - [x] Assess dossier fees, tax, guarantee deposit, and non-insurance charges through `loan_charge_assessments`.
  - [x] Dossier fee normal rule is 3% of granted principal, assessed at setup approval/credit committee validation, collected separately before disbursement, and non-refundable after setup approval.
  - [x] Dossier fee exceptional setup cases require Direction manual decision; Direction can record collect-as-assessed or waiver decisions without recalculating formula amounts automatically.
  - [x] Link insurance to insurance module premium assessments where full insurance is enabled.
  - [x] Tests cover 3% dossier fee, 19.25% tax, 10% guarantee deposit, 2% loan insurance, and non-refundable/default behavior after approval.
  - Evidence: `app/Application/Loans/AssessLoanSetupCharges.php`; `app/Http/Controllers/Api/V1/LoanController.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=direction_can_record_manual_dossier_fee_exception` passes with 1 test / 20 assertions; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 26 tests / 649 assertions.

## Epic 3: Four-Step Approval Workflow

- [x] DEV-0301: Implement Montage, Comptabilite, Controle, and Direction approval steps.
  - [x] Store each step in `loan_approvals`.
  - [x] Enforce role/permission per step.
  - [x] Block skipping required steps.
  - [x] Tests cover approval, rejection, rework, audit, and no direct status forgery.
  - Evidence: `app/Application/Loans/AdvanceLoanApproval.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 6 tests / 145 assertions.

- [x] DEV-0302: Implement loan status transition policy.
  - [x] Use `loan_status_transitions` for application, in_review, approved, disbursed, active, rescheduled, closed, written_off, rejected.
  - [x] Tests cover invalid transition denial and transition history.
  - Evidence: `app/Application/Loans/TransitionLoanStatus.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 7 tests / 168 assertions.

## Epic 4: Amortization And Schedule Management

- [x] DEV-0401: Implement schedule generation.
  - [x] Generate `loan_schedule_snapshots` and `loan_schedule_lines`.
  - [x] Support flat interest on initial principal, equal installments, tax, insurance, and fees.
  - [x] Standard flat-interest schedules do not prorate partial months; ordinary schedule generation does not require day-count calculation.
  - [x] Replace the old "no rounding difference" behavior with final-installment residual absorption so approved totals reconcile exactly.
  - [x] Tests cover schedule components and policy snapshot hash.
  - Evidence: `app/Application/Loans/GenerateLoanSchedule.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=absorbs_final_residual` passes with 1 test / 36 assertions; `vendor/bin/phpstan analyse app/Application/Loans/GenerateLoanSchedule.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes with no errors.

- [x] DEV-0402: Implement schedule versioning for rescheduling/refinancing.
  - [x] Keep same loan identity.
  - [x] Preserve old schedules.
  - [x] Capitalize interest/penalties only through approved workflow.
  - [x] Value-date/day-based exception handling is not required for the current approved standard rescheduling rules; add it only if a later explicit value-date workflow is approved.
  - [x] Tests cover version history and accounting linkage.
  - Evidence: `app/Application/Loans/RescheduleLoan.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 9 tests / 222 assertions.

## Epic 5: Collateral And Guarantees

- [x] DEV-0501: Implement collateral API.
  - [x] Manage real/movable/guarantor collateral records.
  - [x] Link client, loan, documents, values, and status.
  - [x] Tests cover same-agency rules and release at loan closure.
  - Evidence: `app/Http/Controllers/Api/V1/CollateralController.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 10 tests / 258 assertions.

- [x] DEV-0502: Implement collateral item API.
  - [x] Manage quantity, description, reference, chassis/registration, amount, and metadata.
  - [x] Tests cover item lifecycle and no deletion of historical collateral.
  - Evidence: `app/Http/Controllers/Api/V1/CollateralController.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 10 tests / 258 assertions.

- [x] DEV-0503: Implement loan-specific guarantor obligations.
  - [x] Link loan obligations to active verified Module 2 guarantor records, including standalone external guarantor records.
  - [x] Store obligation amount/percentage, status, start/end dates, release conditions, and documents.
  - [x] Ensure updating guarantor identity does not rewrite historical loan obligation facts.
  - [x] Tests cover same-agency rules, release at closure, and historical immutability.
  - Evidence: `app/Http/Controllers/Api/V1/LoanGuaranteeObligationController.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=loan_guarantee_obligations` passes with 1 test / 34 assertions; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 11 tests / 297 assertions; `vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/LoanGuaranteeObligationController.php app/Http/Resources/LoanGuaranteeObligationResource.php app/Models/LoanGuaranteeObligation.php app/Models/Loan.php routes/api/v1/credit.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes with no errors.

## Epic 6: Disbursement And Accounting

- [x] DEV-0601: Implement disbursement workflow.
  - [x] Require approved loan and satisfied setup requirements.
  - [x] Create accounting entries through Module 3 posting.
  - [x] Support transfer account path through Module 3 and cash disbursement path through an open same-agency Module 5 teller session/till.
  - [x] Tests cover idempotency, accounting entries, and no duplicate disbursement.
  - Evidence: `app/Application/Loans/DisburseLoan.php`; `app/Http/Controllers/Api/V1/LoanController.php`; `database/migrations/2026_05_11_110000_create_loan_disbursements_table.php`; `database/migrations/2026_05_16_020000_allow_cash_loan_disbursement_channel.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=cash_loan_disbursement` passes with 1 test / 17 assertions; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 26 tests / 649 assertions; `vendor/bin/phpstan analyse app/Application/Loans/DisburseLoan.php app/Models/LoanDisbursement.php app/Http/Controllers/Api/V1/LoanController.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes with no errors.

## Epic 7: Repayment, Early Settlement, And Recoveries

- [x] DEV-0701: Implement repayment allocation.
  - [x] Apply stakeholder capital-first, oldest-installment-first rules.
  - [x] Keep original principal as the flat-interest base while remaining principal reduces on principal allocation.
  - [x] Keep overpayment on customer account.
  - [x] Tests cover partial payment, same-day payment, overpayment, and component allocation.
  - Evidence: `app/Application/Loans/RecordLoanRepayment.php`; `database/migrations/2026_05_11_120000_create_loan_repayments_table.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=repayment_allocates` passes with 1 test / 43 assertions; `php artisan test tests/Feature/Database/StakeholderCompleteSchemaIntegrityTest.php` passes with 15 tests / 76 assertions; `vendor/bin/phpstan analyse app/Application/Loans/RecordLoanRepayment.php app/Application/Loans/GenerateLoanSchedule.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes with no errors.

- [x] DEV-0702: Implement early repayment.
  - [x] Enforce preferred 3-month minimum if configured.
  - [x] Include future interest by default unless Direction override exists.
  - [x] No early fee; insurance not refunded; guarantee released only after full settlement.
  - [x] Tests cover Direction override and accounting effects.
  - Evidence: `app/Application/Loans/EarlyRepayLoan.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=early_repayment` passes with 2 tests.

- [x] DEV-0703: Implement automated recovery.
  - [x] Debit credit/recovery account first, then other client accounts by configured priority.
  - [x] Record attempts in `loan_recovery_attempts`.
  - [x] Tests cover partial multi-account recovery and audit. Reversal handling remains delegated to the journal/repayment reversal workflow when implemented.
  - Evidence: `app/Application/Loans/RecoverLoanFromAccounts.php`; `app/Http/Controllers/Api/V1/LoanRecoveryController.php`; `app/Models/LoanRecoveryAccount.php`; `app/Models/LoanRecoveryAttempt.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=automated_recovery_debits_recovery_account_then_priority_linked_accounts` passes with 1 test / 25 assertions; `vendor/bin/phpstan analyse app/Application/Loans/RecoverLoanFromAccounts.php app/Http/Controllers/Api/V1/LoanRecoveryController.php app/Http/Resources/LoanRecoveryAttemptResource.php app/Models/LoanRecoveryAccount.php app/Models/LoanRecoveryAttempt.php app/Models/Loan.php routes/api/v1/credit.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes.

## Epic 8: Arrears, Penalties, Delinquency, And Portfolio Transfer

- [x] DEV-0801: Implement arrears tracking.
  - [x] Create/update `loan_arrears` after due date plus grace period.
  - [x] Track unpaid amount as due installment minus paid amount.
  - [x] Tests cover J+5 late rule and partial payment.
  - Evidence: `app/Application/Loans/AssessLoanArrearsAndPenalties.php`; `tests/Unit/Application/Loans/AssessLoanArrearsAndPenaltiesTest.php`; `tests/Feature/Api/Module4CreditLoansTest.php`; focused arrears endpoint and unit tests pass.

- [x] DEV-0802: Implement penalty assessment.
  - [x] Apply `5,000 + 2% unpaid` monthly after grace, with no penalty below 1,000 XAF.
  - [x] Keep capitalization disabled until capitalized-unpaid formula is approved.
  - [x] Tests cover threshold and monthly repeat. CTX/PAR remains reporting/reclassification work, not part of normal penalty calculation.
  - [x] Batch execution hook exists for agency-scoped arrears/penalty assessment.
  - Evidence: `app/Application/Loans/AssessLoanArrearsAndPenalties.php`; `app/Application/BatchRuns/ExecuteLoanArrearsAssessmentBatch.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=batch_run_executes_loan_arrears_assessment_for_agency_scope` passes with 1 test / 16 assertions; `vendor/bin/phpstan analyse app/Application/BatchRuns/ExecuteLoanArrearsAssessmentBatch.php app/Http/Controllers/Api/V1/BatchRunController.php app/Policies/BatchRunPolicy.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes.

- [x] DEV-0803: Implement delinquency tracking API.
  - [x] Manage follow-up reason, appointment, promised amount, and comments.
  - [x] Tests cover loan/client agency scope and audit.
  - Evidence: `app/Http/Controllers/Api/V1/DelinquencyTrackingController.php`; `app/Models/DelinquencyTracking.php`; `app/Http/Resources/DelinquencyTrackingResource.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=delinquency_tracking_records_follow_up_and_enforces_agency_scope` passes with 1 test / 26 assertions; `vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/DelinquencyTrackingController.php app/Http/Resources/DelinquencyTrackingResource.php app/Models/DelinquencyTracking.php app/Models/Loan.php routes/api/v1/credit.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes.

- [x] DEV-0804: Implement loan transfer API.
  - [x] Transfer loan/portfolio between managers.
  - [x] Preserve history; do not rewrite historical postings.
  - [x] Tests cover manager eligibility and audit.
  - Evidence: `app/Http/Controllers/Api/V1/LoanTransferController.php`; `app/Models/LoanTransfer.php`; `app/Http/Resources/LoanTransferResource.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=loan_transfer_reassigns_manager_and_preserves_history_with_agency_scope` passes with 1 test / 21 assertions; `vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/LoanTransferController.php app/Http/Resources/LoanTransferResource.php app/Models/LoanTransfer.php app/Models/Loan.php routes/api/v1/credit.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes.

## Epic 9: Credit Reporting

- [x] DEV-0901: Implement portfolio outstanding report.
  - [x] Capital + interest + penalties; exclude written-off loans; keep rescheduled loans in original portfolio.
  - [x] Tests cover totals and status exclusions.
  - Evidence: `app/Http/Controllers/Api/V1/ReportRunController.php`; `app/Models/ReportDefinition.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=credit_reporting_generates_portfolio_par_and_collection_metrics` passes with 1 test / 26 assertions; `vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/ReportRunController.php app/Models/ReportDefinition.php tests/Feature/Api/Module4CreditLoansTest.php --memory-limit=1G` passes.

- [x] DEV-0902: Implement PAR/delinquency report.
  - [x] Standard PAR30 uses outstanding exposure of loans with at least one installment more than 30 days overdue.
  - [x] Delinquent overdue amount is reported separately from PAR30 outstanding-at-risk.
  - [x] Written-off loans are excluded; rescheduled loans remain reportable with a restructured flag.
  - [x] Tests cover PAR30 outstanding-at-risk and separate overdue amount.
  - Evidence: `app/Http/Controllers/Api/V1/ReportRunController.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=credit_reporting_generates_portfolio_par_and_collection_metrics` passes.

- [x] DEV-0903: Implement collection performance report.
  - [x] Expected collection includes scheduled capital, interest, and penalties; fees excluded.
  - [x] Actual collection includes posted repayment allocations from cash/account-originated repayments and partial payments immediately.
  - [x] Tests cover expected, actual, gap, and performance ratio basis.
  - Evidence: `app/Http/Controllers/Api/V1/ReportRunController.php`; `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter=credit_reporting_generates_portfolio_par_and_collection_metrics` passes.

## Completion Gate

- [x] Core loan APIs are implemented with models, permission checks/policies, requests/resources/controllers/routes, and tests.
- [x] Formula gates are approved before authoritative calculations run.
- [x] Accounting integrations are idempotent and auditable for transfer-account disbursement, repayment, early repayment, recovery, arrears/penalties, and reporting.
- [x] Cash-originated disbursement posts through Module 5 teller sessions/till cash ledger; cash-originated repayment remains deposit-to-account first, then account-debit loan repayment.
- [x] `php artisan test tests/Feature/Api/Module4CreditLoansTest.php` passes with 26 tests / 649 assertions.
- [x] `vendor/bin/phpstan analyse --memory-limit=1G` passes.
- [x] `vendor/bin/pint --test` passes.
- [x] `php artisan scramble:export` passes and exports `api.json`.
- [x] Focused post-format unit verification passes: `php artisan test tests/Unit/Application/Loans/AssessLoanArrearsAndPenaltiesTest.php tests/Unit/Support/FinanceFoundationTest.php` passes with 12 tests / 29 assertions.
- [x] Focused high-risk operation verification passes: `php artisan test tests/Feature/Api/Module4CreditLoansTest.php --filter='repayment_allocates|early_repayment|automated_recovery|batch_run_executes_loan_arrears|delinquency_tracking|loan_transfer|credit_reporting'` passes with 8 tests / 216 assertions.
