# Frontend Integration Backend Issues V2 Backlog

Source feedback:

- Original: `docs/frontendIntegrationfeedabcks/back-issues.md`
- Updated: `docs/frontendIntegrationfeedabcks/back-issues-v2.md`
- Prior backlog: `backlogs/frontend-integration-backend-issues-backlog.md`

Investigation date: 2026-06-01

Method: compare v1 and v2, extract only the new frontend feedback, then verify each new claim against current routes, controllers, workflows, resources, policies, schema, and tests. Current worktree state is authoritative because the first frontend backlog was already implemented in this branch.

## V2 Delta

`back-issues-v2.md` keeps all original issues `#1` to `#23` unchanged and adds eight new issues:

- `#24` Teller analytics endpoints are insufficient for a real cashier dashboard.
- `#25` Cash deposit/withdrawal payloads lack payment method, denomination detail, charges, channel/reference, and notification intent.
- `#26` No frontend notification feed endpoint.
- `#27` Teller dashboard still contains demo sections because `#24` and `#26` are missing.
- `#28` No `GET /report-definitions`, so report generation cannot be driven by the UI.
- `#29` `GET /teller-sessions` is not filterable enough for historical consultation at scale.
- `#30` Loan disbursement needs reloadable setup-charge and insurance-premium state before final disbursement.
- `#31` Global loan products cannot safely map ledger accounts across agencies.

This backlog does not reopen `#1` to `#23`. It adds the required v2 work and regression gates so the old issues do not reappear while the new endpoints are added.

## Current Evidence Summary

### Cash/teller evidence

- `routes/api/v1/accounting.php` exposes `POST teller-sessions/{tellerSession}/deposits`, `POST teller-sessions/{tellerSession}/withdrawals`, and `POST teller-transactions/{tellerTransaction}/reverse`.
- There is no `GET /api/v1/teller-transactions`.
- `TellerTransactionPolicy::viewAny()` already exists and uses `cash.transactions.view`, so the authorization policy is ready for a list endpoint.
- `TellerSessionResource` exposes session identity, opening/closing declarations, currency, and status only. It does not expose deposits total, withdrawals total, expected cash position, transaction count, or recent transaction summary.
- `TellerSessionWorkflow::index()` has generic `search` only. It does not implement exact filters for `business_date`, date range, till, teller, status, or sort.
- `StoreCashDepositRequest` and `StoreCashWithdrawalRequest` do not accept `payment_method`, `denomination_counts`, fee fields, external channel/reference, or notification flags.

### Notification evidence

- `routes/api/v1/notifications.php` exposes only `POST clients/{clientPublicId}/notification-consents`.
- Notification internals exist (`NotificationOutbox`, alert producers, template manager, retry manager), but there is no authenticated user-facing `GET /notifications` or mark-as-read endpoint.

### Reporting evidence

- `ReportDefinition` and `ReportRun` models exist.
- `routes/api/v1/accounting.php` exposes `GET /report-runs`, `POST /report-runs`, and `GET /report-runs/{reportRun}`.
- `routes/api/v1/regulatory_reporting.php` exposes `POST /report-definitions` but no `GET /report-definitions`.
- `ReportRunController::store()` requires `report_definition_public_id`, so the UI cannot generate a report unless it already knows a definition public ID.
- Tests create report definitions inline; there is no evidence of a standard seeded catalog available to frontend users.

### Loan setup/disbursement evidence

- Setup-charge mutation routes exist:
  - `POST /loans/{loan}/setup-charges/assess`
  - `POST /loans/{loan}/setup-charges/{chargePublicId}/collect`
  - `POST /loans/{loan}/insurance-premiums/{premiumPublicId}/collect`
  - `POST /loans/{loan}/setup-charges/{chargePublicId}/direction-decision`
- There is no `GET /loans/{loan}/setup-charges` or equivalent read endpoint to reload assessed charges and insurance premiums after navigation.
- `LoanSetupChargeWorkflow::assessSetupCharges()` returns charges and insurance premium assessment only as the mutation response.
- `DisburseLoan::ensureSetupSatisfied()` requires setup charges and insurance premiums to be assessed and collected before disbursement, so the missing read endpoint blocks a reliable frontend disbursement flow.

