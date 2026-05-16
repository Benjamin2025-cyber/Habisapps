# Stakeholder Formula Response Unblock Analysis

This analysis maps backlog items that were blocked because formula answers were pending to the stakeholder response register:

- `docs/domain/stakeholder-formula-responses.md`
- `docs/domain/stakeholder-formula-items-to-explain.md`
- `config/formulas.php`

Important: stakeholder answers are now available. `xaf_rounding` is approved as an implementation decision because the rule is clear and matches normal finance practice: physical cash precision is separate from ledger/account precision. Other formula gates should only open when their implementation matches the approved rule and is covered by tests.

## Summary

The stakeholder responses unblock planning and implementation design for several previously deferred areas:

- XAF precision split between whole-cash physical deposits and exact 2-decimal loan/account deductions.
- Flat loan interest on initial principal.
- Equal installment behavior.
- Principal/interest split behavior for flat-interest loans.
- Fees, VAT/tax, insurance, and guarantee deposit formulas.
- Capital-first repayment allocation.
- Grace-period behavior.
- Early repayment defaults and recovery priority.
- Rescheduling/refinancing direction with capitalization guardrails.
- Denomination line totals.
- Till theoretical balance and reconciliation difference.
- Portfolio outstanding, standard PAR30, and separate delinquent-amount reporting.

Some areas remain blocked or need internal reconciliation because the response itself contains `A preciser`, `A definir`, or conflicting fields:

- Value-date handling for posting-effective-date workflows. Partial-month behavior no longer blocks standard flat-interest schedules.
- Dossier fee exceptional setup cases require Direction manual decision. The normal trigger is resolved as credit committee validation/setup approval, with collection before disbursement.
- CTX/PAR transition behavior after 90 days.
- Pending transaction status implementation in individual workflows.

## Formula Gate Mapping

| Formula policy gate | Stakeholder response status | Backlog impact |
|---|---|---|
| `xaf_rounding` | Approved | Whole-XAF physical cash deposits are separate from exact 2-decimal loan/account ledger amounts. Loan debt must not be rounded to match cash denominations. |
| `loan_interest_method` | Approved | Flat interest on initial principal. Product `interest_rate` is interpreted as the total flat percentage for the loan term; total interest is divided across installments. |
| `loan_installment_amount` | Approved | Equal installments use flat interest, configured setup components, no standard partial-month proration, and final-installment residual absorption so approved totals reconcile exactly. |
| `repayment_allocation_order` | Approved | Clear scheduled principal, interest, fees, insurance, and tax before penalties, oldest due scheduled items first. Within scheduled dues, principal is first. Once collecting penalties, collect the oldest assessed penalty first. Same-day payments use the same deterministic order. Principal allocation reduces remaining principal; original principal remains the flat-interest base. Exact-account debit retains excess deposit on the customer account. |
| `fees_taxes_insurance` | Approved | Dossier fee, setup tax, loan insurance, and guarantee deposit are approved. Dossier fee is 3% of granted principal. Setup tax is 19.25% of granted principal plus total flat interest. Loan insurance is 2% of granted principal, upfront, and non-refundable on early closure. Guarantee deposit is 10% of granted principal, collected in cash before disbursement, held restricted, and released after full settlement. |
| `penalties_and_arrears` | Partially unblocked | Arrears, normal monthly penalty, grace-period behavior, and Q14 arrears carry-forward are approved. Late boundary is J+5. Unpaid amount is scheduled due less allocated payment. Penalty is 5,000 + 2% of unpaid scheduled due, with no penalty below 1,000 XAF. During grace, principal is not deferred, interest continues, interest is not capitalized, and penalties are disabled. Q14 is not classic capitalization: open scheduled dues carry forward as a calculated arrears view, original principal and flat-interest base do not change, no penalty-on-penalty, and no journal entry is posted merely because arrears carried forward. CTX/PAR transition behavior remains separate reconciliation work. |
| `account_balances` | Approved | Accounting balance uses posted/validated journal entries only and normal-balance-side formulas. Reversals affect balance through posted reversal entries. Available balance is accounting balance minus account minimum, unavailable amount, and active holds. Pending withdrawals reduce availability only when recorded as holds/unavailable amounts. Accounting movement reports use business/posting date; operational reports may use transaction date. |
| `cash_till_reconciliation` | Approved | Denomination counts, theoretical balance, zero-tolerance difference, and till-close handling for pending operations are approved. Pending teller transactions are shown and excluded from theoretical balance until posted; close blocks unless they are posted, cancelled, or supervisor-carried-forward. |
| `portfolio_reporting_metrics` | Approved | Portfolio outstanding is approved for active non-written-off exposure. Standard PAR30 uses outstanding balance of loans with at least one installment more than 30 days past due, not overdue amount only. Overdue amount is a separate delinquent-amount metric. Collection performance is recognized collection over expected collection; expected minus recognized is the shortfall/gap. Rescheduled loans keep original portfolio identity and are reported through a restructured dimension/watchlist. |

