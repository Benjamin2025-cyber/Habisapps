# Defined Modules — Adversarial Implementation Review (2026-05-17)

Scope: every module in `stakeholderResources/definedModules.md`, reviewed against
the actual source under `app/`, `database/migrations/`, `routes/`, and `tests/`.
Posture: assume prior audits in `backlogs/defined-modules-implementation-audit.md`
and `backlogs/defined-modules-adversarial-review.md` over-credit implementation;
verify by reading code, not by trusting documentation.

Confidence labels:
- **Confirmed** — file/line evidence re-verified during this review.
- **Reported** — surfaced by sub-agent code reading, not re-verified by me.
- **Withdrawn** — surfaced by sub-agent but contradicted on verification.

Severity uses banking-API conventions: critical = silent money/PII loss or
regulatory breach; high = exploitable but bounded; medium = control gap with
known compensating control; low = hygiene or maintainability.

---

## Module 1 — Administration & System Security

### M1-01 Batch run scope guard has no row-level lock (high, confirmed)

`app/Application/BatchRuns/ExecuteRegisteredBatchRun.php` does not wrap
`guardNoRunningBatchInScope()` in `DB::transaction()` and does not call
`lockForUpdate()` on `batch_runs` rows. Verified by grep — neither symbol
appears in that file. Two concurrent operators executing the same registered
procedure for the same `(procedure, agency, business_date)` can both pass the
`exists()` check and both proceed. End-of-day automation is therefore not
idempotent under concurrency, which directly contradicts the stakeholder
requirement of priority-ordered, single-pass procedures.

### M1-02 OTP throttle is keyed to IP, not to victim (medium, reported)

Route `routes/api/v1/auth.php:22` uses `throttle:auth.activation` globally. The
test `tests/Feature/Auth/AuthRateLimitTest.php:26-38` exercises the limit at
request-source level. OTP attempt counters at the challenge model do enforce a
per-user max, but a single attacker IP rotating phone numbers is not bounded
per victim. Compensating control: 6-digit codes plus per-challenge `attempts`
cap, but the cap resets on resend (see M1-03).

### M1-03 OTP attempts reset on resend by creating new challenge (medium, reported)

`app/Support/Otp/OtpService.php:51-57` resends by issuing a brand-new
`OtpChallenge` row, and `verifyCode()` selects `latest('created_at')`. So an
attacker that has burned the attempt cap on one challenge can call resend and
get a fresh `attempts = 0` window. There is no rate limit on the resend action
itself in the OTP service layer beyond the HTTP throttle in M1-02.

### M1-04 forceFill password "plaintext" finding (withdrawn)

Sub-agent flagged `AuthController::activate()` writing `password` via
`forceFill(...)->save()` as bypassing hashing. Re-verified: `User` declares
`'password' => 'hashed'` in `casts()` (User.php:91). Laravel applies hashed
casts through `setAttribute()` regardless of `forceFill`. `AuthTest` line ~149
also asserts `Hash::check()` succeeds. No defect.

### M1-05 Mass-assignable `agency_code` / `agency_name` on User (low, reported)

`User` `$fillable` includes denormalized fields like `agency_code` /
`agency_name`. Policies prevent unauthorized creation, but any UPDATE endpoint
that does not strip these fields from the payload risks the denormalized values
drifting from the canonical `agency_id`. Worth a focused audit of every
controller that fills `User` from request input.

---

## Module 2 — CRM & Client Management

### M2-01 Proxy/mandate metadata not enforced at transaction time (critical, reported)

Stakeholder definition: proxies are "authorized to operate on accounts".
Sub-agent grep across `app/Application` and transaction controllers found no
authorization check against the active `ClientProxy` set for an account when
a deposit, withdrawal, or transfer is initiated by someone other than the
account holder. The Module 5 teller flows authorize on agency/till/permission,
not on "is this caller a registered proxy for this customer account". If
proxies are intended as a real control, the data is currently advisory only.
This is the single most impactful Module 2 finding because it makes the
proxy/mandate subsystem cosmetic.

### M2-02 Identity document uniqueness not scoped by agency (high, reported)

`database/migrations/2026_05_11_050000_encrypt_client_identity_document_sensitive_fields.php:41`
creates a partial unique index on `(document_type, document_number_hash)`
without `agency_id`. The duplicate check in
`app/Http/Controllers/Api/V1/ClientIdentityDocumentController.php:363-375` does
not include agency either. Combined: the system enforces global uniqueness on
CNI/Passport hash and exposes its existence cross-agency. Depending on the
desired policy (single citizen, single institution-wide identity vs.
per-branch onboarding), either the constraint or the duplicate-check error
shape needs to be a deliberate design decision, not a side effect.