### Ledger mapping evidence

- `operation_account_mappings` exists and later migrations added `agency_id`, effective dates, and approval fields.
- The generic `OperationAccountMappingController::store()` does not set `agency_id`, and update does not allow changing ledger accounts or scope fields.
- `LoanSetupChargeWorkflow` already resolves setup-charge and insurance-premium revenue ledgers through `operation_account_mappings` by operation code and by ledger account agency.
- `DisburseLoan` still reads `LoanProduct::ledger_account_id` directly and requires that ledger to belong to the loan agency.
- A global loan product with one `ledger_account_id` therefore cannot disburse loans in multiple agencies.

## Product Decisions For V2

These are implementation defaults, not open questions:

- Teller dashboard backend must expose both a paginated transaction list and stable session summary fields. A summary-only endpoint is not enough because the dashboard also needs recent operations/history.
- Cash session consultation must use exact server-side filters, not frontend page-walking.
- Notifications must be an authenticated per-user feed backed by persistent rows or a durable read model. Do not return demo/static notifications.
- Own-user notifications require authentication only. Agency-wide notification administration requires a separate `notifications.view` permission and current-agency scope.
- Default `teller` and `agency-manager` roles receive `notifications.view` for current-agency operational alerts. This permission must not expose platform-wide notifications.
- Report generation must be driven by a listable active report-definition catalog.
- Loan setup charges and insurance premiums must be reloadable independently of the assess mutation response.
- Global loan products must not carry agency-specific ledger accounts as their only posting source. Posting workflows must resolve agency-specific ledgers through approved operation-account mappings.
- Do not grant broad ledger/report permissions to teller just to satisfy dashboard needs. Expose teller-scoped data through cash permissions.
- Do not add frontend-only workarounds as the backend fix.

## V2 Backlog

### FBI2-024: Teller Transaction List And Cashier Session Summary

Source issue: `#24 Analytics caissier (teller)`.

Severity: Critical.

Current contradiction:

- Assume the teller dashboard can display recent operations, session history, deposit/withdraw totals, and expected cash position from backend data.
- The backend has no `GET /api/v1/teller-transactions`.
- `TellerSessionResource` does not expose transaction totals or expected cash position.
- `/dashboards/operational` explicitly denies field roles such as `teller`.
- Therefore the current backend cannot power the teller dashboard without demo data or client-side reconstruction.

Implementation:

- Add `GET /api/v1/teller-transactions`.
- Return a paginated collection using `TellerTransactionResource`.
- Support filters:
  - `filter[teller_session_public_id]`
  - `filter[till_public_id]`
  - `filter[teller_user_public_id]`
  - `filter[transaction_type]`
  - `filter[status]`
  - `filter[transaction_date]`
  - `filter[transaction_date_from]`
  - `filter[transaction_date_to]`
  - `filter[customer_account_public_id]`
  - `filter[loan_public_id]`
  - `search` over reference, event number, operation code, depositor name, and description.
- Enforce `TellerTransactionPolicy::viewAny()` and agency scope:
  - `platform-admin` can query all agencies.
  - Non-platform users see only their current agency.
  - `teller` sees only sessions where `teller_user_id` is their own user ID.
  - `agency-manager` and roles with `cash.transactions.view` plus current-agency scope can see all transactions in their agency.
- Extend `TellerSessionResource` with server-calculated summary:
  - `deposits_total_minor`
  - `withdrawals_total_minor`
  - `manual_journals_total_minor`
  - `reversals_total_minor`
  - `transaction_count`
  - `posted_transaction_count`
  - `pending_transaction_count`
  - `expected_cash_balance_minor`
  - `last_transaction_at`
- Calculate expected cash balance using the same direction logic as teller closing, so the dashboard and close validation cannot drift.
- Implement list/session summaries with aggregate queries or eager-loaded counts/sums. Do not compute summaries with one transaction aggregate query per session row.
- Do not expose ledger-account balances to teller through this endpoint.

Acceptance criteria:

