# Journee Comptable Implementation Backlog

Date: 2026-06-01

Scope: codebase-wide investigation and remediation backlog for the missing `journee comptable` concept described in `stakeholderResources/journee-comptable.md`.

Method: proof by contradiction. Assume the current system correctly implements `journee comptable`; then every write that records a business event must be dated by the currently open accounting day, and once that day is closed, users may consult data but cannot register new business events. The current code contradicts that assumption in multiple modules.

## Adversarial Evidence Boundaries

This backlog deliberately separates three kinds of statements:

- Stakeholder facts: direct implications from `stakeholderResources/journee-comptable.md`.
- Code facts: behavior observed in current application code, migrations, routes, and tests.
- Proposed controls: implementation recommendations required to make the stakeholder rule enforceable in a Laravel API.

Some implementation defaults below are inferred controls, not direct stakeholder quotes. They are still decisions for the implementation backlog: build them as specified unless accounting/product explicitly override them before implementation starts.

The proposed lifecycle tables, guard service, route classifications, and database triggers are not existing requirements from the stakeholder text. They are implementation controls inferred from the stakeholder rule because the current architecture is API-driven, multi-module, and already derives balances from `journal_entries.business_date`.

## Implementation Decisions

These decisions remove ambiguity from the implementation backlog while keeping override points explicit.

1. Scope: implement `journee comptable` as agency-scoped, with optional institution-level views.
   Rationale: the current code is heavily agency-scoped (`agency_id` on journals, teller sessions, batches, loans, accounts, ledgers). Agency-scoped days allow one branch to resolve blockers without freezing all branches. Institution dashboards can aggregate agency days and flag inconsistent close states.

2. Closed-day write policy: block all authenticated registration writes by default, including non-financial setup writes, unless the route is explicitly allowlisted.
   Rationale: the stakeholder said all registration rights are blocked. The safest implementation is fail-closed with a small allowlist for authentication, read-only consultation, accounting-day lifecycle, and emergency maintenance.

3. Closing state: use a distinct `closing` status that blocks ordinary registrations while controls run.
   Rationale: if the day remains writable during close verification, a write can sneak in after controls pass but before final close. `closing` makes close atomic from the operator's perspective.

4. Reopen policy: allow reopen only through an emergency maker-checker flow with reason, dual authorization, and audit trail.
   Rationale: reopen is operationally necessary for exceptional correction, but it is a high-risk control break. It must not be equivalent to ordinary posting permission.

5. Correction dating: normal corrections post to the current open accounting day as reversal/adjustment entries; posting into the original closed day requires reopen.
   Rationale: this preserves closed-day reproducibility. The original closed day changes only through an explicitly audited reopen.

6. Holiday scope: implement holiday/calendar configuration as agency-scoped with an institution default fallback.
   Rationale: Cameroon public holidays may be common, but branch operations can still differ by locality or operational exception. Fallback avoids duplicating common calendars.

7. Next-day opening: require manual opening of the next accounting day after successful close.
   Rationale: the stakeholder explanation says users open a day, close it, then open a new day. Manual opening also forces accounting to confirm holidays and readiness instead of silently rolling forward.

## Stakeholder Rule

From `stakeholderResources/journee-comptable.md`:

- The accounting day defines the business day; it is not automatically the calendar day.
- Cameroon has accounting days and holidays; the system must support non-calendar posting days.
- Events are dated by the accounting day, not always by wall-clock date.
- A user opens a `journee comptable`, records events during it, closes it in the evening, then opens the next accounting day later.
- Once the accounting day is closed, users may log in and consult data, but all registration/write rights are blocked across the system.

Related stakeholder material:

- The stakeholder questionnaire in `stakeholderResources/`, section 19, says daily close is performed by batch.
- `docs/domain/stakeholder-formula-responses.md` currently says daily/cumulative accounting reports use posting/business date and batch business date as the day-close boundary. That remains compatible only if `business_date` becomes controlled by an actual accounting day lifecycle.

## Current Evidence

### No Authoritative Accounting Day Exists

- No `accounting_days`, `business_days`, holiday calendar, or current accounting-day model exists in `app/`, `database/migrations/`, `routes/`, or tests.
- Search evidence: only free `business_date` usage, batch date fields, and documentation references exist.

### Current Writes Accept Or Infer Dates Independently

