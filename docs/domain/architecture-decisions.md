# Architecture Decisions

This document records decisions needed before implementing stakeholder modules. It supersedes raw UI-derived schema assumptions when there is a conflict.

## ADR-001: IDs And Public References

Decision:

- Keep internal database primary keys as Laravel integer `id` values for the current foundation.
- Add public immutable identifiers, preferably ULIDs, to business records that are exposed through APIs or printed on receipts/contracts.
- Use domain reference numbers for business-facing codes such as loan number, account number, agency code, event number, matricule, and receipt number.

Reasoning:

- The stakeholder ER mapping assumes UUID primary keys everywhere, while the current foundation intentionally uses integer keys.
- Public IDs prevent enumeration and avoid exposing internal row counts.
- Business reference numbers remain meaningful to staff and customers, while public IDs remain API-safe.

Implementation guidance:

- Add `public_id` to externally addressable tables.
- Keep `id` for foreign keys unless a module-level ADR decides otherwise.
- Never expose internal integer IDs in public API responses where a public ID exists.

## ADR-002: Authentication Model

Decision:

- Staff authentication remains Sanctum bearer-token based for API access.
- The stakeholder requirement for OTP verification is accepted as a required future authentication capability.
- Registration remains disabled by default. Staff creation should be administrative or invite-based.

Open decision:

- Confirm whether staff login uses email, phone number, or both.
- Confirm whether OTP is required only for first verification, risky login, password reset, or every login.

Implementation guidance:

- Extend users with staff profile fields only after confirming login identifier.
- Store OTP codes hashed, not plaintext.
- OTPs must expire, be single-use, and be rate-limited by phone/IP/purpose.

## ADR-003: Agency Scoping

Decision:

- Agency scoping is required.
- This is not the same as multi-tenancy. The product currently appears to serve one institution with multiple agencies/branches.

Implementation guidance:

- Add `agency_id` to staff, customers, accounts, loans, tills, and operational records where branch ownership matters.
- Enforce agency authorization in policies and queries.
- Do not add tenant-global abstractions until a real multi-institution requirement exists.

## ADR-004: Ledger-First Accounting

Decision:

- The ledger is the source of truth for all financial balances.
- Customer account balances and loan balances are projections derived from immutable journal entries.

Reasoning:

- Stakeholder screens show balances as fields, but a finance system must not let application code arbitrarily mutate balances.
- Double-entry ledger postings provide auditability, correction via reversal, and reconciliation support.

Implementation guidance:

- Build `ledger_accounts`, `journal_entries`, and `journal_entry_lines` before implementing deposits, withdrawals, disbursements, repayments, fees, or penalties.
- Every posted journal entry must balance: total debits equal total credits.
- Posted entries are immutable. Corrections use reversal entries.

## ADR-005: Derived Financial State

Decision:

- Fields such as `available_balance`, `outstanding_principal`, `due_amount`, `total_unpaid_amount`, and cumulative movements are projections.
- Store projections only when needed for performance, and make them rebuildable from ledger/schedule/payment history.

Implementation guidance:

- Prefer query projections first.
- If persisted projections are introduced, update them only inside the same transaction as the authoritative event/posting.
- Add reconciliation tests for projection rebuilds.

## ADR-006: Workflow State

Decision:

- Loan approval workflow must be modeled as explicit state transitions, not a set of nullable date columns.

Implementation guidance:

- Use a loan status/state field plus an append-only transition table.
- Approval records must include step, decision, actor, timestamp, comments, and optional rejection reason.
- Enforce allowed transitions in an action/state machine, not controllers.

## ADR-007: Currency And Monetary Precision

Decision:

- The platform must use an explicit currency on every monetary amount.
- The base operating currency is `XAF`.
- Multi-currency support is out of scope unless a future ADR explicitly introduces it.
- Decimal storage is acceptable for early implementation only with explicit precision/scale and `brick/money` in PHP.

Open decision:

- Confirm rounding policy for `XAF` amounts before implementing accounting, accounts, cash, or loans.

Implementation guidance:

- Do not create money columns without an adjacent currency column unless the currency is guaranteed by table-level invariant and documented.
- Use one precision/scale convention across the system. For user-facing `XAF` amounts, assume whole monetary units unless stakeholders approve fractional accounting precision.
- Never use floating-point types.

## ADR-008: Audit, Retention, And PII

Decision:

- Customer identity, documents, staff actions, financial postings, approvals, reversals, and teller operations are audit-sensitive.
- Finance records should prefer immutable lifecycle records and reversals over deletion.

Implementation guidance:

- Do not add soft deletes by habit to financial history tables.
- PII access must be policy-gated and auditable.
- File/document storage must keep metadata, verification state, owner, checksum, and access policy.
- Define retention and redaction rules before production.

## ADR-009: Financial API Idempotency

Decision:

- All externally callable financial mutations must require an `Idempotency-Key`.

Implementation guidance:

- Authentication endpoints may bypass idempotency persistence to avoid storing issued tokens.
- Financial endpoints must persist idempotency records and never store secrets in replay snapshots.
- Reusing a key with a different request fingerprint must return conflict.
- Client-generated duplicate retries must return the original safe result or a deterministic conflict, not perform a second posting.