- Same-agency teller can list transactions for their own open session.
- Same-agency agency-manager can list transactions for agency teller sessions.
- Cross-agency teller and agency-manager cannot list or infer another agency's transactions.
- Platform-admin can list all agency transactions and filter by agency-linked till/session.
- Filtering by session, till, teller, transaction type, status, exact date, date range, customer account, and loan returns only matching rows.
- `TellerSessionResource` summary values match the transaction list totals for the same session.
- Reversed/cancelled transactions are represented consistently and do not inflate active expected cash position.
- Pagination metadata is stable and `per_page` remains capped.
- OpenAPI documents the endpoint, filters, and summary fields.
- A paginated session list with summaries does not execute N+1 transaction aggregate queries.

Regression tests:

- Feature test creates two agencies, two teller sessions, deposits, withdrawals, manual journals, and reversals; then proves scope and totals.
- Feature test proves teller continues to receive 403 on `/dashboards/operational`; teller dashboard data must come from teller-scoped endpoints.
- Feature test proves the close-session theoretical balance and session summary expected balance use the same transaction-direction rules.

### FBI2-025: Complete Deposit/Withdrawal Payload Contract

Source issue: `#25 Versement/Retrait caisse`.

Severity: High.

Current contradiction:

- Assume deposit/withdrawal endpoints accept all fields needed by the cash operation screens.
- `StoreCashDepositRequest` and `StoreCashWithdrawalRequest` accept amount, currency, customer account, operation code, initiator/signature data, description, and idempotency only.
- They do not accept payment method, denomination counts, charges/fees, channel, external reference, or notification preferences.
- Therefore the current backend forces frontend fields to be hidden or demo-only.

Implementation:

- Add a tender model to teller transactions without breaking existing cash-only clients.
- Keep default `payment_method = cash` when omitted for backward compatibility.
- Supported `payment_method` values:
  - `cash`
  - `cheque`
  - `transfer`
  - `mixed`
- Add request fields:
  - `payment_method`
  - `denomination_counts` for the cash component
  - `cash_amount_minor`
  - `cheque_amount_minor`
  - `transfer_amount_minor`
  - `cheque_number`
  - `cheque_bank_name`
  - `cheque_issue_date`
  - `external_reference`
  - `channel`
  - `fee_policy_key`
  - `notify_customer`
  - `notification_channels`
- Add `GET /api/v1/reference/cash-transaction-options`.
- The options endpoint returns:
  - supported payment methods
  - supported channels
  - required fields by payment method
  - supported notification channels
  - whether denomination counts are required for the selected till/session
  - fee policy keys available to the actor/session
- Add durable persistence for payment/tender details:
  - Add normalized `teller_transaction_tenders`.
  - Store one tender row per payment component with amount, method, reference fields, status, and ledger mapping evidence.
- Accounting behavior:
  - Cash component affects till cash balance and denomination validation.
  - Cheque and transfer components do not affect physical till cash.
  - Every non-cash component must resolve debit/credit ledgers through active operation-account mappings for the teller session agency and currency.
  - Mixed payments must post balanced journal lines for each component.
- Fees:
  - Do not trust arbitrary frontend `fee_amount_minor` as authoritative.
  - Use `fee_policy_key` or operation-code rules to calculate expected fees.
  - Do not implement manual fee override in this ticket.
  - If no approved fee policy/mapping exists, fee amount is zero and the response must explicitly return `fees_applied=false`.
- Notifications:
  - `notify_customer=true` queues a notification outbox entry after successful posting.
  - Notification failure must not roll back the financial posting; it must be observable as delivery status.
- Responses must include tender breakdown, calculated fees, channel, external reference, and notification request status.
- Idempotency:
  - Replaying the same idempotency key must return the original teller transaction and original tender breakdown.
  - Replaying the same idempotency key with a different tender breakdown must return 422 and must not post a second transaction.

Acceptance criteria:

- Existing cash deposit/withdrawal requests without `payment_method` still pass and behave exactly as cash.
- Cash transactions with denomination counts validate count total against `cash_amount_minor`.
- Cheque-only or transfer-only transactions do not increase/decrease physical till cash.
- Mixed transactions split cash/non-cash components correctly and remain balanced.
- Invalid payment-method values return 422 with enum details.
- `GET /reference/cash-transaction-options` returns the same supported payment methods and required-field rules used by request validation.
- Missing cheque metadata on cheque/mixed payments returns 422.
- Fee calculation is server-authoritative and the response shows the calculated fee.
- A transaction with no applicable fee policy returns `fees_applied=false` and `fee_amount_minor=0`.
- Notification intent creates an outbox row scoped to the client/user and does not expose PII to unauthorized users.
- Transaction list and session summaries from `FBI2-024` account for cash and non-cash components correctly.
- Idempotent replay does not duplicate transaction rows, tender rows, journal entries, notification outbox rows, or fee rows.

Regression tests:

- Cash-only deposit/withdrawal tests from Module 5 continue to pass.
- New tests cover cash, cheque, transfer, and mixed deposits.
- New tests cover cash, cheque, transfer, and mixed withdrawals with the same tender accounting rules as deposits.
- Tests prove a malicious frontend cannot post arbitrary `fee_amount_minor`; the backend ignores or rejects client-provided fee amounts and uses server-calculated fees only.
- Tests prove invalid denomination totals are rejected.
- Tests prove idempotency catches mismatched replay payloads.
- Test proves the cash options catalog and request validation cannot drift for payment methods.

### FBI2-026: Authenticated Notification Feed

Source issue: `#26 Notifications / alertes`.

Severity: High.

Current contradiction:

- Assume the frontend notification bell can be backed by the API.
- The only notification route is notification consent creation.
- Existing alert/outbox services are internal producers and delivery infrastructure, not a user feed.
- Therefore the frontend has no backend source for notification list or read state.

Implementation:

- Add a persistent user-facing notification/read model.
- Add permissions:
  - `notifications.view` for agency-scoped notification consultation.
  - `notifications.manage` for administrative notification lifecycle operations.
- A normal authenticated user does not need `notifications.view` to read their own user-targeted notifications.
- Grant `notifications.view` to default `platform-admin`, `agency-manager`, and `teller` roles.
- Grant `notifications.manage` to default `platform-admin` only.
- Add endpoints:
  - `GET /api/v1/notifications`
  - `POST /api/v1/notifications/{notification}/read`
  - `POST /api/v1/notifications/read-all`
- Feed fields:
  - `public_id`
  - `type` (`info`, `success`, `warning`, `error`)
  - `category`
  - `title`
  - `message`
  - `action_url`
  - `agency_public_id`
  - `created_at`
  - `read_at`
  - `metadata`
- Scoping:
  - User-targeted notifications visible only to the target user.
  - Agency notifications visible only to current-agency users with `notifications.view`.
  - Platform notifications visible only to platform-admin.
  - A notification with both platform and user targets is visible to the target user only through the user-targeted record.
- Minimum feed-producing sources for this backlog:
  - teller session opened
  - teller session closed
  - cash deposit posted
  - cash withdrawal posted
  - teller transaction reversed
  - till reconciliation pending/rejected/accepted
  - report run generated
  - report run review approved/rejected
  - loan setup charges assessed
  - loan setup charge or insurance premium collected
  - loan ready for disbursement
- Support filters:
  - `filter[read]`
  - `filter[type]`
  - `filter[category]`
  - `filter[created_from]`
  - `filter[created_to]`
  - `search`
- Integrate the minimum feed-producing sources listed above with feed-row creation.
- Each feed row must store `source_type` and `source_public_id`; uniqueness on `(recipient_type, recipient_id, source_type, source_public_id, category)` prevents duplicate unread notifications for repeated producers.

Acceptance criteria:

- Authenticated user can list their unread and read notifications with pagination.
- Mark-as-read updates only notifications visible to the actor.
- Cross-user and cross-agency notification access returns 403 or 404 without leaking existence.
- Notification feed never returns demo/static data.
- Alert producers can create feed-visible notifications without duplicating unread rows on repeated batch execution.
- API docs describe notification types, categories, filters, and read semantics.
- Default role catalog and `/me` responses expose `notifications.view` for teller and agency manager after role sync.