- Manual journal creation requires caller-provided `business_date`: `app/Http/Requests/StoreJournalEntryRequest.php` and `app/Application/JournalEntries/JournalEntryWorkflow.php`.
- Draft journal update can change `business_date`: `app/Http/Requests/UpdateJournalEntryRequest.php`.
- Teller session opening requires caller-provided `business_date`: `app/Http/Requests/StoreTellerSessionRequest.php` and `app/Application/CashOperations/TellerSessionWorkflow.php`.
- Cash deposits, withdrawals, and manual cash journals inherit the teller session `business_date`, but that date itself is not tied to a controlled accounting day.
- Batch runs accept caller-provided `business_date`: `app/Application/BatchRuns/BatchRunWorkflow.php`.
- FX transactions can accept `transaction_date`, otherwise default to `now()->toDateString()`: `app/Application/FxExchange/FxTransactionWorkflow.php`.
- FX reversals use `now()->toDateString()` instead of an open accounting day: `app/Application/FxExchange/FxTransactionWorkflow.php`.
- Insurance accounting uses `now()->toDateString()` or workflow-specific paid dates in multiple posting paths: `app/Application/Insurance/InsuranceAccountingService.php`, `app/Application/Insurance/InsurancePremiumWorkflow.php`, and `app/Application/Insurance/InsuranceClaimWorkflow.php`.
- Loan disbursement and repayment accept workflow/request business dates: `app/Application/Loans/DisburseLoan.php`, `app/Application/Loans/LoanRepaymentWorkflow.php`, and `app/Application/Loans/RecordLoanRepayment.php`.
- Islamic finance posting paths use `now()->toDateString()` or event dates as journal `business_date`: `app/Application/IslamicFinance/IslamicFinancingWorkflow.php`, `app/Application/IslamicFinance/IslamicSalamGoodsWorkflow.php`, and `app/Application/IslamicFinance/IslamicTreatmentWorkflow.php`.
- HR payroll posting uses payroll period end for original journals and `now()->toDateString()` for reversals: `app/Application/HrPayroll/HrPayrollRunWorkflow.php`.

### Current Close Verification Is Not Day Closure

- `app/Application/BatchRuns/ExecuteAccountingCloseVerificationBatch.php` checks blocking journals for a `business_date`, but it does not close an accounting day record and does not block subsequent writes.
- `app/Application/BatchRuns/ExecuteCashCloseVerificationBatch.php` verifies teller-session close conditions for a date, but it does not enforce institution-wide or agency-wide registration lockout.
- `app/Application/BatchRuns/UpdateBatchRunStatus.php` updates a batch run only; it does not transition any accounting-day state.

### Current Read Models Depend On Business Date

- `app/Application/Accounting/AccountingBalanceWorkflow.php` derives movements and statements from posted journal entries ordered and filtered by `journal_entries.business_date`.
- This makes authoritative `business_date` governance foundational. If `business_date` is free-form, statements can be correct structurally but wrong operationally.

## Proof By Contradiction

### Contradiction 1: If `journee comptable` exists, a closed day blocks all registration. But current write routes remain open.

Assume the current system implements `journee comptable`. After the accounting team closes 2026-06-01, no new deposits, withdrawals, manual journals, loan disbursements, repayments, insurance postings, FX transactions, account holds, account openings, KYC registrations, or setup records that constitute registration may be accepted.

Contradiction: there is no shared gate, middleware, service, database invariant, or model state checked across write endpoints. Closing a batch run can succeed or fail, but it never changes a day state that write workflows consult.

### Contradiction 2: If accounting day is not the calendar day, event dating cannot default to `now()`. But several workflows do.

Assume the open accounting day is 2026-06-03 while wall-clock date is 2026-06-04 due to late processing or a holiday. New postings must carry accounting date 2026-06-03.

Contradiction: FX, insurance, Islamic finance, and HR payroll reversal paths use `now()->toDateString()` in posting/reversal paths. Those events will be dated by calendar time, not the open accounting day.

### Contradiction 3: If a user opens the day once, business users should not choose arbitrary posting dates per operation. But current APIs let them.

Assume an agency has an open accounting day for 2026-06-05. A user should be unable to create a teller session, journal, batch, or operational transaction for 2026-06-04 or 2026-06-06 unless an explicit controlled adjustment/back-office process exists.

Contradiction: journal, teller session, batch, loan, insurance, FX, Islamic finance, and HR payroll flows accept dates from requests, event records, payroll periods, or workflow inputs without resolving accounting dates against a current open accounting day.

### Contradiction 4: If day close is a system state, reporting close evidence must be auditably tied to that state. But close verification is just another batch result.

Assume the accounting team asks: "Who opened 2026-06-01? Who closed it? What controls passed? Why are writes blocked?" The system should answer from an accounting-day audit trail.