### M2-03 `ClientProxy.proxy_id_document_number` stored plaintext (high, reported)

`app/Models/ClientProxy.php:24` plus the controller `store()` path fill this
column from the request without applying the `encrypted` cast that
`ClientIdentityDocument` (ClientIdentityDocument.php:100-105) uses. The
response resource masks the field, but at-rest storage is plaintext. Asymmetric
encryption posture between holder identity documents and proxy identity
documents.

### M2-04 Identity document `updateStatus` allows self-verification path (medium, reported)

`ClientIdentityDocumentController.php:292-297` compares uploader to verifier
but the `crm.kyc.override.self_verify` permission bypasses the check. The
sister `ClientController::updateKycStatus()` path (~line 622) enforces
maker-checker. Two slightly different policies for what stakeholders treat as
one KYC review surface. Either the override permission should be removed or
the maker-checker constraint should be lifted explicitly for both surfaces;
currently it depends on which endpoint is hit.

### M2-05 Pagination accepts `per_page=0/1` without floor (low, reported)

`ClientController::index` and `ClientIdentityDocumentController::index` clamp
`per_page` to `[1, 100]` but not to a sensible floor. `per_page=1` is allowed,
which is fine functionally but makes slow enumeration attacks cheap. Not a
direct vulnerability if rate limiting is correct, but worth a floor of, say,
10 for list endpoints that return PII.

### M2-06 Guarantor PII gated only by client-read permission (low, reported)

`ClientGuarantorPolicy.php:19` chains off `view(Client)`. There is no separate
`crm.guarantors.pii.view` permission. Masking is applied in the resource. If
stakeholders consider guarantor PII a higher-sensitivity bucket than client
PII (common for personal guarantees), this needs a dedicated permission.

---

## Module 3 — Accounting & Financial Architecture

### M3-01 No DB-level check that `sum(debit) = sum(credit)` per entry (critical, confirmed)

Verified by grep against `database/migrations/`,
`app/Models/JournalEntry.php`, `app/Models/JournalLine.php`. The only journal
CHECK is per-line `(debit_minor > 0 AND credit_minor = 0) OR (credit_minor > 0
AND debit_minor = 0)` at
`database/migrations/2026_04_28_050139_add_foundation_integrity_constraints.php:54`.
The aggregate balance is enforced only at the application layer in
`JournalEntryController` submit/post paths. This is the single highest-risk
accounting defect: an unbalanced entry can be persisted by any code path that
bypasses the controller (seeders, future internal services, raw `update()`
on lines).

### M3-02 Journal lines mutable after submission (high, reported)

`JournalLine` has no observer guard, no DB trigger, and no `protected
$fillable` filtering against status-aware mutation. The
`JournalEntryController::update()` checks the parent entry's status, but a
direct `$line->update(...)` call (or a future endpoint that mutates lines)
will not block changes to a submitted or posted entry. The balance check on
submit becomes a snapshot, not an invariant.

### M3-03 Posted-entry status mutable back to draft (high, reported)

No model guard prevents `$entry->update(['status' => 'draft'])` on a posted
entry. `destroy()` only allows DRAFT/SUBMITTED/REJECTED cancellation, but the
underlying Eloquent surface is wide open. For an immutability-required audit
trail, this should be enforced in `JournalEntry::saving` or via a DB trigger.

### M3-04 Reversal bypasses approval workflow (medium, reported)

`app/Application/JournalEntries/CreateJournalEntryReversal.php:20-35` creates
the reversal already in `STATUS_POSTED` with `posted_at` and `posted_by_user_id`
in one step. There is no maker-checker on the reversal itself. If a reversal
needs the same scrutiny as the original entry — as it usually does in
regulated accounting — this is a control gap. If reversals are deliberately
fast-path, that decision should be documented.

### M3-05 No currency-mismatch guard at line level (medium, reported)

`JournalLine.currency` is a raw string and is not validated to match the entry's
currency at the request layer. `Support/Finance/JournalEntryDraft` validates
in-memory but it is not authoritative. Mixed-currency entries pass the per-line
"either debit or credit > 0" constraint.

### M3-06 Available-balance denormalized column with no row lock (medium, reported)

`customer_accounts.unavailable_amount_minor` is a stored column and is read
without `lockForUpdate()` in `Support/Accounting/AccountingBalanceCalculator`.
Concurrent withdrawal flows can both observe the same available balance,
debit, and overdraft. The mitigation here is to wrap balance read + post in a
single transaction with a row lock on the customer account, every time.

### M3-07 No non-overdraft negative-balance guard (medium, reported)

