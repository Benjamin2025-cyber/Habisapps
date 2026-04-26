# Accounting Ledger Rules

The ledger is the highest-risk part of the platform. This document defines non-negotiable accounting rules for implementation.

## Core Principle

Every financial operation must be represented as a balanced, immutable journal entry.

Examples:

- Cash deposit
- Cash withdrawal
- Loan disbursement
- Loan repayment
- Fee assessment
- Insurance charge
- Penalty assessment
- Manual journal entry
- Till transfer
- Reversal or correction

## Required Tables

### ledger_accounts

Stores the chart of accounts.

Minimum fields:

- `id`
- `code`, unique
- `name`
- `account_class`
- `type`
- `normal_balance`
- `status`
- timestamps

Rules:

- Ledger account codes are stable accounting references and should not be reused after deactivation.
- Deactivating a ledger account prevents new postings but must not break historical reporting.

### journal_entries

Stores posting headers.

Minimum fields:

- `id`, `public_id`
- `reference_number`, unique
- `transaction_date`
- `agency_id`
- `source_type`, `source_id`
- `description`
- `status`: draft, posted, reversed, voided
- `posted_by_user_id`
- `posted_at`
- `reversed_by_entry_id`, nullable
- timestamps

### journal_entry_lines

Stores debit/credit lines.

Minimum fields:

- `id`
- `journal_entry_id`
- `ledger_account_id`
- optional `customer_account_id` for sub-ledger context
- `direction`: debit or credit
- `amount`
- `currency`
- `line_memo`
- timestamps

Rules:

- `ledger_account_id` is required on every line.
- `customer_account_id` is optional context and must belong to the same agency/client context required by the source operation.

## Invariants

- A posted journal entry must have at least two lines.
- Total debit must equal total credit per currency.
- All lines in a single journal entry must use the same currency unless an explicit foreign-exchange posting model is introduced.
- Posted entries are immutable.
- Corrections are reversal entries plus new corrected entries.
- Draft entries can be voided; posted entries cannot be voided.
- All amounts are positive decimals; direction carries debit/credit meaning.
- No float arithmetic is allowed.
- Financial actions must run inside database transactions.
- External side effects must happen after the database transaction commits.
- Every external financial mutation must be idempotent.
- Every posting must have a source reference to the domain operation that created it.

## Balance Projections

Balances shown in stakeholder screens should be projections:

- accounting balance
- available balance
- unavailable amount
- daily movement
- cumulative debit movement
- cumulative credit movement
- outstanding principal
- due amount
- unpaid amount

Projection rules:

- A projection must be rebuildable from ledger entries and domain schedules.
- If stored for performance, it must include reconciliation tests.
- Projection updates must occur in the same transaction as the posting that changes them.
- Available balance must subtract holds, unavailable funds, pending withdrawals, and other constraints from accounting balance.
- Do not expose a projection unless its rebuild rule is documented.

## Customer Accounts Vs Ledger Accounts

Avoid polymorphic foreign keys like `account_id -> customer_accounts.id OR ledger_accounts.id`.

Use one of these safe designs:

- `journal_entry_lines.ledger_account_id` always points to a ledger account, plus optional `customer_account_id` for customer sub-ledger context.
- Or represent customer accounts as ledger-account-backed records with a one-to-one relationship.

The first option is recommended for this project because stakeholder screens need both customer account information and general ledger reporting.

## Manual Journal Entries

Manual journal entries are allowed only as controlled accounting operations.

Rules:

- Draft entries can be edited.
- Posting validates debit equals credit.
- Posted entries cannot be edited or deleted.
- Reversal requires permission and reason.
- Every manual entry must include reference number, description, actor, and agency.

## Holds And Unavailable Funds

Customer account availability requires a separate hold/reservation model.

Examples:

- guarantee deposit holds
- pending withdrawals
- legal/account freezes
- collateral-related restrictions
- operational corrections pending approval

Rules:

- Holds must have amount, currency, reason, actor, start time, optional expiry, and release actor/time.
- Holds do not replace ledger postings.
- Available balance is accounting balance minus active holds and other approved constraints.

## Reconciliation Requirements

At minimum, the implementation must support:

- ledger trial balance
- customer account balance rebuild
- till theoretical balance rebuild
- loan principal/interest outstanding rebuild
- projection-vs-ledger mismatch detection