## Backlog Items Now Unblocked For Design

These items were blocked because formulas were unknown. They can now move from "unknown business rule" to implementation design, specs, and formula-driver work.

### Module 3: Accounting Architecture

Backlog references:

- `backlogs/module-3-accounting-architecture-backlog.md`: balance, movement, availability, posting, cash, reporting, fee, interest, penalty, and repayment-allocation formulas remain unresolved, while `xaf_rounding` is approved separately.
- `backlogs/module-3-accounting-architecture-backlog.md`: `Not In Module 3 Safe Slice` entries for accounting balance, available balance, movement totals, reports, and formulas.
- `backlogs/module-3-accounting-architecture-backlog.md`: open question for available-balance formula and whether holds reduce availability immediately.

Now unblocked for design:

- Rounding/precision standard: physical XAF cash deposits are whole-cash amounts; loan/account balances and deductions can carry exact 2-decimal XAF values; final installment components reconcile residual formula differences to approved totals.
- Accounting balance baseline: posted/validated ledger entries only, with normal-balance-side formulas.
- Available balance baseline: accounting balance less account minimum, unavailable amount, and active holds.
- Fee/tax/insurance/guarantee deposit formula specs for future postings.
- Repayment allocation direction for future loan/account posting.
- Movement reports split by report type: accounting reports use business/posting date; operational reports may use transaction date.

Still needs reconciliation:

- Account types: recovery accounts, ordinary savings accounts, current accounts, and their account-product rules.
- Journal posting/reversal vocabulary remains partly architectural, not fully solved by formula responses.
- Chart-of-accounts taxonomy and account-product catalog still need accounting sign-off outside formula answers.

### Module 5: Cash Infrastructure

Backlog references:

- `backlogs/module-5-cash-infrastructure-backlog.md`: deferred teller sessions, deposits/withdrawals, cash receipts, till balances, denomination line totals, reconciliations, and cash limits.
- `backlogs/module-5-cash-infrastructure-backlog.md`: open questions for denominations, till ledger links, opening/theoretical/actual balance, reconciliation difference, transaction posting, and manual journal workflow.

Now unblocked for design:

- Denomination line total formula: denomination value * quantity.
- Accepted denominations rule: all denominations, coins tracked, damaged cash accepted, denominations not deactivatable.
- Count requirement: opening and closing counts required.
- Till opening balance rule: opening balance equals prior closing balance for the next business day.
- Till theoretical balance: opening balance + deposits - withdrawals.
- Reconciliation difference: actual counted cash - theoretical balance.
- Reconciliation tolerance: zero tolerance for posted cash differences.
- Pending transactions should be shown on the close state and excluded from theoretical balance until posted.
- Pending operations block close unless posted, cancelled, or supervisor-carried-forward.

Still needs reconciliation:

- Teller transaction posting workflow and idempotency.
- Whether every till must link to a ledger account and which account classes are valid.
- Cash limit enforcement and approval override rules were not answered by the formula questionnaire.
- Manual journal approval workflow remains outside the formula answers.

### Module 1: Batch / End-Of-Day Jobs

Backlog references:

- `backlogs/module-1-administration-security-backlog.md`: end-of-day jobs that compute balances, penalties, interest, reconciliation differences, reports, or portfolio metrics remain blocked until formula policies are approved.

Now unblocked for design:

- End-of-day penalty assessment can be designed around the monthly penalty rule: 5,000 + 2% unpaid, after 5 grace days, no penalty below 1,000 XAF.
- End-of-day till close/reconciliation can be designed around theoretical balance and zero-tolerance difference.
- Reporting jobs can be designed around portfolio outstanding, standard PAR30, delinquent-amount reporting, collection performance rate, and collection shortfall.
- Movement reports can use batch day-close as the reporting boundary.