`CustomerAccount` does not carry an `allows_overdraft` flag; the calculator
does not refuse to post if the result would be negative on a savings account.
A bug elsewhere that constructs a debit can drive a savings account negative,
silently.

### M3-08 Journal entry `agency_id` is nullable, allows cross-agency posting (medium, reported)

`journal_entries.agency_id` nullable, combined with institution-scoped ledger
accounts, allows journals that touch ledger accounts whose agency wasn't
verified against the caller's agency. The agency-scoped FK migration enforces
match only when both sides are scoped.

### M3-09 Account holds can orphan if creating flow rolls back (medium, reported)

`AccountHold` lifecycle has no automatic release on the failure path of the
flow that created the hold (e.g., loan application rejected after a hold
was placed). There is no scheduled sweeper documented in
`backlogs/defined-modules-implementation-audit.md`. Manual release is the only
path. Long-running holds slowly poison available balance.

---

## Module 4 — Credit & Loans Engine

### M4-01 Same user can sign all four approval stages (critical, reported)

`app/Application/Loans/AdvanceLoanApproval.php:106-111` checks that previous
stages have a `decision = 'approved'` row but not that the actor on this stage
differs from previous actors. The stakeholder spec is explicit that the four
stages are Setup → Comptabilité → Contrôle → Direction, which is a separation
of duties requirement by name. One user with all four permissions can advance
a fraudulent loan end-to-end. Test
`test_four_step_loan_approval_workflow_blocks_skips_rework_and_direct_status_forgery`
exercises sequencing but never same-actor traversal.

### M4-02 `LoanPolicy::view()` does not scope by agent assignment (high, reported)

`app/Policies/LoanPolicy.php:17-20` chains `view()` into `viewAny()`. Once a
user has `loans.view`, any loan record is visible if the controller layer's
agency scope is loose. The policy itself should require
`credit_agent_id === user->id` or platform-admin, or at minimum verify
`agency_id`. Controller-level scoping is not a substitute for the policy
boundary.

### M4-03 Penalty accrual race condition (high, reported)

`app/Application/Loans/AssessLoanArrearsAndPenalties.php:84-107` performs a
TOCTOU check (`alreadyPenalizedThisMonth`) followed by an insert/update. The
loan row is locked, but `loan_arrears` is not, and no DB-side unique
constraint on `(loan_schedule_line_id, month)` exists. Two cron passes
overlapping under load can double-penalize. Idempotency depends on serial
execution, which Module 1 cannot fully guarantee (see M1-01).

### M4-04 Disbursement idempotency masks channel mismatch (high, reported)

`DisburseLoan.php:43-58, 104` keys idempotency on `loan-disbursement:<public_id>`
only. A second call with a different `channel` or `transfer_account_id` returns
the original disbursement record. Caller thinks a different channel succeeded;
ledger reflects the first channel. Should be either rejected as conflict or
keyed on the full payload hash.

### M4-05 Repayment allocation order is hardcoded (medium, reported)

`RecordLoanRepayment.php:531-545` lists the component groups as code, not as
product config. The system references `FormulaPolicyKey::RepaymentAllocationOrder`
but never reads it. If two products are supposed to allocate differently
(e.g., penalties-first for delinquent products), the engine cannot express
that without code change.

### M4-06 Schedule sum invariant not asserted (medium, reported)

`GenerateLoanSchedule.php:72-123` uses `splitWithFinalResidual()` for rounding
and pushes residuals to the last installment, but there is no post-condition
assertion that `sum(installments) == principal + total_interest + fees + taxes`.
A future component added to the schedule without updating the splitter loses
cents silently.

### M4-07 Interest rate not snapshotted at approval/disbursement (medium, reported)

`DisburseLoan.php:74-79` reads the rate from `loan_products.interest_rate`
live. If the product rate is edited after approval but before disbursement,
the schedule is generated at the new rate without any approval flag. Schedule
should reference a rate captured at approval time, not the live product row.

### M4-08 Early settlement: no idempotency on double-close (medium, reported)

`EarlyRepayLoan.php:35, 69-71` rejects already-CLOSED, but does not use a
distinguished status, does not call `lockForUpdate`, and reissues guarantee
release in a path that is `no-op safe` but writes audit events twice on a
concurrent retry.

### M4-09 Collateral release not status-guarded (medium, reported)

`CollateralController::release()` (~line 130) checks loan is closed but does
not check the collateral itself is still ACTIVE. An already-released
collateral can be "released" again — clean DB-level no-op but pollutes the
audit trail and confuses recovery teams.

### M4-10 Loan amount not validated against product bounds at apply time (low, reported)

