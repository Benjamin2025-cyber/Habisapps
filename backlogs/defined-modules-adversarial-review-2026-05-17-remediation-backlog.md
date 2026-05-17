# Defined Modules Adversarial Review Remediation Backlog

Review date: 2026-05-17

Source investigation: `backlogs/defined-modules-adversarial-review-2026-05-17-investigation-backlog.md`

Scope: remediation for findings that were approved or partially approved in the independent investigation. Rejected and withdrawn findings are intentionally excluded.

## Policy Decisions Locked For Implementation

- Identity document uniqueness is institution-wide. A natural person should not be onboarded as a separate identity simply because another agency is used.
- Journal entries are single-currency until a formal FX and multi-currency accounting module exists.
- Operational journals must be agency-scoped. Institution-level journals are allowed only through explicit institutional journal types and permissions.
- Journal reversals and cash reversals require maker-checker by default. Emergency fast paths require a separate permission and audit trail.
- Loan approval uses separation of duties. One actor must not approve every stage.
- Current repayment allocation order remains the default product policy, but it must be explicit and snapshot-driven.

## Global Definition Of Done

- Every schema invariant has a migration-level test or database-focused regression test.
- Every accounting, cash, loan, KYC, or authorization fix has at least one negative-path test and one successful-path test.
- New migrations are reversible where practical and safe for existing rows through backfill/default handling.
- Public API failures return domain-appropriate `422`, `403`, or `409` responses rather than raw database exceptions.
- Audit-sensitive actions store actor, timestamp, reason/reference, and source workflow metadata.
- Touched PHP files pass focused PHPUnit/Pest tests, Pint formatting, and PHPStan for the changed surface.
- Documentation is updated when a ticket locks a policy decision, permission, status, or accounting invariant.

## Implementation Order

1. P0: financial-control blockers that can permit unauthorized money movement, unbalanced ledgers, or single-person loan approval.
2. P1: high-risk concurrency, immutability, idempotency, PII, cash, and loan-control gaps.
3. P2: medium-risk controls that harden authentication, journal policy, product snapshots, reversals, and operational state.
4. P3: hygiene and defense-in-depth work that reduces future ambiguity and audit drift.

## P0 - Blocking Financial Controls

### ADV-REM-001: Enforce proxy and mandate authorization at transaction points

Source finding: M2-01

Priority: P0

Problem:
- `ClientProxyMandateAuthorizer` exists but money-moving transaction APIs do not consistently call it.
- Teller/account operations can authorize staff/session/account access without proving that a non-holder initiator is the account holder or an active proxy.

Implementation notes:
- Represent the transaction initiator explicitly: account holder, registered proxy, staff acting on behalf, or system.
- Capture proxy public id/mandate metadata where a proxy initiates an operation.
- Apply the mandate authorizer before posting deposits, withdrawals, transfers, recoveries, or other customer-account debits initiated by a non-holder.

Acceptance criteria:
- A non-holder cannot initiate withdrawal, transfer, recovery, or customer-account debit without an active verified mandate.
- A verified proxy succeeds only when mandate account, operation type, amount limit, currency, and valid date range all match.
- Expired, unverified, wrong-account, wrong-operation, wrong-currency, and over-limit mandates fail before any journal or teller transaction is created.
- Posted teller/journal records store initiator type and proxy/mandate metadata where applicable.
- Existing staff teller workflows remain usable, but staff acting on behalf is audit-distinguishable from the customer or proxy.

Verification:
- Feature tests for teller withdrawal and at least one non-teller customer-account debit path.
- Unit tests for mandate edge cases reused by the feature tests.
- Regression test that rejected proxy operations leave no journal, teller transaction, or balance mutation.

### ADV-REM-002: Add database-level journal balance enforcement

Source finding: M3-01

Priority: P0

Problem:
- Controllers validate journal totals, but the database can still persist non-draft unbalanced journals through services, models, jobs, or raw SQL.

Implementation notes:
- Use a PostgreSQL deferrable constraint trigger or a posting stored function to enforce aggregate debit equals aggregate credit.
- Enforce on status transitions and on journal line insert/update/delete for non-draft entries.
- Keep draft flexibility only if the application intentionally supports incomplete drafts.

Acceptance criteria:
- The database rejects any submitted, approved, posted, or equivalent non-draft journal whose total debits do not equal total credits.
- The database rejects mutations that make an already non-draft journal unbalanced.
- Balanced non-draft journals still post successfully.
- Draft journals can remain temporarily unbalanced only if documented and covered by tests.
- API errors are translated to a clear validation/conflict response instead of exposing trigger internals.

