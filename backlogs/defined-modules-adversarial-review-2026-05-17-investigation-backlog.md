# Defined Modules Adversarial Review 2026-05-17 — Independent Investigation Backlog

Source report: `backlogs/defined-modules-adversarial-review-2026-05-17.md`

Purpose: independently verify every finding from the external adversarial review before accepting it into remediation planning.

Verdicts:
- `approved`: claim is materially correct and should become remediation work.
- `partially approved`: risk exists, but the report overstates scope, severity, or exact mechanism.
- `rejected`: claim is contradicted by current code.
- `withdrawn accepted`: the source report already withdrew the claim and this investigation agrees.

## Investigation Status

| ID | Source severity | Verdict | Remediation status | Priority |
| --- | --- | --- | --- | --- |
| M1-01 | high | approved | backlog | P1 |
| M1-02 | medium | approved | backlog | P2 |
| M1-03 | medium | approved | backlog | P2 |
| M1-04 | withdrawn | withdrawn accepted | none | none |
| M1-05 | low | partially approved | backlog | P3 |
| M2-01 | critical | partially approved | backlog | P0 |
| M2-02 | high | approved as policy decision required | backlog | P1 |
| M2-03 | high | approved | backlog | P1 |
| M2-04 | medium | partially approved | backlog | P2 |
| M2-05 | low | rejected | none | none |
| M2-06 | low | partially approved | backlog | P3 |
| M3-01 | critical | approved | backlog | P0 |
| M3-02 | high | approved | backlog | P1 |
| M3-03 | high | approved | backlog | P1 |
| M3-04 | medium | partially approved | backlog | P2 |
| M3-05 | medium | approved | backlog | P2 |
| M3-06 | medium | approved | backlog | P1 |
| M3-07 | medium | approved | backlog | P2 |
| M3-08 | medium | approved | backlog | P2 |
| M3-09 | medium | approved | backlog | P3 |
| M4-01 | critical | approved | backlog | P0 |
| M4-02 | high | partially approved | backlog | P1 |
| M4-03 | high | partially approved | backlog | P1 |
| M4-04 | high | approved | backlog | P1 |
| M4-05 | medium | approved | backlog | P2 |
| M4-06 | medium | approved | backlog | P3 |
| M4-07 | medium | approved | backlog | P2 |
| M4-08 | medium | approved | backlog | P2 |
| M4-09 | medium | approved | backlog | P3 |
| M4-10 | low | rejected | none | none |
| M5-01 | high | approved | backlog | P1 |
| M5-02 | withdrawn | withdrawn accepted | none | none |
| M5-03 | high | approved | backlog | P1 |
| M5-04 | high | partially approved | backlog | P1 |
| M5-05 | medium | approved | backlog | P2 |
| M5-06 | medium | approved | backlog | P2 |
| M5-07 | medium | approved | backlog | P2 |
| M5-08 | medium | rejected | none | none |
| M5-09 | low | approved | backlog | P3 |

## Approved Critical / P0 Work

### M2-01 — Proxy/mandate controls are not enforced by transaction APIs

Verdict: partially approved.

Evidence:
- `app/Support/Crm/ClientProxyMandateAuthorizer.php` exists and correctly validates active, verified, scoped, dated, amount-limited mandates.
- `rg ClientProxyMandateAuthorizer app` finds no production caller outside the authorizer itself.
- `app/Http/Controllers/Api/V1/TellerTransactionController.php` deposit and withdrawal flows authorize teller/session/agency/account, but do not check whether the actor is the account holder or an active proxy for the selected account.

Why partial: the data model is not merely cosmetic because a good authorizer exists and tests exercise it directly. The operational transaction layer does not consume it, so the control is not effective at the point money moves.

Remediation:
- Define how a transaction actor is represented for a client: account holder, staff teller acting on behalf of holder, registered proxy, or back-office system.
- Add proxy/mandate enforcement to any account operation that is initiated by a person other than the account owner.
- At minimum, teller deposit/withdrawal and any account transfer/recovery endpoints should capture initiator type and proxy id where applicable.

Acceptance tests:
- A non-holder without active verified mandate cannot initiate withdrawal/transfer from a customer account.
- A verified proxy with matching operation type, account scope, amount limit, currency, and valid dates is accepted.
- Expired, unverified, wrong-account, wrong-operation, or over-limit mandates are rejected.

### M3-01 — No database-level aggregate journal balance invariant

Verdict: approved.