Contradiction: batch runs record procedure, status, date, and summary, but no `accounting_day` aggregate stores lifecycle, opener, closer, holiday rollover, close controls, override reason, or write-lock decision.

## Target Domain Model

### AccountingDay

Create an authoritative accounting-day aggregate.

Suggested table: `accounting_days`

Core fields:

- `id`, `public_id`
- `scope_type`: `institution` or `agency`
- `agency_id`: nullable for institution scope
- `business_date`: date the accounting day represents
- `calendar_opened_at`, `calendar_closed_at`
- `status`: `planned`, `open`, `closing`, `closed`, `reopened`, `cancelled`
- `is_holiday`: boolean
- `holiday_name`: nullable string
- `opened_by_user_id`, `closed_by_user_id`, `reopened_by_user_id`
- `opening_batch_run_id`, `closing_batch_run_id`
- `close_summary_payload`: json
- `close_failure_reason`: nullable text
- `reopen_reason`: nullable text
- `write_lock_version`: integer for optimistic enforcement/audit
- timestamps

Recommended uniqueness:

- Unique `scope_type`, `agency_id`, `business_date`.
- Partial unique open day per scope: only one `open` or `closing` accounting day per institution/agency scope.

Recommended indexes:

- `status`, `business_date`, `agency_id`, `calendar_opened_at`, `calendar_closed_at`.

### AccountingCalendarDay

Create a calendar/holiday planning table so accounting can define non-working days and next accounting dates.

Suggested table: `accounting_calendar_days`

Core fields:

- `id`, `public_id`
- `scope_type`, `agency_id`
- `calendar_date`
- `business_date`: nullable when no accounting day should open
- `is_business_day`
- `is_holiday`
- `holiday_name`
- `notes`
- `created_by_user_id`, `approved_by_user_id`, `approved_at`
- timestamps

### AccountingDayGuard

Create a shared application service that every registration workflow uses.

Required behavior:

- Resolve the current open accounting day for the actor's scope.
- Return the authoritative `business_date` for event dating.
- Reject all registration writes when no open day exists.
- Reject all registration writes when the scoped day is `closing` or `closed`.
- Reject request-supplied business dates that differ from the open day, except for explicitly designed correction/backdate flows with stronger permission and audit.
- Emit a consistent error payload so frontends can tell users the system is in consultation-only mode.

Suggested API:

- `currentOpenForActor(User $actor, ?int $agencyId): AccountingDay`
- `assertCanRegister(User $actor, string $operation, ?int $agencyId): AccountingDay`
- `resolveBusinessDate(User $actor, string $operation, ?int $agencyId, ?string $requestedDate = null): string`
- `assertCanClose(AccountingDay $day): CloseReadinessResult`

### Registration Boundary

Registration means any action that creates or mutates business records, financial records, operational state, or customer-facing status. Consultation means read-only access.

By default, block when the accounting day is not open:

- Journal entry create/update/submit/approve/post/reverse/cancel and journal-line create/update/delete.
- Cash teller session open/close, cash deposit, withdrawal, manual journal, reconciliation, transaction reversal.
- Loan creation changes that register financial state, approvals that move financial workflow, disbursement, repayment, recovery, reschedule, transfer, arrears/penalty batch postings.
- Account creation/update that changes active account state, account holds, hold releases.
- Insurance premium collection/reversal, claim settlement, remittance approval, policy cancellation accounting.
- FX transaction, reversal, stock movement, cash reconciliation.
- Batch execution when the procedure creates or mutates financial/operational state.
- CRM/KYC and staff/admin writes if the stakeholder's "tout droits d'enregistrement" is interpreted literally across the system.

Allowed while closed by default:

- Authentication and token refresh.
- Read-only list/show/report endpoints.
- Export/download of existing data.
- Operational dashboards if they do not create records.
- Accounting-day opening for the next authorized day.
- Emergency admin-only reopen with dual control.

Implementation policy:

- Non-financial setup writes, such as reference data, permissions, product configuration, KYC document upload, notification templates, and staff profile edits, are blocked by the same day lock unless explicitly allowlisted. This follows the stakeholder phrase that all registration rights are blocked across the system.

## Implementation Backlog

### JC-001: Build accounting day and calendar persistence

Severity: Critical

Problem: There is no authoritative domain object for open/closed accounting days or holidays.

Implementation:

- Add `accounting_days` migration, model, factory, policy, resource, and feature tests.
- Add `accounting_calendar_days` migration, model, policy, resource, and feature tests.
- Add status constants and valid transition guards.
- Implement agency-scoped accounting days with institution-level aggregate views and an institution default calendar fallback.
- Seed initial calendar/open-day data only through explicit admin command or documented migration-safe bootstrap.