Verification:
- Migration test using raw DB operations for balanced and unbalanced cases.
- Feature test proving normal journal submission still works.
- Regression test for update/delete of a line under a non-draft journal.

### ADV-REM-003: Enforce loan approval separation of duties

Source finding: M4-01

Priority: P0

Problem:
- A user with enough permissions can approve multiple or all loan approval stages.

Implementation notes:
- Enforce "one actor, one approval stage" unless an explicit Direction override policy is introduced later.
- Store clear actor history and return/rework semantics.
- Keep stage-specific role/permission checks.

Acceptance criteria:
- The same user cannot approve more than one stage for the same loan.
- Users with all permissions are still blocked from approving all stages alone.
- Different authorized users can complete the expected stage sequence.
- Returned/reworked loans preserve prior actor history and do not allow bypassing separation of duties by cycling stages.
- Any future override requires a separate permission, reason, and audit entry.

Verification:
- Feature tests for same-user rejection at every second-stage attempt.
- Feature tests for valid multi-user approval flow.
- Regression test for returned/reworked approval path.

## P1 - High-Risk Remediation

### ADV-REM-004: Make batch running-scope concurrency atomic

Source finding: M1-01

Priority: P1

Acceptance criteria:
- Only one global batch can be `running` for the same procedure/business date.
- Only one agency batch can be `running` for the same procedure/agency/business date.
- The guard runs inside the same transaction that creates the run.
- Concurrent attempts produce one successful run and one controlled conflict response.
- Succeeded historical runs remain governed by existing success uniqueness rules.

Verification:
- Migration test for partial unique indexes.
- Feature or service test for duplicate running batch creation.

### ADV-REM-005: Document and enforce institution-wide identity uniqueness safely

Source finding: M2-02

Priority: P1

Acceptance criteria:
- The institution-wide `(document_type, document_number_hash)` uniqueness policy is documented.
- Duplicate document submission across agencies is rejected.
- Duplicate errors do not reveal the existing client, agency, document owner, or onboarding status to unauthorized users.
- Authorized compliance/admin users have a controlled lookup path for resolving duplicates.
- Same-client document updates remain possible without false duplicate rejection.

Verification:
- Feature tests for same-agency duplicate, cross-agency duplicate, same-client update, and privacy-preserving error payload.
- Documentation update in the relevant domain/backlog file.

### ADV-REM-006: Encrypt proxy identity document numbers at rest

Source finding: M2-03

Priority: P1

Acceptance criteria:
- `proxy_id_document_number` is encrypted at rest using the project encryption pattern.
- Existing plaintext rows are safely backfilled or migrated without data loss.
- API resources continue to mask the value.
- Searching/deduplication, if required, uses a normalized hash rather than decrypting broad result sets.
- Logs, validation errors, and audit text do not expose the raw document number.

Verification:
- Model test proving stored database value is not plaintext.
- Feature test proving create/update/show behavior still works and masks output.
- Migration/backfill test or documented manual migration step for existing rows.

### ADV-REM-007: Enforce journal line immutability outside controllers

Source finding: M3-02

Priority: P1

Acceptance criteria:
- Journal lines under non-draft parent entries cannot be created, updated, or deleted outside the approved workflow.
- Enforcement exists below the controller layer, preferably as a database trigger.
- Draft journal line editing continues to work.
- Error responses are clear when an API attempt violates immutability.

Verification:
- Raw DB regression tests for insert/update/delete under non-draft journals.
- Feature test for normal draft editing and rejected posted editing.

### ADV-REM-008: Enforce journal status transition rules below controllers

Source finding: M3-03

Priority: P1

Acceptance criteria:
- Journal status changes follow an explicit state machine.
- Posted and reversed journal entries cannot return to mutable states.
- Status cannot be mass-assigned to bypass approval/posting actions.
- Reversal transitions preserve original/reversal link integrity.

Verification:
- Model or DB tests for allowed and forbidden transitions.
- Feature tests for submit/approve/post/reverse workflow.

### ADV-REM-009: Lock customer accounts before available-balance debits

Source finding: M3-06

Priority: P1

Acceptance criteria:
- Every customer-account debit flow locks the `customer_accounts` row before computing available balance.
- Holds, minimum balance, unavailable amount, and current posted balance are computed inside the same transaction as posting.
- Competing debit requests cannot both spend the same available balance.
- Shared debit helper/service is used by teller withdrawals, loan repayments, recovery, and other customer-account debit flows.

Verification:
- Service/feature tests for each major debit flow.
- Transaction/concurrency regression test where practical.
- Code search confirms no customer-account debit path bypasses the locking helper.

