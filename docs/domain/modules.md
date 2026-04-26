# Domain Modules

These modules are derived from `stakeholderResources/definedModules.md` and corrected for backend implementation. The stakeholder document is the business inventory; this document defines stable engineering boundaries.

## Cross-Cutting Rules

- Every state-changing financial API must be idempotent.
- Every financial operation must post through the ledger or create a domain record that later posts through a controlled action.
- Every module must enforce agency scope where agency ownership exists.
- Every module that handles PII, documents, approvals, cash, or postings must emit audit records.
- Public API responses should expose public IDs and business references, not internal integer IDs, once public IDs exist.

## 1. Administration & System Security

Owns institution structure, staff identity, access control, and operational automation.

Responsibilities:

- Staff users, authentication, phone/email verification, OTP lifecycle, password lifecycle, and token/session policy.
- Roles, permissions, and authorization policies for all modules.
- Agencies, branch metadata, agency managers, staff assignment, and reporting hierarchy.
- End-of-day batch procedure registry, execution ordering, status tracking, and audit trail.

Out of scope:

- Customer KYC profiles belong to CRM.
- Accounting entries belong to the ledger/accounting module.
- Loan approval workflow belongs to the credit module, even when staff permissions are checked by this module.

## 2. CRM & Customer Relationship Management

Owns customer identity, KYC data, guarantors, proxies, and collection assignments.

Responsibilities:

- Customer profiles, identification documents, contact details, photos/signatures, verification status, and risk attributes.
- Guarantor records and their identity/contact details.
- Account proxies/mandates, authorization dates, status, and signature references.
- Collection configuration such as collection frequency, assigned collection agent, and target collection amount.

Out of scope:

- Account balances and financial postings belong to accounting.
- Loan collateral valuation belongs to credit, even if linked to guarantors.

## 3. Accounting & Financial Architecture

Owns the chart of accounts, customer accounts, immutable ledger postings, and balance projections.

Responsibilities:

- General ledger account catalog and account classification.
- Customer accounts and their lifecycle.
- Journal entries and journal lines with strict double-entry invariants.
- Balance projections for accounting, available, unavailable, debit movement, and credit movement values.
- Sector and sub-sector references used for loan/activity reporting.

Critical rule:

- Stored account balances are projections. The authoritative source of financial truth is immutable, balanced journal posting history.

## 4. Credit & Loan Engine

Owns loan products, applications, approval workflow, amortization schedules, collateral, repayments, delinquencies, and portfolio transfers.

Responsibilities:

- Loan product rules: min/max amount, duration, rates, fees, insurance, guarantee deposit, and penalty rules.
- Loan applications and lifecycle states from draft through approval, disbursement, active servicing, closure, rejection, or cancellation.
- Approval workflow with explicit decisions, comments, actor, timestamp, and transition audit.
- Amortization schedule generation and schedule state.
- Collateral, collateral items, guarantor links, valuation, and release/sale lifecycle.
- Delinquency tracking, promises to pay, follow-up appointments, and manager portfolio transfers.

Critical rule:

- Loan financial state must be derived from disbursements, schedules, repayments, penalties, and ledger entries. Do not allow arbitrary mutation of outstanding principal or due amounts.

## 5. Teller & Cash Operations

Owns physical cash handling, tills, teller transactions, manual journal entry capture, and end-of-day reconciliation.

Responsibilities:

- Till setup, assignment, limits, opening/closing state, and cash-session lifecycle.
- Deposits, withdrawals, and receipts tied to customer accounts and ledger postings.
- Manual journal entry capture with double-entry validation.
- Currency denominations and physical cash counts.
- Till reconciliation comparing theoretical ledger-derived cash balance with counted cash.

Critical rule:

- Cash movement cannot be treated as a standalone record. Every validated deposit, withdrawal, till movement, and manual journal entry must post balanced ledger entries.