Still needs reconciliation:

- Penalty capitalization and CTX/PAR transition behavior.
- Batch execution remains blocked at runtime until formula gates and drivers exist.

### Foundation / Credit Structure

Backlog references:

- `backlogs/foundation-migration-backlog.md`: loan products store formula policy keys without hardcoding formulas.
- `backlogs/foundation-migration-backlog.md`: loan schedules are generated snapshots, but no schedule generator exists until formulas are approved.

Now unblocked for design:

- Loan product formula policies can be configured for flat interest, equal installment behavior, fee/tax/insurance/deposit formulas, capital-first allocation, penalties, grace period, early repayment, and rescheduling.
- Loan schedule generator uses flat interest on initial principal and equal installments. Standard schedules do not need day-count/partial-month calculation, and final-installment residual absorption is implemented for component splits.
- Schedule lines can include principal, interest, tax, insurance/fees where applicable, penalty, and remaining principal projections.
- Early repayment defaults are approved: collect remaining scheduled flat interest, no early fee, no insurance refund, guarantee release after full settlement, and Direction-only future-interest waiver or negotiated total-interest concession.
- Early repayment recovery priority is approved: debit the credit/repayment account first, then other linked same-client accounts by configured priority. Multi-account fallback still needs implementation controls.
- Rescheduling can keep the same loan identity while preserving schedule/version history. Standard rescheduling preserves flat-interest logic; capitalization of interest or penalties is blocked unless a dedicated credit-committee and accounting workflow approves it.

Still needs reconciliation:

- CTX/PAR transition and write-off treatment after 90 days.
- Partial-month behavior for exceptional periods.
- Value-date offset behavior.
- Exact accounting postings for setup fees, VAT, insurance, guarantee deposit, disbursement, repayment, penalty, early repayment, and rescheduling.
- Multi-account automatic recovery for early repayment fallback across linked client accounts.

## Backlog Items Not Unblocked By Formula Responses

These open items were not primarily blocked by formula answers:

- Module 2 KYC vocabulary, encryption, maker-checker, identity-document uniqueness, guarantor/proxy modeling, and public download decisions.
- Module 3 global/shared ledger account support and final chart-of-accounts taxonomy.
- Module 3 sector-reference integration with client metadata.
- Module 5 final till type vocabulary, central till semantics, one-session-per-teller/till policy, cash limit override semantics, and manual journal workflow.
- Public/temporary KYC file download endpoints.
- New stakeholder-added modules such as HR, bancassurance, FX, Islamic finance, SMS banking, dashboards, and alerting.

## Recommended Next Backlog Actions

1. Keep `xaf_rounding` approved in `config/formulas.php`; implement and test the consumers that enforce physical-cash scale versus account/loan scale.
2. For each gate, write an implementation spec from `docs/domain/stakeholder-formula-responses.md`.
3. Split each remaining gate into implementation, config approval metadata, tests, and API/service integration.
4. Keep unresolved reconciliation items in `docs/domain/stakeholder-formula-items-to-explain.md` or an internal architecture decision before enabling runtime execution.
5. Do not update existing backlog checkboxes just because stakeholder answers exist. Mark them complete only when code, tests, and formula-gate approval exist.

## Candidate Implementation Order

1. `xaf_rounding`: smallest cross-cutting rule and prerequisite for all financial amounts.
2. `fees_taxes_insurance`: approved setup-charge formulas for fee, tax, insurance, and guarantee deposit; implement accounting postings and collection controls around the assessed amounts.
3. `loan_interest_method` and `loan_installment_amount`: flat interest plus equal installment schedule generation.
4. `repayment_allocation_order`: capital-first repayment allocation.
5. `penalties_and_arrears`: monthly penalty, arrears carry-forward, and grace-period behavior; defer CTX/PAR transition and write-off treatment.
6. `account_balances`: posted ledger accounting balance first; available balance after pending/hold/account-type policy is settled.
7. `cash_till_reconciliation`: denomination counts, theoretical balance, zero-difference close, and pending-operation close controls.
8. `portfolio_reporting_metrics`: portfolio outstanding, standard PAR30, delinquent amount, collection performance rate, collection shortfall.