Regression tests:

- Tests prove teller sees only their own/agency cash notifications.
- Tests prove platform-admin can see platform-targeted notifications.
- Tests prove read state is per user and does not mark another user's notification read.
- Tests prove a user without `notifications.view` can read their own notifications but cannot read agency-wide notifications not targeted to them.
- Tests prove event producers do not create duplicate unread feed rows for the same source event.

### FBI2-027: Replace Teller Dashboard Demo Dependencies With Backend Contracts(completed)

Source issue: `#27 Dashboard caissier sections maquettées`.

Severity: High.

Current contradiction:

- Assume the teller dashboard can remove demo placeholders after backend work.
- Recent operations depend on `#24`.
- Notifications depend on `#26`.
- Client/account preview depends on client/account/balance endpoints, including the separate access fix documented in `backlogs/frontend-crm-pii-and-teller-balance-access-fix-strategy.md`.
- Therefore the backend must deliver `#24`, `#26`, and the customer-account access fix together for a complete teller dashboard.

Implementation:

- Treat this as an integration closure ticket, not a separate demo endpoint.
- This ticket cannot be marked complete until the customer-account access fix is implemented and verified:
  - default teller can search same-agency clients;
  - default teller can see operational client identity/contact without full sensitive PII;
  - default teller can list same-agency customer accounts;
  - default teller can fetch same-agency available balance;
  - default teller cannot access cross-agency accounts, ledger balances, or full PII.
- Confirm teller role has the narrow permissions required for:
  - client search
  - operational client identity
  - customer account read
  - customer account balance read
  - teller transaction read
  - notification read
- Add a teller dashboard contract test that calls only real endpoints:
  - `GET /teller-sessions`
  - `GET /teller-transactions`
  - `GET /notifications`
  - `GET /clients`
  - `GET /customer-accounts`
  - `GET /customer-accounts/{id}/available-balance`

Acceptance criteria:

- No teller dashboard section requires placeholder backend data.
- A default teller can load all teller dashboard backend data for their current agency.
- The same teller cannot access cross-agency sessions, transactions, clients, accounts, balances, or notifications.
- No broad `ledger.accounts.view`, `accounting.audit.view`, or `crm.pii.view` grant is required for teller dashboard rendering.

Regression tests:

- End-to-end API test executes the teller dashboard fetch sequence as a default teller.
- Test proves removing any required narrow permission produces a clear 403 on the specific endpoint, not masked data or a 500.

### FBI2-028: Report Definition Catalog And Standard Seeds(completed)

Source issue: `#28 Rapports`.

Severity: Critical.

Current contradiction:

- Assume the frontend can generate reports from available report definitions.
- `POST /report-runs` requires `report_definition_public_id`.
- No `GET /report-definitions` route exists.
- Standard report definitions are not proven to be seeded; tests insert definitions directly.
- Therefore report generation is blocked unless the frontend has out-of-band IDs.

Implementation:

- Add `GET /api/v1/report-definitions`.
- Authorize with `accounting.audit.view`.
- Return active definitions by default.
- Support `include_inactive=true` for `platform-admin` only.
- Response fields:
  - `public_id`
  - `code`
  - `name`
  - `report_type`
  - `module`
  - `status`
  - `version`
  - `effective_from`
  - `effective_to`
  - `supported_parameters`
  - `requires_agency`
  - `requires_currency`
  - `requires_period`
  - `description`
- Support filters:
  - `filter[report_type]`
  - `filter[module]`
  - `filter[status]`
  - `search`
- Seed standard active definitions:
  - `trial_balance`
  - `general_ledger`
  - `emf_trial_balance`
  - `credit_portfolio_outstanding`
  - `credit_par_delinquency`
  - `credit_collection_performance`
- Standard seeds must be idempotent in the application seeder or a dedicated sync command. Re-running seeds must update known definitions without creating duplicate active versions.
- Do not add `main levée` report definitions in this ticket.
- Do not add PDF/CSV export in this ticket. The existing report-run summary remains the source for preview/print.

Acceptance criteria:

- `GET /report-definitions` returns seeded active standard definitions after fresh seed/sync.
- Frontend can take one returned `public_id` and successfully call `POST /report-runs`.
- Unsupported or inactive definitions cannot be used to generate reports.
- Filtering and search work with pagination.
- Non-authorized users receive 403.
- API docs document the catalog and relationship to `POST /report-runs`.

Regression tests:

- Fresh database seed test proves standard report definitions exist.
- Re-running the seeder/sync command does not create duplicate active definitions.
- Feature test proves report generation works using a definition discovered from the catalog.
- Test proves no stale or duplicate report definition versions are returned as the active default.

### FBI2-029: Filterable Teller Session And Reconciliation Consultation

Source issue: `#29 Consultation caisse / Sessions de caisse`.

Severity: Critical.

Current contradiction:

- Assume the consultation page can find historical sessions at scale.
- `TellerSessionWorkflow::index()` supports pagination and generic `search`.
- It does not support exact business-date, date range, till, teller, status, or sort filters.
- The frontend workaround fetches many pages and filters client-side, which fails at scale.
- Therefore the backend does not satisfy the consultation contract.

Implementation:

- Extend `GET /api/v1/teller-sessions` with exact filters:
  - `filter[business_date]`
  - `filter[business_date_from]`
  - `filter[business_date_to]`
  - `filter[till_public_id]`
  - `filter[teller_user_public_id]`
  - `filter[status]`
  - `filter[agency_public_id]` for platform-admin
  - `sort` with allowed values: `business_date`, `-business_date`, `opened_at`, `-opened_at`, `closed_at`, `-closed_at`, `status`
- Keep existing `search` as a broad helper, not a substitute for exact filters.
- Add `GET /api/v1/till-reconciliations` as a standalone filtered index.
- Standalone reconciliation filters:
  - `filter[teller_session_public_id]`
  - `filter[till_public_id]`
  - `filter[teller_user_public_id]`
  - `filter[business_date]`
  - `filter[business_date_from]`
  - `filter[business_date_to]`
  - `filter[status]`
  - `filter[agency_public_id]` for platform-admin
- Preserve nested `GET /teller-sessions/{id}/reconciliations`.

Acceptance criteria:

- Querying a two-year dataset by exact date returns the matching session without fetching unrelated pages.
- Date-range, till, teller, status, and agency filters compose correctly.
- Sort is deterministic and documented.
- Non-platform actors cannot query another agency through `agency_public_id`, till, teller, or session filters.
- Standalone reconciliation index returns the same rows as the nested endpoint when filtered by a session.
- Pagination metadata remains stable and `per_page` remains capped.

Regression tests:

- Tests create more than 100 sessions and prove an older session is found by date filter on the first response page.
- Tests prove cross-agency filter attempts do not leak row existence.
- Tests prove standalone and nested reconciliation lists agree for the same session.

### FBI2-030: Reloadable Loan Setup Charge And Insurance Premium State(completed)

Source issue: `#30 Décaissement prêt — frais de dossier`.

Severity: Critical.

Current contradiction:

- Assume the frontend can guide disbursement through assess, collect charges, collect insurance, then disburse.
- Mutation endpoints exist for assess/collect.
- The only readback of assessed charges/premiums is the immediate assess response.
- `DisburseLoan::ensureSetupSatisfied()` blocks disbursement until charges/premiums are assessed and collected.
- Therefore after refresh/navigation the frontend cannot reliably know what remains to collect before disbursement.

Implementation:

- Add `GET /api/v1/loans/{loan}/setup-charges`.
- Return:
  - loan public ID and status
  - setup readiness status
  - charge assessments
  - insurance premium assessments linked to the loan
  - collected payments
  - direction waiver decisions
  - required next actions
- Include every charge field required for the UI:
  - `public_id`
  - `charge_type`
  - `assessed_amount_minor`
  - `currency`
  - `status`
  - `paid_at`
  - `journal_entry_public_id`
  - `waiver_decision`
  - `collectable`
  - `blocking_disbursement`
