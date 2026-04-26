# Cash Operations

This document defines teller and cash-control implementation guidance.

## Till Setup

Tills represent physical or logical cash drawers.

Required controls:

- Each till belongs to an agency.
- Each till has a linked ledger account.
- Each till has assignment rules for teller users.
- Each till has max balance and max withdrawal limits.
- Till state must prevent transactions when closed.
- A user should not have two active teller sessions unless explicitly allowed by policy.
- A till should not have two active sessions at the same time.

## Teller Sessions

Introduce teller sessions before allowing real cash operations.

Recommended fields:

- till
- teller user
- opened_at, closed_at
- opening balance
- opening denomination count when denomination control is required
- closing theoretical balance
- counted closing balance
- status

Reasoning:

- Stakeholder screens mention till state, opening balance, and reconciliation. A session model gives a clean lifecycle boundary.

## Deposits And Withdrawals

Each validated teller transaction must:

- belong to a till session
- reference a customer account
- have direction: deposit or withdrawal
- validate limits and account availability
- create a receipt/event number
- post a balanced journal entry
- become immutable after validation
- require an idempotency key for API-created transactions
- record the actor and client-facing receipt data

Cancellation:

- Do not delete validated transactions.
- Use reversal postings with actor, timestamp, and reason.
- Require permission for reversal/cancellation.

## Denominations

Currency denomination records define accepted notes/coins.

Reconciliation lines store:

- denomination
- quantity
- line total

Rules:

- `line_total = denomination value * quantity`.
- Actual cash total is sum of reconciliation lines.

## Reconciliation

End-of-day reconciliation compares:

- theoretical balance from ledger/till session postings
- actual counted balance from denominations
- difference

Rules:

- Differences require explicit handling.
- Any adjustment must create a controlled journal entry.
- Reconciliations are audit-sensitive and should not be editable after approval.
- Closing a session with unresolved difference requires a configured approval path.
- The system must prevent new transactions on a closed session.

## Manual Journal Entries

Manual journal entries should use the same ledger posting model.

Rules:

- Draft before post.
- Validate total debits equal total credits.
- Require description, reference number, actor, agency, and reason.
- Posted entries are immutable.