Evidence:
- `database/migrations/2026_04_28_050139_add_foundation_integrity_constraints.php` only checks per-line non-negative/exactly-one-side-positive constraints.
- `app/Http/Controllers/Api/V1/JournalEntryController.php` checks totals before submit, but this is application-layer only.
- `JournalLine` remains a normal fillable Eloquent model; future internal services/raw SQL can persist unbalanced entries.

Remediation:
- Add database enforcement for posted/submitted journal balance. PostgreSQL cannot express cross-row aggregate CHECK directly; use a deferrable constraint trigger or a journal-entry posting function.
- If using triggers, enforce at status transition to submitted/approved/posted and on journal line insert/update/delete for non-draft entries.

Acceptance tests:
- Raw DB insert/update cannot leave submitted/posted journal entries unbalanced.
- Draft entries may be temporarily unbalanced, if the product intentionally supports drafting.

### M4-01 — Same user can approve all loan stages

Verdict: approved.

Evidence:
- `app/Application/Loans/AdvanceLoanApproval.php` enforces step sequence and finality, but does not compare `acted_by_user_id` against prior approved steps.
- The four step names are distinct responsibility centers: montage/setup, comptabilite, controle, direction.

Remediation:
- Enforce separation of duties across approval stages, either "no actor may approve more than one stage" or a role-matrix rule approved by policy.
- Store explicit exception metadata if Direction can override maker-checker.

Acceptance tests:
- User with all permissions cannot approve all stages alone.
- Different authorized users can approve in order.
- Returned/reworked steps preserve correct maker-checker semantics.

## Approved High / P1 Work

### M1-01 — Batch running-scope guard is race-prone

Verdict: approved.

Evidence:
- `ExecuteRegisteredBatchRun::guardNoRunningBatchInScope()` performs a plain `exists()` query on `batch_runs`.
- It is called before execution without acquiring a scope lock.
- Existing DB unique indexes cover `status = succeeded`, not concurrent `running`.

Remediation:
- Use a transaction and lock the target batch row plus a deterministic scope row.
- Add partial unique indexes for one running global batch and one running agency batch per `(procedure, agency, business_date)`, if supported by the database.

### M2-02 — Identity document uniqueness scope is implicit and cross-agency

Verdict: approved as policy decision required.

Evidence:
- Unique index: `(document_type, document_number_hash)` where hash is not null, no `agency_id`.
- Duplicate lookup in `ClientIdentityDocumentController::hasDuplicateDocumentNumber()` excludes same client but does not scope by agency.

Remediation:
- Decide policy explicitly: institution-wide natural-person uniqueness or per-agency onboarding.
- If institution-wide, keep the constraint but make error wording and access pattern privacy-preserving.
- If agency-scoped, change index and duplicate lookup to include agency.

### M2-03 — Proxy identity document number is plaintext at rest

Verdict: approved.

Evidence:
- `ClientProxy` fillable includes `proxy_id_document_number`.
- `ClientProxy::casts()` does not encrypt that field.
- `ClientProxyController` writes request input directly.
- `ClientProxyResource` masks output, but masking is not encryption at rest.

Remediation:
- Add encrypted accessor/mutator or Laravel encrypted cast for proxy ID document numbers.
- Consider adding a normalized hash for duplicate detection if needed.

### M3-02 — Journal lines can be mutated outside controller status checks

Verdict: approved.

Evidence:
- `JournalLineController::update()` blocks non-draft parent entries.
- `JournalLine` model has no observer/model guard and all accounting fields are fillable.

Remediation:
- Add model-level or database-level immutability guard for lines whose parent journal is not draft.
- Prefer DB trigger for hard accounting controls.

### M3-03 — Posted journal status can be changed outside controller

Verdict: approved.

Evidence:
- `JournalEntry` fillable includes `status`.
- `JournalEntryController` has workflow checks, but `JournalEntry` has no model or DB state-transition guard.

Remediation:
- Add a status transition state machine enforced outside controllers.
- DB-level trigger should prevent posted/reversed entries from returning to mutable states.

### M3-06 — Available-balance check is race-prone

Verdict: approved.

Evidence:
- `AccountingBalanceCalculator::availableForCustomerAccount()` reads balance, minimum balance, unavailable amount, and active holds without locking the customer account row.
- Withdrawal and loan repayment flows read available balance before posting, but do not consistently lock the customer account row.

Remediation:
- For every debit flow, lock the `customer_accounts` row in the same transaction before computing available balance and posting journal lines.
- Add focused concurrency tests or transaction-level integration tests where practical.

### M4-02 — Loan policy does not enforce object-level scope

Verdict: partially approved.

