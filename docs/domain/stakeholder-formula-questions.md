# Stakeholder Formula Clarification Guide

This guide is for stakeholders, product owners, accountants, branch managers, credit officers, and operations staff. It explains every calculation decision the backend needs before implementing loans, accounts, teller operations, and reports.

The base operating currency is `XAF`. The open questions are not about which currency to use. They are about how amounts are rounded, how interest is calculated, how repayments are allocated, and how accounting/cash values are derived.

Stakeholder responses have been extracted into `docs/domain/stakeholder-formula-responses.md`. Use that response register for implementation readiness and follow-up questions; keep this document as the original question guide.

## How To Use This Document

For each section:

- Read the explanation.
- Review the illustration.
- Choose the business rule.
- Fill the decision fields.
- Mark the section as approved only when Finance/Operations agree.

The examples are illustrative only. They are not proposed final formulas unless explicitly approved.

## 1. XAF Precision And Rounding

### What This Means

The system will calculate interest, fees, taxes, penalties, balances, and schedules. Some formulas can produce fractional values, for example `333.33 XAF`. Stakeholders must decide how the system rounds these values.

### Illustration

Loan interest calculation produces:

```text
raw interest = 10,000.67 XAF
```

Possible outcomes:

```text
round to nearest whole XAF = 10,001 XAF
round down = 10,000 XAF
round up = 10,001 XAF
keep internal decimal until final total = 10,000.67 XAF internally
```

### Decision Needed

- Are customer-facing amounts always whole XAF?
- Can internal calculations keep decimals before final rounding?
- Which rounding mode should be used?
- Should rounding happen on each installment line, each transaction, or only at final total?
- Should the final installment absorb rounding differences?

### Decision Fields

- Customer-facing precision:
- Internal calculation precision:
- Rounding mode:
- Rounding timing:
- Final installment adjustment:
- Approved by:

## 2. Loan Interest Method

### What This Means

The interest method determines how much the customer pays for borrowing. The same loan amount and interest rate can produce very different repayment totals depending on the method.

### Illustration

Example loan:

```text
principal = 100,000 XAF
interest rate = 10%
duration = 10 months
```

Flat interest example:

```text
interest = 100,000 * 10% = 10,000 XAF
total repayment = 110,000 XAF
```

Declining balance example:

```text
interest is calculated on remaining principal each period
as principal reduces, interest reduces
total interest may be lower than flat interest
```

Equal installment / annuity example:

```text
customer pays roughly the same total installment each period
principal and interest portions change over time
```

### Decision Needed

For each loan product, choose the interest method:

- Flat interest on original principal.
- Declining balance interest.
- Equal installment / annuity.
- Equal principal plus declining interest.
- Interest-only / bullet.
- Another institution-specific method.

### Decision Fields Per Product

- Product code/name:
- Interest method:
- Interest rate period: daily / monthly / annual / per cycle
- Interest base:
- Formula:
- Approved by:

## 3. Day-Count Convention

### What This Means

If interest is calculated by days, the system must know how to convert days into interest. A month can be treated as 30 days, actual calendar days, or another convention.

### Illustration

Example:

```text
principal = 100,000 XAF
annual rate = 12%
period = 15 days
```

Actual/365:

```text
interest = 100,000 * 12% * 15 / 365
```

Actual/360:

```text
interest = 100,000 * 12% * 15 / 360
```

30/360:

```text
each month is treated as 30 days
each year is treated as 360 days
```

### Decision Needed

- Should daily interest use actual days / 365?
- Actual days / 360?
- 30/360?
- Calendar-month based?
- How are leap years handled?
- How are partial months handled?

### Decision Fields

- Day-count convention:
- Leap year rule:
- Partial month rule:
- Approved by:

## 4. Installment Amount

### What This Means

The installment amount is what the customer is expected to pay on each due date. It may include principal, interest, tax, insurance, fees, or penalties depending on policy.

### Illustration

Example schedule line:

```text
principal due = 10,000 XAF
interest due = 1,500 XAF
tax = 0 XAF
insurance = 0 XAF
installment amount = 11,500 XAF
```

If fees are included:

```text
monthly fee = 500 XAF
installment amount = 12,000 XAF
```

### Decision Needed