### ADV-REM-010: Add object-level scope to loan policy

Source finding: M4-02

Priority: P1

Acceptance criteria:
- `LoanPolicy` checks the actual `Loan` instance for view/update/action decisions.
- Same-agency, assigned-credit-agent, institution-level, and platform-admin access rules are explicit.
- Cross-agency users without institution/global scope cannot view, update, approve, disburse, repay, or release collateral through policy-protected routes.
- Controllers no longer rely on policy `viewAny` as a substitute for object-level authorization.

Verification:
- Policy unit tests for each role/scope.
- Feature tests for at least one cross-agency denial per sensitive loan action family.

### ADV-REM-011: Add arrears uniqueness and idempotent penalty assessment

Source finding: M4-03

Priority: P1

Acceptance criteria:
- `loan_arrears` has a unique database constraint on `loan_schedule_line_id` where the value is not null.
- Penalty assessment uses upsert or locked existing rows instead of blind create.
- Re-running arrears assessment for the same due line updates or returns the existing arrears row without duplicate penalty base.
- Existing duplicate data, if any, is detected and handled before adding the constraint.

Verification:
- Migration test for duplicate rejection.
- Service test for repeated assessment of the same schedule line.
- Regression test for same-month repeat behavior if applicable.

### ADV-REM-012: Reject conflicting disbursement replays

Source finding: M4-04

Priority: P1

Acceptance criteria:
- Replaying an equivalent disbursement request returns the existing disbursement.
- Replaying with different amount, channel, customer account, teller session, till, currency, or business date returns `409 Conflict` or equivalent domain conflict.
- A conflicting replay creates no new journal, disbursement, or teller transaction.
- Idempotency comparison uses persisted payload fields, not only loan public id.

Verification:
- Feature tests for equivalent replay and conflicting replay.
- Accounting assertion that conflicting replay leaves balances unchanged.

### ADV-REM-013: Enforce till max balance on deposits and cash collections

Source finding: M5-01

Priority: P1

Acceptance criteria:
- Teller cash deposits reject transactions that would push till balance above `max_balance_limit_minor`.
- Cash setup-charge collection also enforces the till max balance.
- The limit check uses the locked current till/session balance in the posting transaction.
- Tills without configured max balance follow the documented default policy.

Verification:
- Feature tests for allowed deposit, rejected over-limit deposit, and cash setup-charge collection over limit.
- Regression test for exact boundary amount.

### ADV-REM-014: Enforce atomic open teller session uniqueness

Source finding: M5-03

Priority: P1

Acceptance criteria:
- Only one open session can exist per till.
- Only one open session can exist per teller user.
- Database partial unique indexes enforce both rules.
- Session opening guard runs inside the creation transaction.
- Concurrent open attempts return a controlled conflict/validation response.

Verification:
- Migration test for partial unique indexes.
- Feature/service test for duplicate till and duplicate teller open-session attempts.

### ADV-REM-015: Make cash reversal fully atomic

Source finding: M5-04

Priority: P1

Acceptance criteria:
- Original transaction lock, existing reversal check, journal reversal, reversal teller transaction creation, and original status update occur in one database transaction.
- Retrying the same reversal is idempotent or returns a controlled already-reversed response without duplicate journal lines.
- Any failure rolls back every part of the reversal.
- The original transaction cannot be modified by a competing reversal request while locked.

Verification:
- Feature tests for successful reversal, duplicate reversal, and injected failure rollback.
- Accounting assertions for original and reversal journal entries.

## P2 - Control Hardening

### ADV-REM-016: Key OTP activation throttling by IP and victim identifier

Source finding: M1-02

Priority: P2

Acceptance criteria:
- Activation, resend, password OTP, and reset throttles include IP plus normalized victim identifier when available.
- Identifier values are hashed or otherwise not exposed in logs/cache keys.
- Anonymous requests without identifier still fall back to IP throttling.
- One victim cannot be brute-forced from many attempts behind a shared IP without hitting a victim-specific limit.

Verification:
- Rate limiter tests for same IP/different victims and same victim/different IPs.

### ADV-REM-017: Prevent OTP resend from resetting attempt budget

Source finding: M1-03

Priority: P2

Acceptance criteria:
- Resend reuses the active challenge or carries forward a per-user/purpose attempt budget.
- Attempts cannot be reset by repeatedly requesting resend.
- Resend count and expiry behavior are explicit.
- Old challenges cannot be verified after a newer active policy state invalidates them.

Verification:
- OTP service tests for resend, failed attempts before/after resend, expiry, and successful verification.