Acceptance criteria for stakeholders:

- The system has a visible accounting day with date, status, opener, close state, and holiday information.
- A holiday can be configured so the next accounting date can differ from the next calendar date.
- Users can understand whether the system is in registration mode or consultation-only mode.

Acceptance criteria for accounting team:

- Only one active open accounting day exists per configured scope.
- Closed days are immutable except through a controlled reopen process.
- Accounting can audit who opened and closed each day and when.
- Holiday/non-business-day configuration is visible and reportable.

Acceptance criteria for developers:

- Migrations include database constraints for uniqueness and valid status values.
- Models expose typed constants/casts and route keys by `public_id`.
- Tests prove duplicate open days per scope are rejected.
- Tests prove a closed day cannot be reopened without a reason and permission.

Acceptance criteria for security reviewer:

- Policies restrict opening, closing, and reopening to dedicated permissions.
- API responses never leak internal numeric IDs.
- Lifecycle actions are audit logged with actor, scope, previous status, new status, business date, reason, and request metadata.
- Race tests or database constraints prove two concurrent opens cannot create two open days.

### JC-002: Add accounting day lifecycle APIs

Severity: Critical

Problem: Users need to open and close the accounting day explicitly; current batch runs do not own a day lifecycle.

Implementation:

- Add routes under `/api/v1/accounting-days`:
  - `GET /accounting-days`
  - `GET /accounting-days/current`
  - `POST /accounting-days/open`
  - `POST /accounting-days/{accountingDay}/start-close`
  - `POST /accounting-days/{accountingDay}/close`
  - `POST /accounting-days/{accountingDay}/reopen`
- `open` resolves the next allowed business date from the calendar unless a privileged user supplies an approved business date.
- `start-close` sets status to `closing` and immediately blocks ordinary registrations while close checks run.
- `close` requires all configured close controls to pass.
- `reopen` requires elevated permission, reason, audit evidence, and preferably maker-checker approval if accounting approves reopening as an allowed operation.

Acceptance criteria for stakeholders:

- The day can be opened in the morning and closed in the evening using explicit actions.
- Once close starts, users see a clear consultation-only state.
- The next day can be opened after close, including after holidays.

Acceptance criteria for accounting team:

- Closing shows blocking reasons: unposted journals, open teller sessions, pending transactions, unreconciled cash, failed required batches, and any module-specific blockers.
- Accounting can retry close after resolving blockers without changing the accounting date.
- Reopen is exceptional, reasoned, and traceable.

Acceptance criteria for developers:

- Lifecycle actions are idempotent where safe: repeated open/current calls return the existing day; repeated close on an already closed day returns current state.
- Invalid transitions return 422 with stable machine-readable error codes.
- API resources include `can_register`, `status`, `business_date`, `scope`, and `close_summary`.

Acceptance criteria for security reviewer:

- Opening, closing, and reopening are protected by separate permissions.
- Reopen requires stronger permission than close.
- Close/reopen endpoints are throttled and audit logged.
- Reopen cannot silently modify posted journals; corrections must use explicit reversal/adjustment flows.

### JC-003: Implement a global registration guard

Severity: Critical

Problem: Every write workflow currently decides its own date/write state, so a closed accounting day cannot block registration across the system.

Implementation:

- Add `AccountingDayGuard` in support/application layer.
- Add middleware for generic write-route blocking where safe.
- Add explicit service calls inside financial workflows where the authoritative business date must be returned.
- Define an allowlist for writes that may occur while closed: auth/logout/token revocation, open next accounting day, emergency reopen, and selected system maintenance if approved.
- Add consistent error response, for example `accounting_day_closed`, `accounting_day_missing`, `accounting_day_closing`, `accounting_day_mismatch`.

Acceptance criteria for stakeholders:

- After day close, users can log in and consult data but cannot register new operations.
- Error messages explain that the accounting day is closed and identify the current/last accounting date.

Acceptance criteria for accounting team:

- No financial operation can be posted to a closed day through normal APIs.
- No user can bypass the closed-day state by supplying another date.
- The guard records attempted blocked registrations for audit review.

Acceptance criteria for developers:

- A single reusable guard enforces open-day status and resolves the business date.
- Tests cover guard behavior for no day, open day, closing day, closed day, and mismatched requested date.
- Existing workflows stop using `now()->toDateString()` for accounting dates.
- Existing tests are updated to create/open an accounting day instead of passing arbitrary dates where registration is involved.

