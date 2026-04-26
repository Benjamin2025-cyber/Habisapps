# Loan Lifecycle

This document defines the corrected loan engine model based on stakeholder module 4 and the ER mapping.

## Lifecycle States

Recommended loan states:

- `draft`
- `submitted`
- `under_review`
- `rejected`
- `cancelled`
- `approved`
- `disbursed`
- `active`
- `in_arrears`
- `closed`
- `written_off`

The exact list can be reduced, but transitions must be explicit.

Avoid encoding every approval step as a top-level loan status unless reporting requires it. Prefer a compact lifecycle status plus approval transition records for step-level detail.

## Workflow Steps

Stakeholder steps:

- Montage
- Comptabilité
- Contrôle
- Direction

Implementation rules:

- Each step creates an approval transition record.
- The transition records decision, actor, comments, timestamps, and optional rejection reason.
- Only allowed transitions can be executed.
- Controllers must not mutate workflow state directly.
- A rejection or return must state whether the loan can be resubmitted.
- A later approval step must not run before all previous required steps are approved.

## Product Rules

Loan products define:

- min/max amount
- min/max duration
- interest rate
- tax/VAT behavior
- fees
- insurance
- guarantee deposit
- penalty formula
- grace period rules
- linked ledger accounts

Rules:

- Validate loan applications against active product rules.
- Snapshot product terms onto the loan at approval/disbursement.
- Later product edits must not change existing loans.
- Product deactivation must prevent new applications but must not break existing active loans.

## Schedule Generation

The loan engine must generate amortization schedules.

Schedule rows include:

- installment number
- due date
- principal
- interest
- tax
- penalty
- total due
- remaining principal
- status

Rules:

- Schedule generation is deterministic and tested.
- Schedule changes require explicit rescheduling actions.
- Repayments allocate against schedule rows by defined allocation order.
- Allocation order must be documented before implementation, for example penalty, tax, interest, principal, or another stakeholder-approved order.
- Rounding behavior must be deterministic and tested.

## Financial Posting

Loan operations must post ledger entries:

- Approval may not post money.
- Disbursement posts principal movement.
- Fee/insurance/guarantee deposit assessment posts the relevant receivable/income/liability entries.
- Repayment posts cash/account movement and loan balance reduction.
- Penalty assessment posts penalty receivable/income where applicable.
- Write-off posts explicit accounting entries and does not delete the loan.

Outstanding balances are derived from schedules, repayments, and ledger entries.

All externally callable loan mutations require idempotency keys.

## Collateral

Collateral records should include:

- collateral type
- estimated value
- valuation date
- valuation actor
- guarantor link when applicable
- lifecycle status
- release/sale fields

Use stable ASCII values for enum storage.

## Delinquency Tracking

Delinquency tracking records interactions and promises to pay.

Minimum fields:

- client
- loan
- tracking date
- reason
- appointment type/date
- promised amount
- comments
- staff actor

Rules:

- Promises to pay do not change financial balances.
- Follow-up records are audit/business records, not accounting postings.
- Broken promises should create follow-up state, not silently overwrite previous promises.

## Rescheduling And Refinancing

Do not mutate original schedule history in place.

Rules:

- Rescheduling creates a new version or explicit adjustment record.
- Prior schedule rows remain auditable.
- Refinancing is a separate business operation with its own approvals and ledger postings.