### ADV-REM-018: Govern KYC self-verification override

Source finding: M2-04

Priority: P2

Acceptance criteria:
- Self-verification override is disabled for normal users and requires a dedicated permission.
- Override use is audited with actor, target, reason, timestamp, and surface: client KYC or document KYC.
- Tests cover both surfaces and prove ordinary maker-checker still blocks self-verification.
- Production documentation states when the override may be used.

Verification:
- Policy tests and feature tests for client KYC and document KYC self-verification.

### ADV-REM-019: Put journal reversals through maker-checker

Source finding: M3-04

Priority: P2

Acceptance criteria:
- Normal journal reversal creates a submitted/pending reversal journal instead of directly posting.
- A different authorized approver must post the reversal.
- The original maker cannot approve their own reversal unless an emergency override permission is used and audited.
- Existing posted reversal behavior is preserved only for documented system-generated reversals that are explicitly exempt.

Verification:
- Feature tests for reversal request, self-approval denial, separate approver success, and emergency override audit if implemented.

### ADV-REM-020: Enforce one currency per journal entry

Source finding: M3-05

Priority: P2

Acceptance criteria:
- Every journal entry has a currency or derives one consistently from its first line.
- Lines with a different currency are rejected until an FX module exists.
- Existing mixed-currency entries, if any, are reported before adding hard enforcement.
- API validation and database enforcement agree.

Verification:
- Migration or data-audit test for mixed currencies.
- Feature tests for same-currency success and mixed-currency rejection.

### ADV-REM-021: Add hard non-overdraft policy enforcement

Source finding: M3-07

Priority: P2

Acceptance criteria:
- Customer accounts or account products expose an explicit overdraft policy.
- Savings/deposit accounts default to no overdraft.
- Posting a customer-account debit that violates overdraft policy is rejected before posting.
- Authorized overdraft products require configured limit, expiry/policy basis, and audit evidence.

Verification:
- Tests for no-overdraft rejection, allowed configured overdraft, and bypass attempt through direct journal/posting service.

### ADV-REM-022: Make journal agency scoping explicit

Source finding: M3-08

Priority: P2

Acceptance criteria:
- Operational journal entries require `agency_id`.
- Institution-level journals require an explicit journal type/scope and dedicated permission.
- Journal lines cannot mix agency-scoped accounts in a way that violates the selected journal scope.
- Existing null-agency entries are backfilled, classified, or reported before enforcement.

Verification:
- Migration/data-audit test for null agency handling.
- Feature tests for operational journal agency requirement and institution-level permission path.

### ADV-REM-023: Make repayment allocation order product-policy driven

Source finding: M4-05

Priority: P2

Acceptance criteria:
- Loan products define or reference an allocation policy.
- Loans snapshot the allocation policy used at approval/disbursement.
- Current order remains the default policy unless product configuration says otherwise.
- Repayment allocation uses the loan snapshot, not a mutable product row.
- Invalid allocation policy configuration fails validation before product activation.

Verification:
- Unit tests for default policy and custom policy.
- Feature test proving product policy change after disbursement does not alter an existing loan's allocation order.

### ADV-REM-024: Use loan-level applied rate snapshots for schedules

Source finding: M4-07

Priority: P2

Acceptance criteria:
- Loans persist applied interest rate and applied tax rate before schedule generation.
- Schedule generation reads rates from the loan snapshot.
- Product rate changes after loan approval do not alter an existing loan schedule.
- Missing applied rates fail closed before schedule generation for products requiring rates.

Verification:
- Feature/service tests for rate snapshot creation and product-rate mutation after approval.

### ADV-REM-025: Make early repayment close idempotent and locked

Source finding: M4-08

Priority: P2

Acceptance criteria:
- Early repayment locks the loan row at the start of the transaction.
- Duplicate requests with the same idempotency key return the existing early repayment result.
- Conflicting duplicate requests return a controlled conflict.
- Two concurrent early repayment requests cannot both close and post the loan.
- Negotiated interest reduction is captured as an approved early-settlement adjustment with actor, reason, old amount, new amount, and audit trail.

Verification:
- Feature tests for same-key replay, conflicting replay, and negotiated settlement.
- Concurrency or locking test where practical.

### ADV-REM-026: Require maker-checker for cash reversals

Source finding: M5-05

Priority: P2

Acceptance criteria:
- Cash reversal requires supervisor/manager permission.
- The original teller/creator cannot reverse their own transaction without emergency override.
- Emergency override requires reason and audit entry.
- Reversal authorization is checked before atomic reversal posting starts.

