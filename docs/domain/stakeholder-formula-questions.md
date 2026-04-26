# Stakeholder Formula Clarification

This document lists the mathematical and accounting formulas that must be confirmed before implementing the Habis Finance API financial modules.

The current stakeholder resources identify many fields such as interest rate, penalty formula, fees, insurance, guarantee deposit, amortization schedules, arrears, and cash reconciliation. They do not yet define the exact formulas. The backend cannot safely implement loans, repayments, accounting balances, or cash operations until these rules are confirmed.

## Why This Is Required

Microfinance formulas affect:

- Customer repayment amounts.
- Interest income.
- Penalties and arrears.
- Regulatory/accounting reports.
- Cash reconciliation.
- Customer account balances.
- Loan closure and write-off.

Small formula differences can create accounting mismatches and customer disputes. The goal is to make every calculation explicit, testable, and auditable.

## Required Stakeholder Decisions

### 1. Currency And Rounding

Questions:

- What is the operational currency: XAF/CFA, TZS, or multiple currencies?
- How many decimal places should money use?
- Should amounts be rounded to the nearest franc/unit, nearest cent, or another precision?
- Which rounding mode should be used: half-up, half-even, always up, always down?
- Should rounding occur per schedule line, per transaction, per day, or only at final total?
- Should the final installment absorb rounding differences?

Business impact:

- Rounding rules affect every repayment, fee, tax, and ledger posting.

Required answer:

- Currency:
- Decimal precision:
- Rounding mode:
- Rounding timing:
- Final-installment adjustment rule:

## Loan Product Formulas

### 2. Interest Method

Questions:

- Which interest method is used for each loan product?
- Is interest flat on original principal?
- Is interest on declining balance?
- Is it annuity/equal installment?
- Is it equal principal plus declining interest?
- Is it bullet/interest-only until maturity?
- Is interest accrued daily, monthly, or per installment?

Required answer per product:

- Product code/name:
- Interest method:
- Interest frequency:
- Interest base:
- Formula:

### 3. Day-Count Convention

Questions:

- For daily interest, what day-count convention is used?
- Actual days / 365?
- Actual days / 360?
- 30/360?
- Calendar month based?

Business impact:

- Same nominal interest rate produces different interest amounts depending on this rule.

Required answer:

- Day-count convention:
- Treatment of leap years:
- Treatment of partial months:

### 4. Installment Amount

Questions:

- How is `installment_amount` calculated?
- Is it equal for every period?
- Does it vary by interest due?
- Does it include tax, insurance, fees, or penalties?
- Is the first or last installment allowed to differ?

Required answer:

- Formula:
- Included components:
- Rounding rule:
- Adjustment rule:

### 5. Principal / Interest Split

Questions:

- How is each installment split between principal and interest?
- Does interest get calculated before principal allocation?
- Does repayment allocation affect schedule status immediately?

Required answer:

- Principal formula:
- Interest formula:
- Allocation timing:

## Fees, Taxes, Insurance, And Deposits

### 6. Dossier / Application Fees

Questions:

- Are dossier fees fixed amount, percentage of loan amount, or product-configured?
- Are fees charged at application, approval, or disbursement?
- Are fees paid in cash/account debit, deducted from disbursement, or accrued separately?
- Are fees refundable if the loan is rejected/cancelled?

Required answer:

- Formula:
- Trigger event:
- Payment method:
- Refund rule:
- Ledger treatment:

### 7. VAT / Tax

Questions:

- What is the tax base?
- Interest only?
- Fees only?
- Insurance only?
- Interest + fees?
- Is tax calculated per installment or upfront?
- Is tax rounded separately?

Required answer:

- Tax rate:
- Tax base:
- Calculation timing:
- Rounding rule:
- Ledger treatment:

### 8. Insurance

Questions:

- Is insurance fixed or percentage-based?
- What is the base amount: granted amount, outstanding principal, installment amount, or another base?
- Is insurance paid upfront or per installment?
- Is it refundable on early closure?

Required answer:

- Formula:
- Base:
- Timing:
- Refund rule:
- Ledger treatment:

### 9. Guarantee Deposit

Questions:

- Is guarantee deposit fixed or percentage-based?
- What is the base: granted amount, outstanding principal, or another value?
- Is it collected before disbursement, deducted from disbursement, or held from customer account?
- Is it released automatically at loan closure?
- Can it be used to settle unpaid amounts?

Required answer:

- Formula:
- Base:
- Collection timing:
- Release rule:
- Offset/use rule:
- Ledger treatment:

## Penalties And Arrears

### 10. Penalty Formula

Questions:

- When does penalty start: immediately after due date or after grace days?
- What is the penalty base: overdue principal, overdue interest, total overdue installment, outstanding principal, or fixed amount?
- Is penalty fixed amount, percentage, daily percentage, monthly percentage, or product-specific formula?
- Does penalty compound?
- Is penalty capped?
- Is penalty recalculated daily or only during payment?

Required answer:

- Trigger:
- Grace days:
- Base:
- Formula:
- Frequency:
- Compounding rule:
- Cap:
- Rounding rule:
- Ledger treatment:

### 11. Arrears / Unpaid Amount

Questions:

- What makes a loan installment late?
- Is a partial payment considered late, partially paid, or unpaid?
- How is `total_unpaid_amount` calculated?
- How is `due_amount` / `exigible` calculated?

Required answer:

- Late rule:
- Partial payment rule:
- Due amount formula:
- Total unpaid formula:

### 12. Repayment Allocation Order

Questions:

- When a customer pays less than total due, what is paid first?
- Penalties first?
- Taxes first?
- Fees first?
- Interest first?
- Principal first?
- Oldest installment first?

Example options:

- penalty -> tax -> interest -> principal
- tax -> penalty -> interest -> principal
- interest -> principal -> penalty
- oldest installment fully before next installment

Required answer:

- Allocation order:
- Same-day payment ordering:
- Overpayment handling:

## Grace Period, Capitalization, And Rescheduling

### 13. Grace Period

Questions:

- During grace period, does interest accrue?
- Is interest paid during grace period?
- Is interest capitalized into principal?
- Are principal payments deferred?
- Are penalties disabled during grace period?

Required answer:

- Principal behavior:
- Interest behavior:
- Penalty behavior:
- Schedule impact:

### 14. Capitalized Interest

Questions:

- When is interest capitalized?
- What base does future interest use after capitalization?
- Is capitalized interest treated as principal for accounting and penalties?

Required answer:

- Capitalization trigger:
- Formula:
- Future interest base:
- Ledger treatment:

### 15. Early Repayment

Questions:

- Can a customer repay early?
- Is future interest waived, reduced, or still due?
- Are early repayment fees charged?
- Is insurance refunded?
- Is guarantee deposit released immediately?

Required answer:

- Early repayment allowed:
- Future interest rule:
- Fee rule:
- Insurance refund rule:
- Guarantee release rule:
- Ledger treatment:

### 16. Rescheduling / Refinancing

Questions:

- Can overdue loans be rescheduled?
- Does rescheduling create a new loan or modify the existing loan?
- Are unpaid interest and penalties capitalized?
- Is approval required?

Required answer:

- Rescheduling allowed:
- New loan vs same loan:
- Capitalization rule:
- Approval workflow:
- Ledger treatment:

## Account And Balance Formulas

### 17. Accounting Balance

Questions:

- Is accounting balance strictly derived from posted ledger entries?
- Are pending transactions included?

Required answer:

- Accounting balance formula:
- Included statuses:

### 18. Available Balance

Questions:

- Which holds reduce available balance?
- Guarantee deposits?
- Pending withdrawals?
- Legal freezes?
- Minimum balance requirements?
- Loan-related restrictions?

Required answer:

- Available balance formula:
- Hold types:
- Minimum balance rule:

### 19. Daily And Cumulative Movements

Questions:

- Are daily movements based on transaction date or posting date?
- Do reversed transactions reduce movement totals or appear separately?
- When does the day close?

Required answer:

- Daily movement formula:
- Cumulative movement formula:
- Reversal handling:
- Day boundary:

## Teller And Cash Formulas

### 20. Cash Denomination Count

Formula proposal:

```text
line_total = denomination_value * quantity
actual_cash_total = sum(line_total)
```

Questions:

- Are damaged notes/coins tracked separately?
- Are foreign currency denominations supported?

Required answer:

- Denomination rule:
- Damaged cash rule:
- Foreign currency rule:

### 21. Till Theoretical Balance

Questions:

- Is theoretical balance derived only from posted teller ledger entries?
- Are pending transactions included?
- Are inter-till transfers included when sent, received, or both confirmed?

Required answer:

- Opening balance formula:
- Theoretical balance formula:
- Pending transaction treatment:
- Inter-till transfer timing:

### 22. Till Reconciliation Difference

Formula proposal:

```text
difference = actual_cash_total - theoretical_balance
```

Questions:

- What tolerance is allowed?
- Who approves differences?
- How are shortages and overages posted?

Required answer:

- Tolerance:
- Approval role:
- Shortage ledger treatment:
- Overage ledger treatment:

## Reporting Metrics

### 23. Portfolio Outstanding

Questions:

- Is portfolio outstanding principal only or principal + interest + penalties?
- Are written-off loans included?
- Are rescheduled loans included under original or new portfolio?

Required answer:

- Formula:
- Included statuses:
- Grouping rules:

### 24. Portfolio At Risk / Delinquency

Questions:

- Does the institution use PAR30, PAR60, PAR90?
- Is PAR based on outstanding principal of loans with any installment overdue by N days?
- Are restructured loans reported separately?

Required answer:

- PAR buckets:
- Formula:
- Included/excluded loans:
- Restructured loan handling:

### 25. Collection Performance

Questions:

- How is expected collection calculated?
- How is actual collection calculated?
- Are penalties and fees included in collection performance?

Required answer:

- Expected collection formula:
- Actual collection formula:
- Inclusion/exclusion rules:

## Approval Needed Before Implementation

Please confirm these items before backend implementation starts:

- Currency and rounding.
- Interest method by loan product.
- Installment formula.
- Fee, tax, insurance, and guarantee deposit formulas.
- Penalty and arrears formulas.
- Repayment allocation order.
- Grace period and capitalization behavior.
- Early repayment and rescheduling rules.
- Balance formulas.
- Teller/cash reconciliation formulas.
- Reporting metric formulas.

## Suggested Sign-Off Table

| Area | Stakeholder Owner | Status | Notes |
|---|---|---|---|
| Currency and rounding |  | Pending |  |
| Loan interest |  | Pending |  |
| Installments |  | Pending |  |
| Fees and VAT |  | Pending |  |
| Insurance |  | Pending |  |
| Guarantee deposit |  | Pending |  |
| Penalties |  | Pending |  |
| Repayment allocation |  | Pending |  |
| Early repayment |  | Pending |  |
| Rescheduling/refinancing |  | Pending |  |
| Account balances |  | Pending |  |
| Teller reconciliation |  | Pending |  |
| Reporting metrics |  | Pending |  |