Evidence:
- `LoanPolicy::view()` delegates to `viewAny()` and ignores the `Loan` instance.
- Some controllers add agency checks separately, but the policy itself is not a second wall.

Why partial: controller-level checks may still protect many HTTP paths. The policy is weak if reused by another controller/job/resource gate.

Remediation:
- Add object-level checks: platform admin, institution loan permission, same agency, assigned credit agent, or documented role scope.
- Add tests proving cross-agency users cannot view/update a loan through any route using policy authorization.

### M4-03 — Penalty assessment idempotency depends on loan lock, not arrears uniqueness

Verdict: partially approved.

Evidence:
- `AssessLoanArrearsAndPenalties` locks the loan row before iterating schedule lines.
- It does not lock `loan_arrears` rows directly.
- `loan_arrears` has no unique constraint on `loan_schedule_line_id`.

Why partial: the loan row lock serializes callers that use this service correctly for the same loan. A future code path or raw write can still duplicate arrears rows, and there is no DB backstop.

Remediation:
- Add unique index on `loan_arrears(loan_schedule_line_id)` where non-null.
- Use `updateOrInsert`/upsert or lock existing arrears rows by schedule line.

### M4-04 — Disbursement retry masks payload mismatch

Verdict: approved.

Evidence:
- `DisburseLoan::handle()` returns the first existing `LoanDisbursement` for a loan before comparing requested channel, transfer account, teller session, amount, or business date.
- Idempotency key is effectively fixed to `loan-disbursement:{loan_public_id}`.

Remediation:
- On replay, compare stored disbursement channel, target account/till, amount, and business date.
- Return the existing result only when payload is equivalent; otherwise return a conflict.

### M5-01 — Deposit does not enforce till max balance

Verdict: approved.

Evidence:
- `TellerTransactionController::storeWithdrawal()` checks `max_withdrawal_limit_minor`.
- `storeDeposit()` does not reference `max_balance_limit_minor`.
- `tills.max_balance_limit_minor` exists in migration and request validation.

Remediation:
- Compute posted till balance + incoming deposit and reject when it exceeds `max_balance_limit_minor`.
- Apply the same check to teller-cash setup-charge collection if it increases till cash.

### M5-03 — Open teller session uniqueness is not atomic

Verdict: approved.

Evidence:
- `TellerSessionController::store()` checks `hasOpenSessionForTill()` and `hasOpenSessionForTeller()` before entering the transaction that creates the session.
- No partial unique index enforces one open session per till/teller.

Remediation:
- Add partial unique indexes for `teller_sessions(till_id) WHERE status = 'open'` and `teller_sessions(teller_user_id) WHERE status = 'open'`.
- Move open guard inside transaction and lock the till/teller scope.

### M5-04 — Cash reversal is not fully atomic

Verdict: partially approved.

Evidence:
- `CreateJournalEntryReversal::execute()` is itself transactional.
- `TellerTransactionController::reverse()` then creates reversal `TellerTransaction` and updates the original transaction outside the same transaction as the journal reversal.

Remediation:
- Wrap the whole reverse operation in one transaction, including existing reversal check, journal reversal, reversal transaction insert, and original status update.
- Lock the original transaction row during reversal.

## Approved Medium / P2 Work

### M1-02 — OTP activation throttle is IP-only

Verdict: approved.

Evidence:
- `RateLimiter::for('auth.activation')` uses `->by((string) $request->ip())`.
- Activation, resend, password OTP, and password reset routes all use this limiter.

Remediation:
- Key limiter by IP plus victim identifier when present, e.g. normalized phone number/email hash.
- Consider separate stricter resend limiter.

### M1-03 — OTP resend creates fresh attempt window

Verdict: approved.

Evidence:
- `OtpService::resendActivationChallenge()` calls `issueActivationChallenge()`, creating a new challenge.
- `verifyCode()` chooses latest unused challenge and checks attempts on only that row.

Remediation:
- Reuse current active challenge and increment resend count, or enforce per-user/purpose attempt budget across active challenge windows.

### M2-04 — Identity document self-verification policy diverges from KYC surface

Verdict: partially approved.

Evidence:
- Identity document verification allows self-verification only with `crm.kyc.override.self_verify`.
- Client KYC verification also supports an override path through `canVerifySubmittedKyc()`.

Why partial: both surfaces have an override, so the report’s "sister path enforces maker-checker" statement is incomplete. The real risk is that override governance is broad and reused across both client KYC and document KYC without a sharper policy decision.

Remediation:
- Decide whether the override permission should exist in production.
- If it exists, log and test override use explicitly on both surfaces.