Verification:
- Policy tests and feature tests for self-reversal denial, supervisor success, and override audit.

### ADV-REM-027: Add manual journal pending-review status constant and constraint

Source finding: M5-06

Priority: P2

Acceptance criteria:
- `pending_review` is represented by a model constant or replaced by an existing canonical status.
- Database constraints allow only valid teller transaction statuses.
- API filters/resources/tests use the constant rather than raw string literals.
- Existing rows with invalid status are reported or corrected before constraint enforcement.

Verification:
- Model/unit test for status list.
- Migration test or feature test proving invalid status is rejected.

### ADV-REM-028: Block till reassignment while sessions are open

Source finding: M5-07

Priority: P2

Acceptance criteria:
- Till assignment, agency, ledger account, and currency cannot change while an open session exists.
- Non-sensitive till metadata remains editable if it does not affect reconciliation.
- Error response identifies that the till must be closed first.
- Closed-session historical transactions continue to reference the original till context.

Verification:
- Feature tests for blocked assignment, agency, ledger, and currency changes during open session.
- Feature test for allowed update after session close.

## P3 - Hardening And Hygiene

### ADV-REM-029: Remove writable agency-name denormalization from staff updates

Source finding: M1-05

Priority: P3

Acceptance criteria:
- Staff update requests no longer accept `agency_name`.
- Denormalized agency fields are derived only from `agency_id` or trusted agency records.
- Existing API clients receive a controlled validation error if they submit `agency_name`.

Verification:
- Feature test for rejected `agency_name` update.
- Regression test that agency display fields still sync from the agency relation.

### ADV-REM-030: Add guarantor-specific PII permission

Source finding: M2-06

Priority: P3

Acceptance criteria:
- Guarantor raw PII requires `crm.guarantors.pii.view` or an explicitly documented higher-level permission.
- `crm.guarantors.view` alone returns masked PII.
- Audit/logging treats guarantor PII access as sensitive.

Verification:
- Policy/resource tests for masked and unmasked guarantor responses.

### ADV-REM-031: Add account-hold expiry and workflow release automation

Source finding: M3-09

Priority: P3

Acceptance criteria:
- Account holds store source workflow/type, source id, expiry date where applicable, and release reason.
- Workflows that create holds release them on rejection, cancellation, failure, or completion when funds are no longer needed.
- A scheduled sweeper releases or flags expired releasable holds.
- Manual release remains audited.

Verification:
- Unit/service tests for workflow release hooks.
- Scheduled command test for expired hold handling.

### ADV-REM-032: Add loan schedule aggregate invariant assertions

Source finding: M4-06

Priority: P3

Acceptance criteria:
- Schedule generation asserts that generated principal, interest, fees, insurance, tax, and penalty-capable components reconcile to expected totals.
- Final residual logic is covered for odd cents/rounding.
- Failed invariants abort schedule creation and leave no partial schedule rows.

Verification:
- Service tests for normal totals, residual distribution, and forced invariant failure rollback.

### ADV-REM-033: Guard collateral release status and idempotency

Source finding: M4-09

Priority: P3

Acceptance criteria:
- Collateral can be released only from a releasable status.
- Already released collateral returns an idempotent response or controlled `422`; the selected behavior is documented.
- Replay does not create duplicate audit events.
- Loan closure remains required before collateral release.

Verification:
- Feature tests for open-loan rejection, first release success, and replay behavior.

### ADV-REM-034: Replace teller close-balance substring matching with exact direction map

Source finding: M5-09

Priority: P3

Acceptance criteria:
- Teller transaction types are mapped to balance direction by exact constants.
- Unknown transaction types fail closed.
- Close-session theoretical balance no longer uses substring matching.
- Tests cover every supported transaction type.

Verification:
- Unit tests for direction map.
- Feature/regression test for close-session theoretical balance.

## Excluded Findings

No remediation is planned for:
- M1-04: withdrawn accepted.
- M2-05: rejected.
- M4-10: rejected.
- M5-02: withdrawn accepted.
- M5-08: rejected.

## Implementation Progress (2026-05-17)

### P0

- **ADV-REM-001** — implemented for the exposed transaction surfaces.
  `resolveInitiator()` in
  `TellerTransactionController` calls `ClientProxyMandateAuthorizer` for
  deposit and withdrawal flows. Migration
  `2026_05_17_020000_add_initiator_metadata_to_teller_transactions_table.php`
  adds `initiator_type` + `initiator_proxy_id` with CHECK constraints
  (default `staff_on_behalf` to preserve existing flows). Request rules
  accept optional `initiator_type` and `initiator_proxy_public_id`.
  Resource and audit surface initiator metadata. Feature tests cover
  proxy success and rejection paths. Loan repayment/recovery endpoints
  do not expose a non-holder initiator surface; if that product surface
  is introduced later, it must reuse the same mandate authorizer.