`StoreLoanRequest` enforces `min:1` but not the product's min/max. The mismatch
is only surfaced later in the workflow. Should fail-fast at the request layer.

---

## Module 5 — Cash & Teller Operations

### M5-01 Deposits not bounded by `max_balance_limit_minor` (high, confirmed)

`app/Http/Controllers/Api/V1/TellerTransactionController.php` was grep'd for
`max_balance_limit_minor` and the symbol is not present. The withdrawal flow
does check `max_withdrawal_limit_minor`. A deposit can therefore push the till
balance arbitrarily above the operational ceiling, defeating cash-management
policy. The till schema does carry the column (added in
`2026_05_11_000000_finalize_stakeholder_complete_schema.php:274`), so this is
a service-layer gap, not a schema gap.

### M5-02 Till migration "missing columns" finding (withdrawn)

Sub-agent claimed `tills` table did not include `max_balance_limit_minor`,
`requires_denominations`, `is_central_till`, `currency`, etc. Re-verified:
`database/migrations/2026_05_11_000000_finalize_stakeholder_complete_schema.php:271-276`
adds all of them. The fields exist. M5-01 stands; the original "schema is
broken" claim does not.

### M5-03 Session uniqueness check is not atomic (high, confirmed)

`TellerSessionController.php:87` calls `hasOpenSessionForTill` /
`hasOpenSessionForTeller` (lines 268/276) which are plain `Builder::first()`
queries outside any transaction. Grep confirms no `lockForUpdate` in this
controller. Two concurrent opens of the same till or by the same teller can
both pass the guard. There is no `UNIQUE` partial index on `teller_sessions`
keyed to `status = 'open'` to backstop the application check. Two phantom
sessions on the same till poison reconciliation.

### M5-04 Reversal not wrapped in DB transaction (high, reported)

`TellerTransactionController::reverse()` (~lines 346-400) creates a reversal
journal entry, inserts a new `TellerTransaction` row, and updates the original
row's status without wrapping the three writes in `DB::transaction()`. A
partial failure leaves either a marked-reversed original with no reversing
transaction, or a free-floating reversing transaction with the original still
appearing live. Either way reconciliation breaks.

### M5-05 Self-reversal allowed without supervisor (medium, reported)

`TellerTransactionPolicy::reverse` only checks `cash.transactions.manage` and
agency scope. A teller with that permission can reverse their own deposit or
withdrawal with no second pair of eyes. For a cash module, reversal is the
fraud path; this needs explicit maker-checker.

### M5-06 Manual journal uses an undefined status (medium, reported)

`TellerTransactionController.php:495` creates manual journal rows with status
`'pending_review'`. `TellerTransaction.php:75-79` defines only `STATUS_POSTED`,
`STATUS_CANCELLED`, `STATUS_REVERSED`. The string literal is silently outside
the defined enum, which means status filters and admin tooling will miss these
records.

### M5-07 Till reassignment not blocked when session is open (medium, reported)

`TillController` allows `assigned_user_id` change without verifying that the
till has no open session. The active session is orphaned from the till's
current assignee. Reconciliation, end-of-day balance ownership, and audit
trail all reference a teller who no longer "owns" the till.

### M5-08 Manual journal does not verify teller owns the session (medium, reported)

`TellerTransactionController::storeManualJournal` (~lines 403-422) accepts a
`TellerSession` argument but does not call the same `canUseSession` guard the
deposit and withdrawal paths use (`storeDeposit` line 49, `storeWithdrawal`
line 196). A supervisor or any cash-permitted user can post into another
teller's session, which destroys per-teller accountability that the rest of
the module relies on.

### M5-09 `str_contains($type, 'withdrawal')` in close balance math (low, confirmed)

`TellerSessionController::theoreticalBalanceMinor` (~lines 309-317) uses
substring matching to identify withdrawal-style types. Any future type that
contains the substring (`partial_withdrawal`, `withdrawal_reversal`) is
silently treated as a withdrawal. Fragile, replace with exact type constants.

---

## Cross-module patterns

Three themes recur across modules:

1. **Application-layer invariants without DB enforcement.** Journal balance
   (M3-01), session uniqueness (M5-03), penalty idempotency (M4-03), batch
   concurrency (M1-01). These all rely on Eloquent code paths and a single
   request flow. Any second flow (job, future endpoint, raw SQL, retry under
   load) silently breaks them. A pass to add DB CHECK constraints, partial
   unique indexes, and `lockForUpdate` to the critical paths would close most
   of the high-severity findings in one effort.