- Is the installment amount equal each period?
- Does it vary based on interest/principal?
- Which components are included?
- Can the first or last installment differ?
- How are rounding differences handled?

### Decision Fields

- Installment formula:
- Included components:
- Equal or variable installments:
- First/last installment rule:
- Rounding rule:
- Approved by:

## 5. Principal And Interest Split

### What This Means

Each repayment must be split into principal and interest so the system can reduce the loan balance correctly and report income correctly.

### Illustration

Customer pays:

```text
payment = 12,000 XAF
interest due = 2,000 XAF
principal due = 10,000 XAF
```

If paid in full:

```text
2,000 XAF goes to interest
10,000 XAF reduces principal
```

If customer pays only 7,000 XAF, the allocation rule decides what gets paid first.

### Decision Needed

- Is interest calculated before principal allocation?
- Does principal reduce immediately after payment?
- What happens when payment is partial?

### Decision Fields

- Interest calculation timing:
- Principal reduction rule:
- Partial payment behavior:
- Approved by:

## 6. Dossier / Application Fees

### What This Means

Dossier fees are charges for processing a loan. The system must know how they are calculated and when they are collected.

### Illustration

Fixed fee:

```text
dossier fee = 5,000 XAF
```

Percentage fee:

```text
loan amount = 200,000 XAF
fee rate = 2%
dossier fee = 4,000 XAF
```

Deducted from disbursement:

```text
approved loan = 200,000 XAF
fee = 5,000 XAF
cash received by customer = 195,000 XAF
```

### Decision Needed

- Fixed or percentage?
- Charged at application, approval, or disbursement?
- Paid separately or deducted from disbursement?
- Refundable if rejected or cancelled?

### Decision Fields

- Formula:
- Trigger event:
- Payment method:
- Refund rule:
- Ledger treatment:
- Approved by:

## 7. VAT / Tax

### What This Means

Taxes may apply to interest, fees, insurance, or other charges. The system must know the tax base and timing.

### Illustration

Tax on fee:

```text
fee = 5,000 XAF
tax rate = 19.25%
tax = 962.5 XAF before rounding
```

Tax on interest:

```text
interest = 2,000 XAF
tax rate = 19.25%
tax = 385 XAF
```

### Decision Needed

- What rate applies?
- What is taxed: interest, fees, insurance, penalties, or combinations?
- Is tax calculated upfront or per installment?
- Is tax rounded separately?

### Decision Fields

- Tax rate:
- Tax base:
- Calculation timing:
- Rounding rule:
- Ledger treatment:
- Approved by:

## 8. Insurance

### What This Means

Loan insurance may be fixed or based on the loan amount. The system must know when it is charged and whether it can be refunded.

### Illustration

Percentage insurance:

```text
loan amount = 200,000 XAF
insurance rate = 1%
insurance = 2,000 XAF
```

Monthly insurance example:

```text
insurance = 500 XAF per installment
```

### Decision Needed

- Fixed or percentage-based?
- Based on granted amount, outstanding principal, installment, or another base?
- Paid upfront or per installment?
- Refundable on early closure?

### Decision Fields

- Formula:
- Base:
- Timing:
- Refund rule:
- Ledger treatment:
- Approved by:

## 9. Guarantee Deposit

### What This Means

A guarantee deposit is money held as security for the loan. It may be collected, held, released, or used to settle unpaid balances.

### Illustration

Percentage deposit:

```text
loan amount = 300,000 XAF
guarantee deposit = 10%
deposit required = 30,000 XAF
```

If held from customer account:

```text
account balance = 80,000 XAF
hold = 30,000 XAF
available balance = 50,000 XAF
```

### Decision Needed

- Fixed or percentage-based?
- What is the base amount?
- Is it paid in cash, deducted, or held from account balance?
- Released at closure?
- Can it settle unpaid loans?

### Decision Fields

- Formula:
- Base:
- Collection method:
- Release rule:
- Offset/use rule:
- Ledger treatment:
- Approved by:

## 10. Penalty Formula

### What This Means

Penalties apply when repayment is late. The system must know when penalties start, what amount they are based on, and whether they accumulate.

### Illustration

Installment due:

```text
due date = April 10
installment = 20,000 XAF
grace period = 3 days
penalty starts = April 14
```

Daily percentage penalty:

```text
overdue amount = 20,000 XAF
penalty rate = 1% per day
late days = 5
penalty = 20,000 * 1% * 5 = 1,000 XAF
```

Fixed penalty:

```text
penalty = 2,000 XAF once overdue
```

### Decision Needed

- When does penalty start?
- What is the penalty base?
- Fixed or percentage?
- Daily, monthly, or one-time?
- Does it compound?
- Is there a cap?

### Decision Fields

- Trigger:
- Grace days:
- Base:
- Formula:
- Frequency:
- Compounding rule:
- Cap:
- Rounding rule:
- Ledger treatment:
- Approved by:

## 11. Arrears / Unpaid Amount

### What This Means

Arrears represent amounts not paid by their due date. The system must decide how partial payments affect arrears.

### Illustration

Due installment:

```text
installment due = 20,000 XAF
customer pays = 12,000 XAF
remaining unpaid = 8,000 XAF
```

If partial payment still counts as late:

```text
installment status = partially_paid_late
arrears = 8,000 XAF
```

### Decision Needed

- What makes an installment late?
- How are partial payments classified?
- How is `due_amount` calculated?
- How is `total_unpaid_amount` calculated?

### Decision Fields

- Late rule:
- Partial payment rule:
- Due amount formula:
- Total unpaid formula:
- Approved by:

## 12. Repayment Allocation Order

### What This Means

When a customer pays less than the total amount due, the system must decide what is paid first.

### Illustration

Customer owes:

```text
penalty = 1,000 XAF
tax = 500 XAF
interest = 4,000 XAF
principal = 15,000 XAF
total due = 20,500 XAF
payment = 10,000 XAF
```

Option A:

```text
penalty -> tax -> interest -> principal
principal receives 4,500 XAF
```

Option B:

```text
interest -> principal -> penalty
principal receives 6,000 XAF
penalty remains unpaid
```

### Decision Needed

- Which component is paid first?
- Are oldest installments paid before newer installments?
- How are same-day multiple payments ordered?
- What happens to overpayments?

### Decision Fields

- Allocation order:
- Installment ordering:
- Same-day payment ordering:
- Overpayment handling:
- Approved by:

## 13. Grace Period

### What This Means

A grace period may delay principal repayment, penalty start, or both. Interest may still accrue.

### Illustration

Loan has:

```text
first principal due after 30 days
grace period = 10 days
```

Possible rules:

```text
principal deferred, interest still due
principal and interest both deferred
interest accrues and is capitalized
penalty disabled during grace period
```

### Decision Needed

- Is principal deferred?
- Does interest accrue?
- Is interest paid during grace period?
- Is interest capitalized?
- Are penalties disabled?

### Decision Fields

- Principal behavior:
- Interest behavior:
- Penalty behavior:
- Schedule impact:
- Approved by:

## 14. Capitalized Interest

### What This Means

Capitalized interest means unpaid interest is added to the loan principal. Future interest may then be calculated on the higher balance.

### Illustration

```text
principal = 100,000 XAF
unpaid interest = 5,000 XAF
after capitalization, new principal = 105,000 XAF
```

### Decision Needed

- When is interest capitalized?
- Does future interest use the new principal?
- Is capitalized interest treated like principal for penalties and reporting?

### Decision Fields

- Capitalization trigger:
- Formula:
- Future interest base:
- Penalty treatment:
- Ledger treatment:
- Approved by:

## 15. Early Repayment

### What This Means

Early repayment occurs when a customer pays before the scheduled loan end date. The system must know whether future interest is waived or still collected.

### Illustration

Customer has:

```text
remaining principal = 80,000 XAF
future scheduled interest = 12,000 XAF
customer wants to close today
```

Possible rules:

```text
pay only 80,000 XAF principal plus current accrued interest
pay 80,000 XAF plus all future interest
pay 80,000 XAF plus early closure fee
```

### Decision Needed

- Is early repayment allowed?
- Is future interest waived?
- Is an early repayment fee charged?
- Is insurance refunded?
- Is guarantee deposit released immediately?

### Decision Fields

- Early repayment allowed:
- Future interest rule:
- Fee rule:
- Insurance refund rule:
- Guarantee release rule:
- Ledger treatment:
- Approved by:

## 16. Rescheduling / Refinancing