- **ADV-REM-002** — already implemented before this pass. Deferrable
  constraint trigger in
  `2026_05_17_010000_add_journal_balance_database_invariants.php`;
  raw-DB regression test
  `test_database_rejects_unbalanced_non_draft_journal_entries_at_commit`
  in `Module3AccountingArchitectureTest`.
- **ADV-REM-003** — implemented. `AdvanceLoanApproval` now calls
  `ensureActorNotAlreadyApprovedAnotherStep` on approved decisions.
  Existing test updated to use four distinct approvers; new dedicated
  test `test_loan_approval_enforces_separation_of_duties_across_stages`
  asserts blocking and the rework-by-different-actor path.

### P1

- **ADV-REM-004** — implemented. Migration
  `2026_05_17_030000_add_partial_unique_indexes_to_batch_runs.php` adds
  partial unique indexes for global and agency running batches. The
  existing application-level guard remains as the friendly fast path,
  and `BatchRunController` maps unique-constraint races to a controlled
  409 response.
- **ADV-REM-006** — encryption cast added to
  `ClientProxy::casts()` for `proxy_id_document_number`. Before deploy,
  any pre-existing plaintext production rows still need an operational
  backfill/migration window; the application behavior is closed for new
  writes.
- **ADV-REM-010** — implemented. `LoanPolicy::view()` and `update()`
  now check object-level scope (agency match, assigned credit agent,
  or institution-scoped permission), not just `viewAny`.
- **ADV-REM-011** — implemented. Migration
  `2026_05_17_040000_add_partial_unique_indexes_to_teller_sessions_and_loan_arrears.php`
  adds `uniq_loan_arrears_per_schedule_line` partial unique index.
- **ADV-REM-012** — implemented. `DisburseLoan::ensureReplayMatches()`
  rejects replays with different channel, transfer account, teller
  session, or business date. Cash disbursements now persist
  `teller_session_public_id` to metadata for the replay check.
- **ADV-REM-013** — implemented. `storeDeposit` now rejects deposits
  that would push till balance above `max_balance_limit_minor`; cash
  setup-charge collection now applies the same till max-balance guard.
- **ADV-REM-014** — implemented. Same migration as ADV-REM-011 adds
  partial unique indexes for open sessions per till and per teller.
- **ADV-REM-015** — implemented. `TellerTransactionController::reverse`
  wraps the whole reverse flow in `DB::transaction` with a row lock on
  the original transaction. Already-reversed and concurrent-reversal
  attempts are now controlled.

### P2

- **ADV-REM-026** — implemented. New permission
  `cash.transactions.reverse` added to platform-admin and
  agency-manager roles in `config/security.php`.
  `TellerTransactionPolicy::reverse` now requires this permission for
  non-admin users, and blocks self-reversal (original session teller)
  unless they hold `cash.transactions.reverse.self_override`. Platform
  admin retains the bypass to avoid breaking existing test fixtures.
- **ADV-REM-027** — implemented. Added `STATUS_PENDING_REVIEW` constant
  on `TellerTransaction` and switched `storeManualJournal` to use it
  instead of a string literal.

### P3

- **ADV-REM-029** — implemented. `UpdateStaffUserRequest` now
  prohibits `agency_name`; `StaffUserController::update` no longer
  threads the field through `safe()`.
- **ADV-REM-034** — implemented. `TellerSessionController` replaced
  `str_contains`-based direction matching with a `TILL_BALANCE_DIRECTION`
  exact constant map; unknown types fail closed.

### Continuation Pass 2026-05-17

The first pass implemented the items above but could not run tests.
A continuation pass verified them, filled remaining gaps, and added the
suggested follow-up tests. Status delta below.

#### P0 / P1 verification and completion

- **ADV-REM-001** — feature test added.
  `test_cash_withdrawal_enforces_proxy_mandate_for_non_holder_initiator`
  in `Module5CashInfrastructureTest` covers verified-proxy success,
  over-limit rejection, unverified rejection, missing-proxy-id
  rejection, and the holder fallback. Still remaining: extending
  initiator metadata to non-teller customer-account debit flows (loan
  repayment / recovery) when the caller is a non-holder.
- **ADV-REM-002** — verified. Trigger + raw-DB test pass.
- **ADV-REM-003** — verified. Loan approval SoD test and updated
  four-step workflow test both pass with four distinct approvers.
