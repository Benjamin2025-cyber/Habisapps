# Stakeholder Formula Response Unblock Analysis

This analysis maps backlog items that were blocked because formula answers were pending to the stakeholder response register:

- `docs/domain/stakeholder-formula-responses.md`
- `docs/domain/stakeholder-formula-items-to-explain.md`
- `config/formulas.php`

Important: stakeholder answers are now available, but the runtime formula gates in `config/formulas.php` are still `approved: false`. Nothing should execute formula-dependent behavior until the relevant rule is converted into an implementation spec, assigned an owner/date, covered by tests, and mapped to a formula driver.

## Summary

The stakeholder responses unblock planning and implementation design for several previously deferred areas:

- XAF precision and no-rounding behavior.
- Flat loan interest on initial principal.
- Equal installment behavior.
- Principal/interest split behavior for flat-interest loans.
- Fees, VAT/tax, insurance, and guarantee deposit formulas.
- Capital-first repayment allocation.
- Grace-period behavior.
- Early repayment defaults and recovery priority.
- Rescheduling/refinancing direction.
- Denomination line totals.
- Till theoretical balance and reconciliation difference.
- Portfolio outstanding, PAR30, and collection performance direction.

Some areas remain blocked or need internal reconciliation because the response itself contains `A preciser`, `A definir`, or conflicting fields:

- Partial-month behavior and value-date handling.
- Dossier fee trigger and exceptional setup cases.
- Guarantee deposit collection/use conflict.
- Penalty capitalization and CTX/PAR cap behavior.
- Capitalized unpaid amounts.
- Accounting-balance status vocabulary and pending transaction semantics.
- Available-balance pending withdrawal treatment.
- Daily/cumulative movement date semantics.
- Collection-performance formula wording.

## Formula Gate Mapping

| Formula policy gate | Stakeholder response status | Backlog impact |
|---|---|---|
| `xaf_rounding` | Mostly unblocked | Can design 2-decimal XAF storage/display and no-rounding behavior. Still needs implementation spec and approval metadata. |
| `loan_interest_method` | Unblocked | Can design flat-interest engine on initial principal. Rate period should be product configuration, not a stakeholder blocker. |
| `loan_installment_amount` | Mostly unblocked | Can design equal installments using flat interest and upfront tax behavior. Needs precise schedule component model in implementation. |
| `repayment_allocation_order` | Mostly unblocked | Can design capital-first allocation, oldest installment first, same-day same order, overpayment to customer account. Remaining component order after capital should be an internal policy/config decision. |
| `fees_taxes_insurance` | Mostly unblocked | Can design 3% dossier fee, 19.25% tax on capital + interest, 2% insurance, 10% guarantee deposit. Dossier-fee trigger and guarantee deposit use need reconciliation. |
| `penalties_and_arrears` | Partially unblocked | Penalty formula and arrears formula are mostly defined. Capitalization, CTX/PAR cap behavior, and capitalized unpaid amounts remain reconciliation work. |
| `account_balances` | Partially unblocked | Ledger-derived accounting balance direction exists; available-balance minimums and loan restrictions exist. Pending transaction status and account taxonomy remain unresolved. |
| `cash_till_reconciliation` | Mostly unblocked | Denomination counts, theoretical balance, and zero-tolerance difference are defined. Pending transaction handling at close remains reconciliation work. |
| `portfolio_reporting_metrics` | Mostly unblocked | Portfolio outstanding, PAR30, and collection inclusions are defined. Collection-performance formula wording needs internal reconciliation. |

## Backlog Items Now Unblocked For Design

These items were blocked because formulas were unknown. They can now move from "unknown business rule" to implementation design, specs, and formula-driver work.

### Module 3: Accounting Architecture

Backlog references:

- `backlogs/module-3-accounting-architecture-backlog.md`: "All balance, movement, availability, posting, rounding, cash, reporting, fee, interest, penalty, and repayment-allocation formulas remain unresolved."
- `backlogs/module-3-accounting-architecture-backlog.md`: `Not In Module 3 Safe Slice` entries for accounting balance, available balance, movement totals, reports, and formulas.
- `backlogs/module-3-accounting-architecture-backlog.md`: open question for available-balance formula and whether holds reduce availability immediately.

Now unblocked for design:

- Rounding/precision standard: 2 decimal customer-facing XAF, no rounding, no final installment adjustment.
- Accounting balance baseline: stakeholder direction says use entered/validated ledger entries.
- Available balance baseline: accounting balance less account minimum, with loan restrictions reducing availability.
- Fee/tax/insurance/guarantee deposit formula specs for future postings.
- Repayment allocation direction for future loan/account posting.
- Movement reports can be split by accounting/posting date versus operational/transaction date as internal report semantics.

Still needs reconciliation:

- Whether pending withdrawals reduce available balance before posting.
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
- Pending transactions should be shown on the close state.

Still needs reconciliation:

- Exact pending transaction lifecycle at till close: block close, carry forward, or supervisor approval.
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
- Reporting jobs can be designed around portfolio outstanding, PAR30, and collection performance interpretations.
- Movement reports can use batch day-close as the reporting boundary.

Still needs reconciliation:

- Penalty capitalization and CTX/PAR transition behavior.
- Pending transaction handling in day close.
- Collection performance formula wording: performance ratio versus uncollected gap.
- Batch execution remains blocked at runtime until formula gates and drivers exist.

### Foundation / Credit Structure

Backlog references:

- `backlogs/foundation-migration-backlog.md`: loan products store formula policy keys without hardcoding formulas.
- `backlogs/foundation-migration-backlog.md`: loan schedules are generated snapshots, but no schedule generator exists until formulas are approved.

Now unblocked for design:

- Loan product formula policies can be configured for flat interest, equal installment behavior, fee/tax/insurance/deposit formulas, capital-first allocation, penalties, grace period, early repayment, and rescheduling.
- Loan schedule generator design can start using flat interest on initial principal and equal installments.
- Schedule lines can include principal, interest, tax, insurance/fees where applicable, penalty, and remaining principal projections.
- Early repayment can default to collecting future interest, no early fee, no insurance refund, and guarantee release after full settlement.
- Rescheduling can keep the same loan identity while preserving schedule/version history and capitalizing interest + penalties through approval workflow.

Still needs reconciliation:

- Capitalized unpaid amounts: trigger, formula, penalty base, and accounting treatment.
- Partial-month behavior for exceptional periods.
- Value-date offset behavior.
- Exact accounting postings for setup fees, VAT, insurance, guarantee deposit, disbursement, repayment, penalty, early repayment, and rescheduling.

## Backlog Items Not Unblocked By Formula Responses

These open items were not primarily blocked by formula answers:

- Module 2 KYC vocabulary, encryption, maker-checker, identity-document uniqueness, guarantor/proxy modeling, and public download decisions.
- Module 3 global/shared ledger account support and final chart-of-accounts taxonomy.
- Module 3 sector-reference integration with client metadata.
- Module 5 final till type vocabulary, central till semantics, one-session-per-teller/till policy, cash limit override semantics, and manual journal workflow.
- Public/temporary KYC file download endpoints.
- New stakeholder-added modules such as HR, bancassurance, FX, Islamic finance, SMS banking, dashboards, and alerting.

## Recommended Next Backlog Actions

1. Create a formula implementation backlog for the nine `config/formulas.php` policy gates.
2. For each gate, write an implementation spec from `docs/domain/stakeholder-formula-responses.md`.
3. Split each gate into driver implementation, config approval metadata, tests, and API/service integration.
4. Keep unresolved reconciliation items in `docs/domain/stakeholder-formula-items-to-explain.md` or an internal architecture decision before enabling runtime execution.
5. Do not update existing backlog checkboxes just because stakeholder answers exist. Mark them complete only when code, tests, and formula-gate approval exist.

## Candidate Implementation Order

1. `xaf_rounding`: smallest cross-cutting rule and prerequisite for all financial amounts.
2. `fees_taxes_insurance`: clear formulas for fee, tax, insurance, and guarantee deposit, with reconciliation notes.
3. `loan_interest_method` and `loan_installment_amount`: flat interest plus equal installment schedule generation.
4. `repayment_allocation_order`: capital-first repayment allocation.
5. `penalties_and_arrears`: monthly penalty and arrears first; defer capitalized unpaid amounts until reconciled.
6. `account_balances`: posted ledger accounting balance first; available balance after pending/hold/account-type policy is settled.
7. `cash_till_reconciliation`: denomination counts, theoretical balance, and zero-difference close.
8. `portfolio_reporting_metrics`: portfolio outstanding, PAR30, collection performance.