Acceptance criteria for security reviewer:

- The guard fails closed when the accounting-day lookup is ambiguous or unavailable.
- The guard cannot be bypassed by direct route access, idempotency replay, or alternate source-module endpoints.
- Blocked write attempts are audit logged without logging sensitive payload contents.
- Database-level invariants cover critical posted journal paths so a missed application check cannot create posted entries for closed days.

### JC-004: Make journal entries authoritative accounting-day records

Severity: Critical

Problem: `journal_entries.business_date` is the foundation for balances and statements, but it is currently free-form.

Implementation:

- Add `accounting_day_id` to `journal_entries` with a foreign key to `accounting_days`.
- Backfill existing rows by matching scope and `business_date`; create historical closed day records if needed.
- For all new entries, set `business_date` from `accounting_days.business_date` and store `accounting_day_id`.
- Prevent changing `business_date` on drafts except through a controlled reassign operation before submission and only if both source and target days are open/authorized.
- Posting must verify the linked accounting day is open, or that the journal is an approved correction allowed by reopen/adjustment policy.

Acceptance criteria for stakeholders:

- Accounting movements show the accounting day that controlled the posting.
- Reports no longer depend on arbitrary operator-entered dates.

Acceptance criteria for accounting team:

- A journal cannot be posted if its accounting day is closed.
- Reversals and adjustments are traceable to the day they are actually registered, with a link to the original journal.
- Existing historical journals remain reportable after migration.

Acceptance criteria for developers:

- Journal API response includes `accounting_day_public_id` and `business_date`.
- `StoreJournalEntryRequest` no longer requires arbitrary `business_date`; if accepted for compatibility, it must equal the open accounting day.
- `UpdateJournalEntryRequest` cannot freely change `business_date`.
- Feature tests cover manual journal create, submit, approve, post, reverse, and cancel under open/closed day states.

Acceptance criteria for security reviewer:

- Direct database constraints or triggers prevent posted journals linked to closed days unless an explicit correction flag and permission path is used.
- Audit events include `accounting_day_public_id` for journal lifecycle transitions.
- Reversal/correction permissions are separated from ordinary posting.

### JC-005: Integrate teller sessions and cash operations with accounting day

Severity: Critical

Problem: Teller sessions use a request-supplied `business_date`; cash operations inherit that uncontrolled date.

Implementation:

- Add `accounting_day_id` to `teller_sessions`, `teller_transactions`, and `till_reconciliations` if needed for direct traceability.
- Opening a teller session must use the current open accounting day; reject request-supplied different dates.
- Deposits, withdrawals, manual cash journals, reversals, and reconciliations must call the guard before writing.
- Accounting-day close must require all teller sessions in scope to be closed, no pending manual cash journals, and required reconciliations completed.
- Till `daily_state` remains per till, but it cannot be the source of truth for institution/agency registration state.

Acceptance criteria for stakeholders:

- Tellers cannot continue registering deposits or withdrawals after the accounting day is closed.
- Tellers can still consult their past sessions and transactions after close.

Acceptance criteria for accounting team:

- Close readiness lists open teller sessions and pending cash transactions by till/teller.
- Cash movements for a day reconcile to the same accounting day as journal postings.
- A teller cannot open a session for a holiday or arbitrary future/past business date.

Acceptance criteria for developers:

- Teller session tests create an open accounting day and assert sessions inherit its date.
- Deposit/withdrawal/manual-journal tests prove closed-day rejection.
- Reversal tests decide and assert whether reversal uses current open day or requires day reopen; default should use current open day as a new accounting event.

Acceptance criteria for security reviewer:

- Teller cannot bypass lockout with an already-open teller session after `AccountingDay` moves to `closing` or `closed`.
- Idempotency replay for a transaction from an open day returns the prior result, but altered replays after close cannot create new writes.
- Blocked teller attempts are audit logged with teller, till, agency, operation type, and accounting day.

### JC-006: Integrate loans, credit workflows, and recovery batches

Severity: Critical

Problem: Loan disbursement, repayment, recovery, arrears, and rescheduling use request/batch dates but do not validate against an open accounting day.

Implementation:

- Add guard calls to disbursement, repayment, early repayment, recovery, reschedule, loan transfer, status transitions that register financial state, and arrears/penalty posting workflows.
- Batch-created loan events must be tied to an explicit accounting day appropriate for the procedure. Mutating batches must not post into a closed day unless an approved correction/reopen path exists.
- Disbursement and repayment journals must link to `accounting_day_id`.
- Decide whether loan setup/application edits are blocked by closed day. Safe default: block all writes unless allowlisted.