2. **Policies that defer agency/assignment scope to the controller.**
   `LoanPolicy::view` (M4-02), `ClientGuarantorPolicy` (M2-06), and several
   controllers in Module 3 lean on `StaffAgencyScope` and explicit query
   filters. The policy is supposed to be the second wall; right now it is a
   single wall, and the controller is also that same wall.

3. **Maker-checker is partial.** Loan approval can be single-actor (M4-01).
   Journal reversal is auto-posted (M3-04). Cash reversal is self-serve
   (M5-05). KYC has two surfaces with different rules (M2-04). The
   institution-level intent is clearly maker-checker; the implementation is
   selectively enforced.

## What this review does not cover

- Full integration with future HR, FX, insurance, Islamic finance, EMF
  reporting, SMS, alerts, and dashboard scope. Per
  `stakeholderResources/definedModules.md`, those are outside Modules 1-5.
- Production runtime config — secrets, SMS provider credentials, deployed
  rate-limit values, queue worker concurrency.
- Performance/load behavior under stress. Race condition findings are
  reasoned from code, not reproduced under load.
- Front-end and external integration surfaces.

## Suggested next steps

The findings here should not be silently merged into the existing
implementation audit, which currently reports Modules 1-5 as "implemented".
Recommended ordering:

1. Treat the **critical** findings (M2-01 proxy enforcement, M3-01 journal
   balance DB CHECK, M4-01 separation of duties) as blocking items before
   declaring Modules 2/3/4 production-ready.
2. Run a focused remediation pass on the **high** findings (M1-01, M2-02,
   M2-03, M3-02, M3-03, M4-02, M4-03, M4-04, M5-01, M5-03, M5-04).
3. The **medium** and **low** findings can land in the existing module
   completion backlogs without a separate document.

No code changes have been made in this review.

---

## 2026-05-17 Calibration Note

After publication this review was independently re-verified in
`backlogs/defined-modules-adversarial-review-2026-05-17-investigation-backlog.md`.
The investigation found that several findings here were over-confident
or factually wrong. The investigation backlog is the authoritative
remediation source; this section records the corrections so this
document does not mislead future readers.

Rejected (not defects):

- **M2-05** — `per_page=1` is normal pagination, not a security defect.
  Bounded `[1, 100]` is sufficient; enumeration risk belongs to rate
  limiting / audit / PII masking, not a pagination floor.
- **M4-10** — `LoanController::resolveStoreReferences()` calls
  `validateProductAmount()` before creation. Bounds ARE enforced at
  apply time; the FormRequest just delegates the bounds check to the
  controller. Still pre-persistence.
- **M5-08** — `TellerTransactionController::storeManualJournal()`
  does call `canUseSession()`. The sub-agent claim was stale relative
  to current code.

Severity / mechanism overstated (partially approved):

- **M2-01** — wording "proxies are advisory data" is too strong. An
  authorizer `app/Support/Crm/ClientProxyMandateAuthorizer.php` exists
  and is correct in isolation. The real defect is that the teller
  transaction layer does not call it. Risk stands; "cosmetic" framing
  was wrong.
- **M2-04** — I claimed the client-KYC sister surface enforces
  maker-checker. Both surfaces actually have an override path.
  The real risk is the breadth of the `crm.kyc.override.self_verify`
  permission, not a single divergent endpoint.
- **M3-04** — "bypasses approval workflow" is accurate, but I implied
  transaction-safety risk. `CreateJournalEntryReversal::execute()` is
  itself transactional. The gap is maker-checker only.
- **M4-02** — `LoanPolicy::view()` is weak as a second wall, but
  controllers still apply agency scope, so I overstated "any
  loan visible".
- **M4-03** — I missed that the loan row IS locked in
  `AssessLoanArrearsAndPenalties`. The real gap is the lack of a DB
  unique constraint on `loan_arrears(loan_schedule_line_id)` as a
  backstop for raw writes or future code paths.
- **M5-04** — `CreateJournalEntryReversal::execute()` is internally
  transactional. The atomicity gap is in the outer
  `TellerTransactionController::reverse()` orchestration, which is
  what needs wrapping. Less severe than "phantom reversals".
- **M1-05** — `StaffUserController::update()` unsets `agency_name`
  before fill and resolves `agency_code` via `SyncStaffUser`. Current
  update path is safer than my broad warning suggested.

Lessons applied to future reviews:

1. Verify high-severity findings against current code before
   publishing severity ratings, not just two or three samples.
2. Distinguish "control absent" from "control exists but not wired".
   The first is a missing feature; the second is a configuration gap
   with a much smaller blast radius.
3. Treat sub-agent code claims as Reported, never Confirmed, until
   independently re-read. The Reported/Confirmed labels in this
   document should have been more conservative.