### What This Means

Rescheduling changes the repayment plan. Refinancing may close an old loan and create a new one.

### Illustration

Current overdue loan:

```text
principal outstanding = 100,000 XAF
unpaid interest = 10,000 XAF
penalties = 5,000 XAF
```

Possible rule:

```text
new rescheduled principal = 100,000 XAF
interest and penalties stay separate
```

Alternative:

```text
new principal = 115,000 XAF
interest and penalties are capitalized
```

### Decision Needed

- Is rescheduling allowed?
- Does it modify the same loan or create a new loan?
- Are interest/penalties capitalized?
- Is approval required?

### Decision Fields

- Rescheduling allowed:
- New loan vs same loan:
- Capitalization rule:
- Approval workflow:
- Ledger treatment:
- Approved by:

## 17. Accounting Balance

### What This Means

Accounting balance should be the ledger-derived balance of an account. Stakeholders must confirm whether pending transactions are excluded.

### Illustration

Posted entries:

```text
credits = 150,000 XAF
debits = 40,000 XAF
accounting balance = 110,000 XAF
```

Pending withdrawal:

```text
pending withdrawal = 10,000 XAF
accounting balance remains 110,000 XAF if pending is excluded
```

### Decision Needed

- Is accounting balance strictly from posted ledger entries?
- Are pending transactions excluded?
- How are reversals shown?

### Decision Fields

- Accounting balance formula:
- Included statuses:
- Reversal handling:
- Approved by:

## 18. Available Balance

### What This Means

Available balance is what the customer can use. It is usually accounting balance minus holds, unavailable funds, and pending restrictions.

### Illustration

```text
accounting balance = 100,000 XAF
guarantee deposit hold = 30,000 XAF
pending withdrawal = 10,000 XAF
available balance = 60,000 XAF
```

### Decision Needed

- Which holds reduce available balance?
- Is there a minimum balance?
- Do pending withdrawals reduce availability?
- Do loan restrictions reduce availability?

### Decision Fields

- Available balance formula:
- Hold types:
- Minimum balance rule:
- Pending transaction rule:
- Approved by:

## 19. Daily And Cumulative Movements

### What This Means

Reports show daily and cumulative debit/credit movements. Stakeholders must decide whether reports use transaction date or posting date.

### Illustration

Transaction initiated:

```text
transaction date = April 10
posted date = April 11
amount = 20,000 XAF
```

If reporting by transaction date:

```text
April 10 movement includes 20,000 XAF
```

If reporting by posting date:

```text
April 11 movement includes 20,000 XAF
```

### Decision Needed

- Use transaction date or posting date?
- How are reversals reported?
- What is the day-close boundary?

### Decision Fields

- Daily movement formula:
- Cumulative movement formula:
- Reversal handling:
- Day boundary:
- Approved by:

## 20. Bank Notes And Coins / Billetage

### What This Means

The stakeholder resources include bank note and coin management through denomination setup and cash counting. This is used when opening/closing tills and reconciling actual cash.

### Illustration

Cash count:

```text
10 notes of 10,000 XAF = 100,000 XAF
5 notes of 5,000 XAF = 25,000 XAF
20 coins/notes of 500 XAF = 10,000 XAF
actual cash total = 135,000 XAF
```

Formula:

```text
line_total = denomination_value * quantity
actual_cash_total = sum(line_total)
```

### Decision Needed

- Which denominations are accepted?
- Are coins tracked?
- Are damaged notes tracked separately?
- Can denominations be deactivated?
- Is denomination counting required at till opening, till closing, or both?

### Decision Fields

- Accepted denominations:
- Coin tracking:
- Damaged cash rule:
- Opening count required:
- Closing count required:
- Approved by:

## 21. Till Theoretical Balance

### What This Means

The theoretical balance is what the system believes should be in the teller’s cash drawer based on posted cash movements.

### Illustration

```text
opening cash = 100,000 XAF
cash deposits = 80,000 XAF
cash withdrawals = 30,000 XAF
theoretical balance = 150,000 XAF
```

If pending transactions are excluded:

```text
pending withdrawal = 10,000 XAF
theoretical balance remains 150,000 XAF until posted
```

### Decision Needed