Acceptance criteria for stakeholders:

- Loan operations are dated by the open accounting day, not the operator's local calendar date.
- After close, no disbursement or repayment can be registered.

Acceptance criteria for accounting team:

- Loan financial journals reconcile with the accounting day close report.
- Recovery and arrears batches cannot mutate a closed day after closure.
- Exceptions/backdated corrections require reopen or formal adjustment with audit.

Acceptance criteria for developers:

- Feature tests cover disbursement, repayment, recovery, and arrears batch under open and closed day states.
- Idempotency conflict checks include accounting day identity where applicable.
- Existing date parameters are either removed from public financial write APIs or validated as equal to the open day.

Acceptance criteria for security reviewer:

- Users cannot use loan endpoints to bypass closed-day write lock even if they lack accounting permissions.
- Automated/batch actors have explicit service identity and scoped permission for day-bound mutation.
- All loan financial audit events include accounting-day identity.

### JC-007: Integrate insurance and FX posting workflows

Severity: High

Problem: Insurance and FX posting paths currently use `now()` or caller dates; both can contradict non-calendar accounting days.

Implementation:

- Replace `now()->toDateString()` business-date defaults with `AccountingDayGuard` resolution.
- Add `accounting_day_id` to FX transactions, FX stock movements/reconciliations, insurance premium payments, claim settlements, remittance batches, cancellations/refunds where those records generate or depend on journals.
- Validate supplied `transaction_date`, `movement_date`, or `business_date` against the current open accounting day for posting flows.
- Keep economic/effective dates distinct from accounting dates. For example, an FX rate `effective_on` or insurance policy `effective_on` is not necessarily a posting date and should not be blocked unless it is a registration write after close.

Acceptance criteria for stakeholders:

- FX and insurance events appear on the accounting day that was open when registered.
- Business/effective dates remain meaningful without corrupting accounting close.

Acceptance criteria for accounting team:

- Premium collections, claim settlements, remittances, FX transactions, and reversals cannot post after close.
- Reports can separate accounting date from operational/effective date.

Acceptance criteria for developers:

- Tests cover FX transaction default date, FX reversal date, insurance premium cash collection, insurance claim settlement, and remittance close under open/closed days.
- Data model differentiates `accounting_day_id/business_date` from `transaction_date/effective_on/paid_on`.

Acceptance criteria for security reviewer:

- Date override permissions are not available to ordinary operators.
- Reversal paths cannot become a closed-day bypass.
- Audit logs identify both original event date and accounting day where relevant.

### JC-008: Integrate batch close with accounting day close

Severity: Critical

Problem: Batch runs verify conditions by date but do not close or lock the day.

Implementation:

- Add required close-control registry for each accounting-day scope.
- Accounting-day `start-close` creates or links required batch runs for the target day.
- `ExecuteAccountingCloseVerificationBatch` and `ExecuteCashCloseVerificationBatch` write results into `accounting_days.close_summary_payload` or a normalized `accounting_day_close_controls` table.
- Day moves from `closing` to `closed` only when required controls pass.
- Failed close leaves day in `closing` or returns it to `open` based on accounting team's operational preference. Safe default: `closing` blocks new registrations until an authorized user explicitly resumes/open-for-correction.

Acceptance criteria for stakeholders:

- The evening close is a single understandable process, not disconnected batch records.
- If close fails, users see why and what must be fixed.

Acceptance criteria for accounting team:

- Close cannot succeed with draft/submitted/approved unposted journals, open teller sessions, pending cash transactions, unreconciled closed sessions, or failed mandatory batches.
- Close produces a final summary with counts and links to blockers.
- Accounting can prove that no writes occurred after close started except allowed close-control updates.

Acceptance criteria for developers:

- Batch APIs cannot create arbitrary close runs for a date unrelated to an accounting day unless explicitly authorized for historical audit.
- `BatchRun` links to `accounting_day_id`.
- Tests cover close success, close failure, retry, duplicate close protection, and race conditions.

Acceptance criteria for security reviewer:

- Close-control execution cannot be spoofed by manually marking a batch run `succeeded` unless the actor has close-control permission and the control evidence is valid.
- Batch status mutation is audited and tied to day lifecycle.
- Running close uses locks to prevent writes racing between control checks and final close.

### JC-009: Apply closed-day blocking across non-accounting registration APIs

Severity: High

Problem: The stakeholder said all registration rights are blocked across the system, not only journal posting. Therefore, the implementation must classify every route and block non-consultation writes by default unless the route is explicitly allowlisted.

