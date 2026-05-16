# Module 3 Completion Backlog: Accounting And Financial Architecture

This backlog complements `backlogs/module-3-accounting-architecture-backlog.md`. The existing implementation provides structural accounting APIs. This backlog covers full stakeholder Module 3 completion.

## Epic 1: Account Products And Account Rules

- [x] DEV-0101: Implement account product API.
  - [x] Add model, policy, requests, resources, controller, routes, and tests for `account_products`.
  - [x] Support savings, current, recovery, and future Islamic account families.
  - [x] Store minimum balance, recovery eligibility, ordinary savings flag, ledger mapping, and status.
  - [x] Tests cover uniqueness, agency scope, lifecycle, and response contract.

- [x] DEV-0102: Enforce account product rules during customer account creation.
  - [x] Require valid active account product where policy requires it.
  - [x] Copy or link minimum-balance and recovery rules according to product policy.
  - [x] Tests cover inactive product denial and account-family behavior.

- [x] DEV-0103: Resolve global/shared ledger account strategy.
  - [x] Decide whether ledger accounts can be global/institution-level or must remain agency-scoped.
  - [x] Reconcile the regulatory PCG/EMF catalog with local agency ledger accounts.
  - [x] Add migration changes only after the accounting scope decision is approved.
  - [x] Tests cover global, agency, and mapping behavior.
  - Decision: operational `ledger_accounts` remain agency-scoped in the current safe slice. Institution-level EMF/COBAC references live in `emf_regulatory_accounts`, and local agency ledgers map to that catalog through `emf_ledger_account_mappings`.

## Epic 2: EMF Chart And Operation Mapping

- [x] DEV-0201: Implement EMF regulatory account catalog API.
  - [x] Manage `emf_regulatory_accounts`.
  - [x] Support parent-child hierarchy and active/inactive lifecycle.
  - [x] Tests cover hierarchy, uniqueness, and deletion restrictions.

- [x] DEV-0202: Implement local ledger to EMF mapping API.
  - [x] Manage `emf_ledger_account_mappings`.
  - [x] Prevent duplicate or invalid mappings.
  - [x] Tests cover account scope and reporting readiness.

- [x] DEV-0203: Implement operation code and account mapping API.
  - [x] Manage `operation_codes` and `operation_account_mappings`.
  - [x] Support loan, cash, insurance, HR, FX, Islamic finance, SMS, alert, and reporting operations.
  - [x] Tests cover protected codes, mapping validity, and no unsafe posting side effects.

- [x] DEV-0204: Complete sector/sub-sector integration with client and loan metadata.
  - [x] Decide whether clients, loans, or both carry sector/sub-sector references.
  - [x] Reject inactive or invalid sector references.
  - [x] Preserve reference-only behavior until reporting engines exist.
  - [x] Tests cover client/loan classification and no portfolio metric side effects.
  - Decision: both `clients` and `loans` carry sector/sub-sector metadata. Client APIs accept public IDs and require active matching references. Loan metadata remains schema-level until the loan workflow API is implemented; database constraints prevent mismatched sector/sub-sector pairs.

## Epic 3: Journal Review, Approval, And Posting

- [x] DEV-0301: Implement journal review workflow.
  - [x] Add explicit states for draft, submitted, approved, rejected, posted, reversed, and cancelled.
  - [x] Require reviewer permission for approval/rejection.
  - [x] Tests cover invalid transitions and maker-checker rules.
  - Decision: review approval/rejection is separate from posting. Submitted entries must be balanced and have at least two lines; approved entries are ready for the later authoritative posting workflow in `DEV-0302`.

- [x] DEV-0302: Implement authoritative posting.
  - [x] Only balanced, approved journals can post.
  - [x] Posted journals and lines become immutable except through reversal.
  - [x] Posting is idempotent and audit logged.
  - [x] Tests cover immutability, reversal, duplicate posting prevention, and agency scope.
  - Decision: posting currently finalizes journal state and audit metadata only. Balance projections remain deferred to `DEV-0401`; reversal creates a posted offsetting journal and marks the source entry reversed.

## Epic 4: Balance, Movement, And Statements

- [x] DEV-0401: Implement accounting balance engine.
  - [x] Derive balances from posted journal lines.
  - [x] Exclude draft/pending journals unless a report explicitly requests them.
  - [x] Tests cover account, customer account, ledger account, date range, and currency.
  - Decision: expose read-only ledger-account and customer-account accounting-balance projections. Do not store mutable balance columns. Available balance remains separate in `DEV-0402`.

- [x] DEV-0402: Implement available balance engine.
  - [x] Apply account product minimum balance, active holds, loan restrictions, and pending withdrawals once defined.
  - [x] Tests cover stakeholder formula expectations and account-type rules.
  - Decision: available balance is accounting balance minus product minimum balance, recorded unavailable amount, and active holds in the requested currency. Pending withdrawals and loan restrictions remain excluded until those workflows create explicit reservations.

- [x] DEV-0403: Implement account statement and movement APIs.
  - [x] Support date ranges, debit/credit totals, opening/closing derived balances, and pagination.
  - [x] Tests cover posted-only behavior, authorization, and no internal IDs.
  - Decision: customer account statements and ledger account movements are read-only projections over posted journal lines. Opening and closing balances are rebuilt from posted entries, not stored.

## Epic 5: Accounting Reports

- [x] DEV-0501: Implement trial balance and general ledger report runs.
  - [x] Use `report_definitions` and `report_runs`.
  - [x] Link generated reports to `documents` where exported.
  - [x] Tests cover report status, document linkage, and agency scope.
  - Decision: report runs summarize posted journal lines into `report_runs.summary`. Export linkage attaches an existing document; document/media generation remains a separate export concern.

- [x] DEV-0502: Implement EMF/COBAC reporting foundation.
  - [x] Use EMF mappings.
  - [x] Validate unmapped ledger accounts before report generation.
  - [x] Tests cover incomplete mapping denial.
  - Decision: `emf_trial_balance` report runs require active EMF mappings for every posted ledger account in scope before summary generation.

## Completion Gate

- [x] Account product and EMF catalog APIs implemented.
- [x] Journal posting is authoritative and immutable.
- [x] Balance/available-balance formulas are implemented only after formula policy approval.
- [x] Report generation has tests and audit trail.
- [x] `vendor/bin/phpstan analyse --memory-limit=1G` passes.
- [x] `vendor/bin/pint --test` passes.
- [x] `php artisan scramble:export` passes and exports `api.json`.
- [x] Product/API verification passes: `php artisan test tests/Feature/Api/Module3AccountingProductTest.php` passes with 5 tests / 81 assertions.
- [x] Focused post-format verification passes: `php artisan test tests/Feature/Module3AccountingArchitectureTest.php --filter='journal_review_workflow|accounting_balances_are_derived|journal_lines_cannot_mutate'` passes with 3 tests / 130 assertions.
- [x] Account-hold unit verification passes: `php artisan test tests/Unit/Application/Accounting/ReleaseAccountHoldTest.php` passes as part of a 7-test unit slice.
- [x] `php artisan test` passes.