- Include insurance premium fields:
  - `public_id`
  - `premium_amount_minor`
  - `currency`
  - `status`
  - `payments`
  - `blocking_disbursement`
- Add `include_setup_charges=true` on `GET /loans/{loan}` and make it reuse the same serializer as `GET /loans/{loan}/setup-charges`.
- Keep assess idempotent and safe to call repeatedly, but the frontend must not need to reassess just to read state.
- Add a single readiness service used by both the GET endpoint and `DisburseLoan::ensureSetupSatisfied()`. Do not duplicate readiness rules in controller/resource code.

Acceptance criteria:

- Before assessment, setup-state GET returns explicit `not_assessed` readiness and required next action.
- After assessment, GET returns all assessed charges and insurance premiums.
- After collecting one charge, GET reflects that charge as paid while other charges remain blocking.
- Direction waiver decisions are visible and included in readiness calculation.
- After all required charges/premiums are collected or waived, GET returns `ready_for_disbursement=true`.
- Disbursement uses the same readiness calculation as the GET endpoint, so UI readiness and backend enforcement cannot drift.
- Agency scoping and loan permissions match `GET /loans/{loan}`.
- Re-running setup-charge assessment does not duplicate existing assessed charges or insurance premium assessments.

Regression tests:

- Test covers full setup lifecycle with refresh-style GETs between each mutation.
- Test proves disbursement is rejected when GET says not ready and succeeds when GET says ready.
- Test proves cross-agency users cannot inspect setup charges.
- Test proves repeated assessment is idempotent and preserves existing collected/waived state.

### FBI2-031: Agency-Scoped Ledger Mapping For Global Loan Products

Source issue: `#31 Produit de prêt GLOBAL vs comptes comptables PAR AGENCE`.

Severity: Critical.

Current contradiction:

- Assume a global loan product can be used by multiple agencies.
- `DisburseLoan` requires `loanProduct->ledger_account_id`.
- It then rejects the disbursement unless that ledger account belongs to the loan agency.
- A single global product can store only one `ledger_account_id`, so it cannot satisfy multiple agency ledgers.
- Therefore global products and agency-specific charts of accounts are incompatible for disbursement.

Implementation:

- Stop relying on `loan_products.ledger_account_id` as the only source for loan principal ledger resolution.
- Resolve agency-specific posting ledgers through approved active `operation_account_mappings`.
- Standardize operation codes for loan postings:
  - `loan_principal_disbursement`
  - `loan_cash_disbursement`
  - `loan_transfer_disbursement`
  - `loan_setup_dossier_fee`
  - `loan_setup_tax`
  - `loan_setup_guarantee_deposit`
  - `loan_insurance_premium`
  - repayment/recovery operation codes already used by loan workflows.
- Require mapping lookup to include:
  - operation code
  - agency ID
  - currency
  - active status
  - approved approval status
  - effective date window
  - active ledger accounts in the same agency
- Update `OperationAccountMappingController` and requests to support:
  - `agency_public_id`
  - debit ledger create/update
  - credit ledger create/update
  - effective dates
  - approval status workflow compatibility
- Enforce uniqueness or conflict detection for active approved mappings by operation code, agency, currency, and effective window.
- Keep product-level ledger fields only as legacy/default metadata. New posting code must prefer agency operation mappings.
- Add `GET /api/v1/operation-account-mappings/readiness` so frontend can show which agencies/products cannot disburse before the user reaches final disbursement.
- The mapping resolver must be a shared service used by loan disbursement, setup-charge collection, insurance-premium collection, teller non-cash tenders, and FX workflows that already depend on operation mappings.

Acceptance criteria:

- A global loan product can disburse loans in agency A and agency B using different agency-specific loan principal ledgers.
- Disbursement fails with a clear 422 when the required agency/currency mapping is missing, inactive, unapproved, expired, or points to a cross-agency/inactive ledger.
- Existing single-agency products continue to work when a valid mapping exists.
- Setup charge and insurance premium collection use the same mapping resolver rules as disbursement.
- Teller cash loan disbursement uses agency-specific cash/till and loan principal ledgers without cross-agency leakage.
- Mapping create/update APIs can configure agency-specific mappings without raw DB edits.
- Mapping readiness endpoint reports missing, inactive, unapproved, expired, cross-agency, and overlapping mappings separately.
- API docs describe required mappings for loan disbursement and setup collection.