- Are only posted teller transactions included?
- Are pending transactions included?
- How are inter-till transfers counted?
- When does transferred cash leave one till and enter another?

### Decision Fields

- Opening balance formula:
- Theoretical balance formula:
- Pending transaction treatment:
- Inter-till transfer timing:
- Approved by:

## 22. Till Reconciliation Difference

### What This Means

At closing, the teller counts physical cash. The system compares counted cash to theoretical balance.

### Illustration

```text
theoretical balance = 150,000 XAF
actual counted cash = 148,500 XAF
difference = -1,500 XAF
```

Formula:

```text
difference = actual_cash_total - theoretical_balance
```

### Decision Needed

- What difference tolerance is allowed?
- Who approves shortages/overages?
- How are shortages posted?
- How are overages posted?
- Can a till close with unresolved differences?

### Decision Fields

- Tolerance:
- Approval role:
- Shortage ledger treatment:
- Overage ledger treatment:
- Close-with-difference rule:
- Approved by:

## 23. Portfolio Outstanding

### What This Means

Portfolio outstanding is used for management and reporting. It must be clear whether it means principal only or includes interest/penalties.

### Illustration

Loan portfolio:

```text
principal outstanding = 1,000,000 XAF
interest due = 80,000 XAF
penalties due = 20,000 XAF
```

Principal-only portfolio:

```text
portfolio outstanding = 1,000,000 XAF
```

Total exposure portfolio:

```text
portfolio outstanding = 1,100,000 XAF
```

### Decision Needed

- Principal only or principal + interest + penalties?
- Are written-off loans included?
- Are rescheduled loans included under original or new portfolio?

### Decision Fields

- Formula:
- Included statuses:
- Grouping rules:
- Approved by:

## 24. Portfolio At Risk / Delinquency

### What This Means

Portfolio at risk measures loans with overdue installments. Common buckets are PAR30, PAR60, and PAR90.

### Illustration

If a loan has one installment overdue by 35 days:

```text
loan outstanding principal = 200,000 XAF
PAR30 includes 200,000 XAF
```

Depending on policy, the full outstanding principal may be counted, not just the overdue installment.

### Decision Needed

- Which PAR buckets are used?
- Is PAR based on full outstanding principal or overdue amount only?
- Are written-off or rescheduled loans included?

### Decision Fields

- PAR buckets:
- Formula:
- Included/excluded loans:
- Restructured loan handling:
- Approved by:

## 25. Collection Performance

### What This Means

Collection performance compares what should have been collected to what was actually collected.

### Illustration

Expected today:

```text
scheduled principal = 300,000 XAF
scheduled interest = 50,000 XAF
expected collection = 350,000 XAF
```

Actual today:

```text
actual repayments = 280,000 XAF
collection performance = 280,000 / 350,000 = 80%
```

### Decision Needed

- Are penalties included in expected collection?
- Are fees included?
- Are partial payments counted immediately?
- Are cash and account debits both included?

### Decision Fields

- Expected collection formula:
- Actual collection formula:
- Inclusion/exclusion rules:
- Approved by:

## Approval Needed Before Implementation

Please confirm these items before backend implementation starts:

- XAF precision and rounding.
- Interest method by loan product.
- Installment formula.
- Fee, tax, insurance, and guarantee deposit formulas.
- Penalty and arrears formulas.
- Repayment allocation order.
- Grace period and capitalization behavior.
- Early repayment and rescheduling rules.
- Balance formulas.
- Bank notes/coins and till reconciliation formulas.
- Reporting metric formulas.

## Suggested Sign-Off Table

| Area | Stakeholder Owner | Status | Notes |
|---|---|---|---|
| XAF precision and rounding |  | Pending |  |
| Loan interest |  | Pending |  |
| Installments |  | Pending |  |
| Fees and VAT |  | Pending |  |
| Insurance |  | Pending |  |
| Guarantee deposit |  | Pending |  |
| Penalties |  | Pending |  |
| Repayment allocation |  | Pending |  |
| Grace period/capitalization |  | Pending |  |
| Early repayment |  | Pending |  |
| Rescheduling/refinancing |  | Pending |  |
| Account balances |  | Pending |  |
| Bank notes and coins |  | Pending |  |
| Teller reconciliation |  | Pending |  |
| Reporting metrics |  | Pending |  |
