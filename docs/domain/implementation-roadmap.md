# Implementation Roadmap

This roadmap orders features so foundational risks are resolved before high-risk financial workflows are added.

## Phase 0: Foundation Alignment

Goals:

- Confirm ID strategy: internal integer IDs plus public ULIDs.
- Establish staff-only phone/password login with activation OTP.
- Confirm single-institution multi-agency scope versus true multi-tenancy.
- Confirm agency hierarchy, cross-agency transfer rules, and reference numbering policy.
- Confirm `XAF` rounding, precision, and final-adjustment rules.
- Confirm document/file storage requirements.
- Confirm idempotency policy for all financial mutation endpoints.
- Confirm retention/redaction policy for PII and financial audit records.

Deliverables:

- Updated ADRs.
- Migration conventions for public IDs.
- Agency scoping and authorization matrix.
- Domain enum list.
- `XAF` money precision and rounding standard.
- Stakeholder-approved formula specification for interest, schedules, fees, penalties, balances, cash reconciliation, and reporting metrics.
- API mutation/idempotency standard.

## Phase 1: Administration And Staff

Build:

- Agencies.
- Additional staff profile fields beyond the reusable auth foundation.
- Agency, portfolio, and supervisor assignment workflow.
- Password reset and optional risky-login MFA using the OTP challenge model.
- Role/permission policies.
- Agency scoping policies.
- Batch procedure registry.

Why first:

- Every later module depends on staff, agency, and authorization.

## Phase 2: CRM / KYC

Build:

- Clients.
- Identity documents.
- Guarantors.
- Proxies.
- File metadata for photos/signatures/documents.
- KYC verification state.

Why second:

- Accounts and loans require verified customer records.

## Phase 3: Accounting Ledger

Build:

- Ledger accounts.
- Customer accounts.
- Journal entries and lines.
- Posting service/action with double-entry validation.
- Balance projections.
- Reversal mechanism.

Why before loans/cash:

- Loans and teller operations cannot be safely implemented without an accounting source of truth.

## Phase 4: Cash Operations

Build:

- Tills.
- Teller sessions.
- Deposits and withdrawals.
- Denominations.
- Till reconciliation.
- Manual journal entry capture.

Why before loan servicing:

- Repayments and disbursements may use teller/cash workflows.

## Phase 5: Loan Product And Application

Build:

- Loan products.
- Loan applications.
- Product rule validation.
- Approval workflow.
- Product term snapshots.

## Phase 6: Disbursement, Schedule, Repayment

Build:

- Schedule generation.
- Loan disbursement postings.
- Repayment allocation.
- Penalty assessment.
- Arrears/delinquency tracking.
- Loan closure.

## Phase 7: Reporting, Batch, And Reconciliation

Build:

- End-of-day batch execution.
- Ledger/account/till reconciliation reports.
- Portfolio transfer reports.
- Delinquency reports.
- Operational audit exports.

## Do Not Do Yet

- Do not create every stakeholder table in one migration wave.
- Do not store mutable balances as the source of truth.
- Do not expose internal integer IDs in API contracts for financial resources.
- Do not implement loan disbursement before ledger posting is complete.
- Do not implement cash withdrawals before till sessions and account availability checks exist.
- Do not implement document/photo/signature upload as raw URL fields.
- Do not implement approval workflows as direct status updates without transition history.