Implementation:

- Classify every authenticated route as `consultation`, `registration`, `day-lifecycle`, or `system-maintenance`.
- Add route middleware or per-workflow guard calls to block registration routes during `closing` or `closed` states.
- Document the allowlist for writes that may remain available while closed; any expansion of the allowlist requires accounting/product approval.
- Cover CRM/KYC, accounts, holds, agencies, staff, products, mappings, notifications, HR/payroll, Islamic finance, reporting, and document upload according to the route classification.

Acceptance criteria for stakeholders:

- After close, the application is effectively read-only except for authorized accounting-day operations.
- Users receive consistent messaging across modules.

Acceptance criteria for accounting team:

- No module can introduce business data after close that changes the day-end state.
- Configuration changes after close are either blocked or separately audited as maintenance.

Acceptance criteria for developers:

- A route classification test fails when a new write route is added without a day-lock classification.
- Public write routes use middleware where possible and explicit guards where dates must be resolved.
- Tests prove selected representative endpoints across modules reject closed-day registration.

Acceptance criteria for security reviewer:

- The allowlist is minimal, documented, and tested.
- Platform-admin cannot accidentally bypass day lock unless using an explicit emergency permission and reasoned endpoint.
- File/document upload endpoints cannot mutate KYC or financial evidence after close unless allowlisted.

### JC-010: Reporting, dashboards, and API contract updates

Severity: High

Problem: Existing reports filter by `business_date`, but users need to know this date is the accounting day and whether data is final.

Implementation:

- Add accounting-day filters and response fields to statements, movements, reports, dashboards, batch runs, teller sessions, and journal resources.
- Expose day status and finality in reporting responses.
- Update OpenAPI/Scramble docs to describe accounting day behavior.
- Update docs/domain decisions: business date means `journee comptable`, not caller-supplied calendar date.

Acceptance criteria for stakeholders:

- Reports clearly identify whether a day is open, closing, closed, or reopened.
- Daily movement reports correspond to the accounting day selected by accounting.

Acceptance criteria for accounting team:

- Closed-day reports are reproducible after close.
- Reopened days are visibly marked and include reopen reason/audit link.

Acceptance criteria for developers:

- API resources include stable `accounting_day_public_id` fields where relevant.
- Report queries can filter by accounting day public id and business date.
- OpenAPI export includes new endpoints and fields.

Acceptance criteria for security reviewer:

- Report endpoints enforce agency scope for accounting days.
- Audit-only reopen metadata does not leak sensitive internal comments to unauthorized users.

### JC-011: Migration and historical data strategy

Severity: High

Problem: Existing data has `business_date` but no accounting-day identity.

Implementation:

- Create historical `accounting_days` from distinct `journal_entries.business_date` and relevant agency scopes.
- Mark historical days as `closed` with `migration` metadata.
- Backfill `accounting_day_id` on journal entries, teller sessions, batch runs, teller transactions, and other event tables as the schema evolves.
- Generate an exception report for rows that cannot be mapped unambiguously.

Acceptance criteria for stakeholders:

- Existing historical reports continue to work after the change.
- Migration exceptions are visible before production rollout.

Acceptance criteria for accounting team:

- Historical days are clearly marked as migrated, not manually closed by accounting.
- Any ambiguous historical dates are reviewed and resolved before go-live.

Acceptance criteria for developers:

- Migration scripts are repeatable in staging and have rollback notes.
- Backfill does not invent open days for historical records.
- Tests cover migration mapping for null agency, agency-scoped, and unmapped cases.

Acceptance criteria for security reviewer:

- Migration audit metadata records actor/process and timestamp.
- Backfill does not weaken immutability of posted journals.
- Exception reports avoid exposing sensitive customer-level details unless authorized.

### JC-012: Test and verification hardening

Severity: Critical

Problem: Existing tests often pass `business_date` directly and would not detect the missing accounting-day concept.

Implementation:

- Add `OpensAccountingDay` test helper/trait.
- Update financial feature tests to open a day before registration.
- Add closed-day negative tests per module.
- Add architecture tests that prevent new financial posting workflows from using `now()->toDateString()` for accounting dates.
- Add route-classification tests for day-lock coverage.
- Add concurrency tests for open/close races and write-during-close race.

Acceptance criteria for stakeholders:

- Tests prove users can consult but cannot register after close.

Acceptance criteria for accounting team:

- Tests prove close blockers reflect real accounting controls.
- Tests prove no new operations are posted after close starts.

Acceptance criteria for developers:

- `composer test` passes after all test updates; focused runs may use `php artisan test --parallel --recreate-databases --filter ...`.
- Focused tests exist for accounting day lifecycle, journal posting, cash operations, loan operations, insurance, FX, batch close, and route classification.
- Static search or architecture tests prevent reintroducing calendar-date accounting defaults.

Acceptance criteria for security reviewer:

- Tests cover authorization boundaries for open, close, reopen, emergency override, and blocked write attempts.
- Tests cover idempotency replay behavior across close boundaries.
- Tests prove audit events are written for lifecycle transitions and blocked write attempts.

## Route Classification Workstream

Create a generated or maintained route inventory with these labels:

- `consultation`: safe when accounting day is closed.
- `registration`: blocked unless an accounting day is open.
- `day_lifecycle`: open/close/reopen/current day operations.
- `system_maintenance`: blocked by default unless explicitly allowlisted.

Initial high-risk routes to classify first:

- `routes/api/v1/accounting.php`: account products, EMF mappings, operation codes/mappings, reports, ledger accounts, customer accounts, holds, journal entries/lines, sectors, denominations, tills, teller sessions/transactions/reconciliations.
- `routes/api/v1/credit.php`: loan creation, approval, setup charges, disbursement, repayment, recovery, reschedule, transfer, arrears.
- `routes/api/v1/insurance.php`: premium collection, claim settlement, remittance, cancellation/refund.
- `routes/api/v1/currency_exchange.php`: FX transaction, reversal, stock movement, reconciliation.
- `routes/api/v1/islamic_finance.php`: Islamic financing events that create journals or contractual state.
- `routes/api/v1/auth.php`: batch procedures/runs and admin reference data.

## Full Implementation Sequence

This is not a partial-release plan. These phases describe execution order only. The feature is not complete, shippable, or acceptable until every phase is implemented and the `Definition Of Done` is satisfied.

### Phase 1: Authoritative accounting-day foundation

- `accounting_days` table and lifecycle API.
- Guard service.
- Journal entries linked to accounting day.
- Journal create/post/reverse blocked unless day open.
- Teller session open and cash deposit/withdrawal blocked unless day open.
- Close prevents new journal/cash writes.

Required verification:

- A closed day blocks manual journal posting and cash deposit.
- Statements show entries linked to the open accounting day.
- Tests cover open, close, and write-after-close denial.

### Phase 2: End-of-day close integrity

- Batch close integration.
- Close controls for journals, teller sessions, cash reconciliation.
- `closing` state blocks registration.
- Race protection around close.

Required verification:

- Close cannot succeed with open teller sessions or unposted journals.
- No write can sneak in between close verification and final close.

### Phase 3: Cross-module financial coverage

- Loans, insurance, FX, Islamic finance, HR payroll posting paths use guard and accounting-day linkage.
- Remove `now()->toDateString()` accounting defaults from posting flows.
- Idempotency behavior validated around closed days.

Required verification:

- Representative financial writes in every module fail after close and use current open day when open.

### Phase 4: Global registration lock and documentation

- Route classification across all APIs.
- Non-financial registration lock according to approved allowlist.
- OpenAPI and domain docs updated.
- Migration/backfill completed.

Required verification:

- New write routes cannot be added without accounting-day classification.
- Stakeholder/accounting documentation matches API behavior.

## Decisions To Ratify Or Override

The backlog is implementable with the defaults in `Implementation Decisions`. Accounting/product may override these before implementation starts, but absence of override means the defaults stand.

1. Agency-scoped accounting day with institution-level aggregate views.
2. Block all authenticated registration writes by default after close/start-close.
3. Use `closing` as a fail-closed state while controls run.
4. Reopen requires emergency maker-checker approval, reason, and audit.
5. Corrections post to the current open accounting day unless the original day is explicitly reopened.
6. Holidays are agency-scoped with institution default fallback.
7. Next accounting day is opened manually after successful close.

## Definition Of Done

- `journee comptable` is represented by first-class persisted accounting-day records.
- Every registration write is either guarded by an open accounting day or explicitly allowlisted with documented approval.
- Financial postings derive accounting `business_date` from the open accounting day, not wall-clock `now()` or arbitrary request input.
- Closing a day blocks new registrations and produces auditable close evidence.
- Reports and statements identify accounting day status and finality.
- Historical data is backfilled or exception-reported.
- Tests cover lifecycle, closed-day lockout, cross-module financial postings, route classification, authorization, audit, idempotency, and race conditions.
- Security review can verify least privilege, fail-closed behavior, auditability, and database-level protection for critical posting invariants.