### M3-04 — Journal reversal auto-posts

Verdict: partially approved.

Evidence:
- `CreateJournalEntryReversal` creates reversal entries directly as `posted`.
- It does run inside a DB transaction and is called from a controller policy path, so "bypass" means bypassing maker-checker, not lack of transaction safety.

Remediation:
- Decide whether reversals require maker-checker.
- If yes, create reversal as submitted and require approval before posting.
- If no, document the fast-path policy and permission boundary.

### M3-05 — Journal lines allow mixed currencies per entry

Verdict: approved.

Evidence:
- `StoreJournalLineRequest` and `UpdateJournalLineRequest` validate currency shape only.
- `journal_entries` has no currency column.
- No controller check requires all lines in one entry to share currency.

Remediation:
- Add journal entry currency or explicit multi-currency journal design.
- Until FX module is complete, enforce one currency per entry.

### M3-07 — No hard non-overdraft invariant

Verdict: approved.

Evidence:
- `CustomerAccount` has no `allows_overdraft` flag.
- Available balance is computed but DB does not prevent a posted debit from making savings negative if a code path bypasses calculator checks.

Remediation:
- Add account product/account overdraft policy fields.
- Add posting guard for customer-account debits according to product type.

### M3-08 — Nullable journal agency weakens scoping

Verdict: approved.

Evidence:
- `journal_entries.agency_id` is nullable.
- `JournalLineController::store()` rejects line creation for null-agency entries, but direct service/model creation can still produce null-agency posted journals.

Remediation:
- Decide whether institution-level journals are allowed.
- If not, make `journal_entries.agency_id` non-null.
- If yes, add explicit institutional journal type and cross-agency ledger rules.

### M4-05 — Repayment allocation order is hardcoded

Verdict: approved.

Evidence:
- `RecordLoanRepayment::allocationComponentGroups()` hardcodes scheduled principal/interest/fees/insurance/tax before penalty.
- Product/policy fields do not drive this ordering.

Remediation:
- Add approved product-level allocation policy snapshot.
- Keep current order as default microfinance policy, but make it explicit config or product rule.

### M4-07 — Interest rate snapshot columns are not used by schedule generation

Verdict: approved.

Evidence:
- Migrations add `loans.applied_interest_rate` and `loans.applied_tax_rate`.
- `Loan` fillable/casts do not include those fields.
- `GenerateLoanSchedule` computes interest from live `LoanProduct->interest_rate`.

Remediation:
- Snapshot applied rates onto the loan at application/approval.
- Schedule generation must use the loan snapshot, not the mutable product row.

### M4-08 — Early repayment lacks idempotent double-close protection

Verdict: approved.

Evidence:
- `EarlyRepayLoan` is transactional but does not lock the loan row with `lockForUpdate()`.
- It rejects non-open statuses, but concurrent requests can both load an open loan before either saves closed.
- No idempotency key exists for early closure.

Remediation:
- Lock the loan row at the start.
- Add idempotency/replay behavior for repeated early closure request.

### M5-05 — Cash reversal has no maker-checker restriction

Verdict: approved.

Evidence:
- `TellerTransactionPolicy::reverse()` only checks `cash.transactions.manage` and agency access.
- It does not compare original transaction creator/teller to the reversing actor or require supervisor role.

Remediation:
- Require supervisor/manager permission for reversals.
- Forbid creator/teller self-reversal unless a documented emergency override is logged.

### M5-06 — Manual journal status literal is outside model constants

Verdict: approved.

Evidence:
- `TellerTransactionController::storeManualJournal()` writes status `pending_review`.
- `TellerTransaction` constants include only posted/cancelled/reversed.

Remediation:
- Add `STATUS_PENDING_REVIEW` constant and status check constraint, or use existing submitted journal status as source of truth.

### M5-07 — Till reassignment ignores open sessions

Verdict: approved.

Evidence:
- `TillController::update()` allows `assigned_user_id` changes after validating active till assignment uniqueness.
- It does not check whether the till currently has an open teller session.

Remediation:
- Block assignment, agency, ledger, and currency changes while any open teller session exists for the till.

## Approved Low / P3 Work

### M1-05 — User agency denormalization is partly exposed

Verdict: partially approved.

Evidence:
- `User` fillable includes `agency_code` and `agency_name`.
- `StaffUserController::update()` includes both in the safe list, but unsets `agency_name` before fill and resolves `agency_code` through `SyncStaffUser`.

Why partial: the report’s broad warning is directionally valid, but current staff update path does not blindly trust `agency_name`.