Regression tests:

- Test proves agency B can disburse with the same global product after configuring agency B mappings.
- Test proves agency B disbursement fails before mapping setup with a precise missing-mapping error.
- Test proves a mapping with agency A ledger cannot be used by agency B.
- Test proves overlapping active approved mappings are rejected or deterministically resolved according to the defined uniqueness rule.

## Cross-Cutting Regression Requirements

- Preserve all v1 frontend integration fixes:
  - Every issue `FBI-001` through `FBI-023` in `backlogs/frontend-integration-backend-issues-backlog.md` must remain satisfied.
  - Role permission read-after-write consistency and protected-permission metadata must remain covered because v2 adds new permissions.
  - Client PII redaction and customer-account balance access must remain covered because teller dashboard depends on them.
  - Document retrieval must remain covered because notification/report/loan setup flows may link documents.
  - Loan approvals, active schedule reload, setup-charge state, linked-account update, and client loan filter must remain covered because v2 extends the loan disbursement workflow.
- No endpoint added in this backlog may return demo/static data.
- Every new list endpoint must support pagination with stable `meta.pagination`.
- Every new list endpoint must enforce agency scope before filters are applied.
- Every endpoint that accepts `filter[...]` must reject unsupported filters with 422. Do not silently treat misspelled security filters as broad queries.
- Every financial posting endpoint must remain idempotent where idempotency already exists.
- Every new route must be included in API docs and frontend integration tests.
- Do not use broad permissions such as `ledger.accounts.view`, `accounting.audit.view`, or `crm.pii.view` to solve teller/front-office UI gaps when a narrower endpoint is the right contract.

## Verification Commands

Focused implementation tests should use parallel artisan tests with filters, for example:

- `php artisan test --parallel --recreate-databases --filter Module5CashInfrastructureTest`
- `php artisan test --parallel --recreate-databases --filter Module4CreditLoansTest`
- `php artisan test --parallel --recreate-databases --filter RegulatoryReportingTest`
- `php artisan test --parallel --recreate-databases --filter NotificationsFoundationTest`

Full backend gate:

- `composer test`
- `vendor/bin/phpstan analyze`
- `vendor/bin/pint --test`
- `php artisan scramble:export --path=public/docs/api.json`

Frontend-contract gate:

- Run the existing external integration suite that previously covered the first feedback backlog.
- Add v2 scenarios for `FBI2-024` through `FBI2-031`.
- The v2 suite must prove no section depends on placeholder data and no old issue regresses.

## Full Implementation Sequence

This is an execution sequence, not a partial release plan. The v2 backlog is complete only when every ticket and cross-cutting regression requirement above is implemented and verified.

1. Add report-definition catalog and standard seeds (`FBI2-028`) because it is independent and unblocks report UI.
2. Add teller transaction list and teller session summaries (`FBI2-024`).
3. Add teller-session and reconciliation filters (`FBI2-029`).
4. Add notification feed (`FBI2-026`).
5. Close teller dashboard real-data contract (`FBI2-027`).
6. Add loan setup-charge read model (`FBI2-030`).
7. Add agency-scoped loan posting mapping resolver (`FBI2-031`).
8. Extend deposit/withdrawal tender payloads (`FBI2-025`) using the same mapping resolver and teller summary contracts.
9. Run focused, full, static-analysis, formatting, API-doc, and frontend-contract gates.

## Definition Of Done

- Every new issue in `back-issues-v2.md` has an implemented backend contract.
- The frontend can remove demo placeholders for teller recent operations and notifications.
- Historical cash/session consultation works by server filters, not client page-walking.
- Report generation starts from backend-discovered active report definitions.
- Loan disbursement setup-charge state is reloadable and matches backend disbursement enforcement.
- Global loan products can be used across agencies through agency-specific approved operation mappings.
- Existing v1 fixes do not regress.
- All acceptance criteria, regression tests, API docs, and verification commands pass.