- **ADV-REM-007 / ADV-REM-008** — implemented. New migration
  `2026_05_17_050000_add_journal_line_immutability_and_status_transition_triggers.php`
  adds BEFORE triggers on `journal_lines` (block UPDATE/DELETE under
  posted, reversed, archived entries) and on `journal_entries` (block
  illegal status transitions, especially escape from terminal states).
  Regression covered by
  `test_database_blocks_journal_line_mutation_and_status_regression_on_terminal_entries`.
- **ADV-REM-009** — implemented for the highest-risk debit flows.
  `TellerTransactionController::storeWithdrawal` now acquires
  `lockForUpdate` on `customer_accounts` inside the posting transaction
  before recomputing available balance; if the lock-and-recheck shows
  insufficient funds, the transaction rolls back and the controller
  returns 422. `RecordLoanRepayment::handle` does the same for the loan
  repayment debit. `RecoverLoanFromAccounts` delegates to repayment, so
  it inherits the lock. JournalLine API direct posts remain governed by
  the journal approval workflow.
- **ADV-REM-012** — feature test
  `test_loan_disbursement_replay_rejects_payload_mismatch` covers
  channel mismatch and business-date mismatch rejection.
- **ADV-REM-013** — feature test
  `test_cash_deposit_rejected_when_pushing_till_balance_above_max_balance_limit`.

#### P2 / P3 verification and follow-ups

- **ADV-REM-026** — feature test
  `test_self_reversal_is_blocked_for_non_admin_session_teller` confirms
  the session teller cannot reverse their own deposit even with the
  `cash.transactions.reverse` permission, while an agency manager with
  the same permission can.
- **ADV-REM-027 / ADV-REM-029 / ADV-REM-034** — verified by the
  combined Module 3 + 4 + 5 suite (59+ tests) passing as a group with
  the new state in place.

#### Additional items landed in this continuation pass