Remediation:
- Remove `agency_name` from update request validation and safe list.
- Prefer deriving all denormalized agency fields from `agency_id`.

### M2-06 — Guarantor PII does not have a dedicated permission

Verdict: partially approved.

Evidence:
- `ClientGuarantorPolicy` uses `crm.guarantors.view` plus agency/institution scope.
- `ClientGuarantorResource` masks name/phone unless user has global `crm.pii.view`.

Why partial: guarantor PII is not fully exposed to any guarantor viewer because masking exists. There is no dedicated guarantor-specific PII permission.

Remediation:
- If guarantor PII is a separate sensitivity bucket, add `crm.guarantors.pii.view`.

### M3-09 — Account holds lack automatic release/sweeper

Verdict: approved.

Evidence:
- `ReleaseAccountHold` and `AccountHoldController::release()` support manual release.
- No scheduled sweeper or source-workflow rollback release was found.

Remediation:
- Add hold source metadata and expiry/release automation.
- Workflows that create holds must release them on rejection/cancellation/failure.

### M4-06 — Schedule sum post-condition is missing

Verdict: approved.

Evidence:
- `GenerateLoanSchedule` uses `splitWithFinalResidual()` and creates lines, but does not assert aggregate sums after line generation.

Remediation:
- Add explicit invariant checks after building component shares and after creating lines.
- Add tests that fail if any component is dropped from totals.

### M4-09 — Collateral release is not idempotency/status guarded

Verdict: approved.

Evidence:
- `CollateralController::release()` only checks loan is closed, then updates collateral to released and records audit.
- It does not reject or idempotently return already released collateral.

Remediation:
- Require releasable collateral status before mutation.
- Make replay idempotent without duplicate audit event, or return a clear 422.

### M5-09 — Close balance math uses substring matching

Verdict: approved.

Evidence:
- `TellerSessionController::theoreticalBalanceMinor()` uses `str_contains()` against transaction type strings.

Remediation:
- Replace with exact known transaction type constants and explicit direction map.

## Rejected / No Remediation

### M1-04 — Password forceFill plaintext concern

Verdict: withdrawn accepted.

Reason:
- Source report withdrew it.
- Current `User` model uses hashed password cast and tests assert password verification behavior.

### M2-05 — `per_page=1` is a defect

Verdict: rejected.

Reason:
- `per_page` is bounded to `[1, 100]` in `ClientController` and `ClientIdentityDocumentController`.
- Allowing `per_page=1` is normal pagination behavior, not a security defect by itself. Enumeration risk belongs to rate limiting, audit, search filters, and PII masking, not a hard pagination floor.

### M4-10 — Loan amount not validated against product bounds at apply time

Verdict: rejected.

Reason:
- `StoreLoanRequest` only validates `min:1`, but `LoanController::resolveStoreReferences()` calls `validateProductAmount()` before creation.
- Existing test coverage expects out-of-range amount to fail with `requested_amount_minor` validation.

Potential minor improvement:
- The check is controller-layer rather than FormRequest-layer, but it is still at apply time before persistence.

### M5-02 — Till migration missing columns

Verdict: withdrawn accepted.

Reason:
- Source report withdrew it.
- Migration adds `requires_denominations`, `is_central_till`, `max_balance_limit_minor`, `max_withdrawal_limit_minor`, and `currency`.

### M5-08 — Manual journal does not verify teller owns session

Verdict: rejected.

Reason:
- Current `TellerTransactionController::storeManualJournal()` calls `canUseSession()` before creating the manual journal, matching deposit/withdrawal behavior.
- The source report appears stale for current code.

## Remediation Order

1. P0: M3-01 journal DB balance invariant, M4-01 loan approval separation of duties, M2-01 proxy/mandate transaction enforcement.
2. P1: M1-01 batch concurrency, M3-02/M3-03 journal immutability, M3-06 available-balance locking, M4-04 disbursement replay conflict, M5-01 till max balance, M5-03 open session uniqueness, M5-04 atomic cash reversal, M2-02/M2-03 identity/proxy PII.
3. P2: OTP resend/throttle, journal currency policy, loan rate snapshot, early repayment idempotency, cash reversal maker-checker, manual journal status constant, till reassignment guard.
4. P3: low-risk hardening and hygiene items: agency denormalization cleanup, guarantor PII permission split, hold sweeper, schedule invariant assertions, collateral release idempotency, close-balance exact type map.

## Verification Commands Used

- `rg` and `nl -ba` inspections against `app/`, `database/migrations/`, `routes/`, and `tests/`.
- No full test suite was run during this investigation; this was a source-evidence verification pass.