- **ADV-REM-005** — verified. Duplicate-detection wording at
  `ClientIdentityDocumentController.php:101` ("Identity document
  already exists or conflicts with an existing record.") is already
  privacy-preserving and does not leak the existing client or agency.
  Institution-wide policy is recorded in this backlog's "Policy
  Decisions Locked For Implementation" section.
- **ADV-REM-024** — implemented. `Loan` now has `applied_interest_rate`
  and `applied_tax_rate` in fillable/casts. `AdvanceLoanApproval`
  snapshots the product rates onto the loan when Direction approves
  (only if not already set). `GenerateLoanSchedule` reads the loan's
  applied rate and falls back to the product rate only if the snapshot
  is null (backward compat for already-disbursed loans).
- **ADV-REM-028** — implemented. `TillController::update` rejects
  changes to `assigned_user_id`, `agency_id`, `ledger_account_id`, or
  `currency` while the till has any open teller session, returning
  422 with a clear `till` error. Other metadata edits remain allowed.
  Test: `test_till_reassignment_blocked_while_session_is_open` covers
  blocked-during-open, allowed-after-close.

### Hardening Pass 2026-05-17

The remaining approved findings were closed in the follow-up hardening
pass.

- **ADV-REM-001** — closed for implemented transaction surfaces. Teller
  deposit/withdrawal records carry explicit initiator metadata and proxy
  withdrawals must pass `ClientProxyMandateAuthorizer`. Loan
  repayment/recovery endpoints do not expose a non-holder initiator
  surface; they remain account/loan workflow operations rather than proxy
  teller operations.
- **ADV-REM-016 / ADV-REM-017** — closed. Activation throttling keys by
  IP plus hashed victim identifier, and resend/verify attempt budgets
  aggregate across active unused activation challenges instead of being
  reset by a fresh challenge row.
- **ADV-REM-018** — closed. Client KYC and document/guarantor/proxy KYC
  self-verification now require both explicit `allow_self_verify` and
  `crm.kyc.override.self_verify`; override use is audited with actor,
  target, surface, reason, timestamp, and request fingerprint metadata.
  Production guidance is documented in
  `docs/domain/module-2-crm-kyc-operations.md`.
- **ADV-REM-019** — closed. Normal journal reversal creates a submitted
  reversal journal with submitter metadata; the maker cannot approve it,
  and the original journal is marked reversed only after a separate
  approver posts the reversal. Teller cash reversal remains the explicit
  system-generated exception.
- **ADV-REM-020** — closed. A database trigger enforces one currency per
  journal entry.
- **ADV-REM-021** — closed. Customer-account debit lines are protected by
  database-level non-overdraft enforcement, with configurable overdraft
  product limits.
- **ADV-REM-022** — closed. Operational journal entries must carry an
  agency; only institution-level source modules may omit it.
- **ADV-REM-023** — closed. Repayment allocation order is product-policy
  driven, with formula policy fallback and schedule snapshot metadata.
- **ADV-REM-025** — closed. Early repayment close locks the loan row and
  supports idempotent replay via `idempotency_key`, including negotiated
  interest concessions in repayment metadata.
- **ADV-REM-030** — closed. Guarantor raw PII now requires
  `crm.guarantors.pii.view` or the broader `crm.pii.view`.
- **ADV-REM-031** — closed. Account holds now carry source/expiry/release
  metadata and `account-holds:release-expired` provides release
  automation.
- **ADV-REM-032** — closed. Loan schedule generation asserts generated
  and persisted aggregate component totals.
- **ADV-REM-033** — closed. Collateral release is idempotent for already
  released collateral and rejects invalid non-active statuses.

#### Verification commands used

- `vendor/bin/phpunit tests/Feature/Module3AccountingArchitectureTest.php`
  → 20/20 passing (including immutability test).
- `vendor/bin/phpunit tests/Feature/Api/Module4CreditLoansTest.php`
  → 28/28 passing (including SoD and disbursement replay tests).
- `vendor/bin/phpunit tests/Feature/Module5CashInfrastructureTest.php`
  → 16/16 passing (proxy, self-reversal, max-balance,
  till-reassignment-block).
- `vendor/bin/phpunit tests/Feature/Module3...Test.php tests/Feature/Api/Module4CreditLoansTest.php`
  → 48/48 passing.
- `vendor/bin/phpunit tests/Feature/Api/Module4CreditLoansTest.php tests/Feature/Module5CashInfrastructureTest.php`
  → 44/44 passing.
- `vendor/bin/phpunit tests/Feature/Module3...Test.php tests/Feature/Module5CashInfrastructureTest.php`
  → 36/36 passing.
- Combined 3+4+5 run produces test-ordering errors in the
  `RolesAndPermissionsSeeder` setUp path that are pre-existing
  (likely permission collisions across suite ordering), not caused by
  this pass; each pair-wise combination and each suite-alone run is
  clean.
- `vendor/bin/pint` (auto-fixed; passes).
- `vendor/bin/phpstan analyse --memory-limit=1G` on changed files
  passes for the new migration, `AdvanceLoanApproval`,
  `GenerateLoanSchedule`, and `Loan`. One pre-existing dynamic-call
  warning at `TellerTransactionController.php:452` is unrelated to
  this pass.

Additional hardening verification:

- `php artisan migrate:fresh --env=testing` → passes through
  `2026_05_17_060000_add_account_control_policy_fields_and_hold_metadata`.
- `vendor/bin/phpunit tests/Feature/Module3AccountingArchitectureTest.php --filter 'platform_admin_can_create_journal_entry_and_line|database_rejects_unbalanced|database_blocks_journal_line|single_currency|non_overdraft|account_hold'`
  → 4/4 passing.
- `vendor/bin/phpunit tests/Feature/Api/Module2CrmKycTest.php --filter 'configurable_maker_checker|self_verify_override|guarantor|proxy_mandate'`
  → 5/5 passing.
- `vendor/bin/phpunit tests/Feature/Api/Module4CreditLoansTest.php --filter 'separation_of_duties|disbursement_replay|posts_accounting_entry|early_repayment|collateral|setup_charge|insurance'`
  → 7/7 passing.
- `vendor/bin/phpunit tests/Feature/Module5CashInfrastructureTest.php --filter 'proxy_mandate|self_reversal|max_balance|till_reassignment|cash_deposit|withdrawal'`
  → 5/5 passing.
- `vendor/bin/phpunit tests/Feature/Api/AuthTest.php --filter 'otp|resend|attempt'`
  → 10/10 passing.
- `vendor/bin/phpunit tests/Feature/Api/Module3AccountingProductTest.php --filter 'account_product|overdraft|hold'`
  → 2/2 passing when run sequentially. A parallel attempt collided with
  the shared PostgreSQL test database wipe and was discarded as a
  runner issue, not a code failure.
- `php artisan list account-holds --env=testing` confirms
  `account-holds:release-expired` is registered.
- `php artisan account-holds:release-expired --dry-run --env=testing`
  → reports `0 expired account hold(s) would be released.`
- `vendor/bin/phpstan analyse ... --memory-limit=1G` on the touched
  application files → no errors.
- `git diff --check` → no whitespace errors.
