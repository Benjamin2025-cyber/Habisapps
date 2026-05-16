# Stakeholder Formula Items To Explain

This document explains the items the stakeholder response marked as `A preciser`, `A definir`, or similar. It is written as a discussion aid for a follow-up working session.

Scope: only formula sections 1-25 from `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`.

## How To Use This Document

For each item:

- Explain the business meaning in plain language.
- Give the simple example.
- Ask them to choose the rule they already use operationally.
- Record the answer in `docs/domain/stakeholder-formula-responses.md`.

The goal is not to reopen all formulas. The goal is to clarify only the items they marked as not yet precise.

## 1. Mois Partiels

Source: section 3, day-count convention.

Stakeholder text:

```text
Regle pour les mois partiels: A preciser - notion de mois partiels a contextualiser
```

### Implementation Decision

This is no longer a blocker for the standard loan schedule.

For the selected microfinance method, interest is flat, calculated at setup on the initial principal, and spread across the agreed duration. Standard scheduled installments are not prorated by actual days just because the first or last calendar period is shorter.

Keep this item only for exception workflows:

- early closure where Direction waives future interest
- rescheduling that creates a new schedule
- value-date or posting-effective-date behavior
- any future product that explicitly uses daily interest

### Meaning

A partial month is a period that is shorter than a full repayment month.

Examples:

- A loan starts on May 10, but the first due date is May 31.
- A customer closes a loan on June 12, before the normal June 30 due date.
- A rescheduled loan creates a short first period or short final period.

The system needs to know whether interest or charges for that shorter period should be calculated as a full month or only for the actual number of days.

### Choices To Explain

Option A: Treat every started month as a full month.

```text
Loan active for 10 days in May = charge a full month
```

Option B: Prorate using the 360-day convention.

```text
Annual rate / 360 * actual days
```

Option C: No partial-month calculation because interest is fixed at setup.

```text
Total interest is calculated at setup and divided by duration.
Short calendar periods do not change interest.
```

### Decision Needed

Choose what happens when the loan period is not a clean full month.

Recommended implementation reading from their response: because they chose flat interest calculated at setup, partial-month handling should usually matter only for exceptional operations such as early closure, rescheduling, or value-date adjustments.

## 2. Exceptional Dossier Fee Cases

Source: section 6, dossier/application fees.

Stakeholder text:

```text
NOTE: Cas exceptionnel a voir (ex. : si lors de la mise en place...) - a preciser ulterieurement.
```

### Meaning

They already decided the normal rule:

```text
Dossier fee = granted capital * 3%
Payment method = cash
Refund if rejected = non-refundable after setup stage
```

The unresolved part is what happens in unusual cases during setup.

Examples:

- The loan is approved, but setup is cancelled before disbursement.
- The fee was paid, but the file is later rejected by a later control step.
- The amount granted changes after the fee was already collected.
- The customer paid the fee, but the loan is not finally put in place.

### Choices To Explain

Option A: Never refund once the credit committee has validated.

Option B: Refund only if the cancellation was caused by HABIBI, not the client.

Option C: Recalculate the fee if the granted amount changes.

Option D: Send exceptional cases to Direction for manual approval.

### Implementation Decision

Use standard microfinance practice:

- The dossier fee is earned at credit committee validation/setup approval.
- It is collected separately at setup, before disbursement.
- It is non-refundable after validation.
- Exceptional cases go to Direction for manual decision.

Do not automate every edge case. If an unusual setup case occurs, record the Direction decision and resulting manual waiver, refund, reversal, or recalculation action.

## 3. Penalty Capitalization Rule

Source: section 10, penalty formula.

Stakeholder text:

```text
Regle de capitalisation: A preciser
```

### Meaning

Capitalization means an unpaid amount is added into another balance so later calculations use the larger balance.

In this response, stakeholders chose:

```text
Penalty = 5,000 + 2% of unpaid amount
Frequency = monthly
Do not penalize unpaid amounts below 1,000 XAF
```

Implementation decision for the normal Q10 penalty: unpaid penalties remain due and collectible, but they are not added into the next penalty base. This is standard practice because penalties are already a sanction; charging a new penalty because an old penalty is unpaid is penalty pyramiding.

The remaining explanation is only for section 14 capitalized unpaid amounts, not for the normal monthly penalty formula.

### Choices To Explain

Option A: No capitalization.

```text
Penalty is calculated only on unpaid installment amount.
Old penalties remain separate.
```

Option B: Capitalize unpaid penalties.

```text
Next month penalty is calculated on unpaid installment + old unpaid penalties.
```

Option C: Capitalize only unpaid interest, not penalties.

Option D: Stop or freeze capitalization when the loan enters CTX after 90 days.

### Explanation To Give

For the normal penalty rule, penalties remain separate and are not themselves penalized again. If a loan has an unpaid installment, the monthly penalty is calculated on the unpaid scheduled due, not on previous penalties. If the client has paid the scheduled principal/interest due but still owes an old penalty, the old penalty remains collectible, but it does not create a new penalty by itself.

Collection priority:

```text
1. Scheduled principal, interest, fees, insurance, and tax
2. Penalties
3. Within penalties: oldest assessed penalty first
```

This means an old unpaid penalty does not consume money needed to keep a current scheduled installment current. Once the borrower is paying penalties, the oldest penalty is cleared before the newest one.

Keep capitalization disabled unless a specific capitalized-unpaid-amount workflow is approved under section 14.

## 4. Capitalized Unpaid Amounts

Source: section 14, capitalized interest.

Stakeholder text:

```text
Declencheur de capitalisation: A preciser
Formule: A preciser
Base des interets futurs: A preciser
Traitement des penalites: A preciser
Traitement comptable: A preciser
```

They also added:

```text
Because capital and interest are fixed, call this "capitalized unpaid amounts".
If an installment from month X remains unpaid in month Y, the unpaid amount is updated and penalties apply to it.
```

### Meaning

They seem to be saying this is not classic capitalized interest.

Classic capitalized interest:

```text
Unpaid interest becomes principal.
Future interest is calculated on the new higher principal.
```

Their likely intended concept:

```text
Unpaid installment remains unpaid.
Next month, the unpaid amount is updated.
Penalties are calculated on that unpaid amount.
```

Implementation decision:

```text
Do not implement classic interest capitalization.
Original principal stays the flat-interest base.
Prior penalties do not generate new penalties.
Normal arrears carry-forward is a calculated view, not a journal entry.
True capitalization is only allowed in a separate rescheduling/refinancing workflow approved by credit committee.
```

The clean way to explain this is to call it arrears carry-forward, not interest capitalization.

### Items To Explain

#### Trigger

There is no accounting update trigger for normal carry-forward. At any calculation date, the system derives arrears from the active schedule and repayment allocations. Penalty eligibility still follows the approved Q10 trigger: after the 5-day grace period on the monthly arrears batch.

#### Carry-Forward Formula

The carry-forward amount is:

```text
open scheduled due = scheduled principal + scheduled interest + scheduled fees + scheduled insurance + scheduled tax - allocated payments
```

```text
Old unpaid = 20,000
Penalty = 5,400
Unpaid remains 20,000
Penalty remains separate
```

The alternative where `New unpaid = old unpaid + penalty` should not be used unless Direction explicitly approves penalty capitalization, because it makes old penalties part of the future penalty base.

#### Future Interest Base

Should future interest use the original principal or the increased unpaid amount?

For flat interest, the implementation answer is:

```text
Future interest still uses original principal.
Unpaid amounts affect arrears and penalties, not the original interest calculation.
```

#### Penalty Treatment

Should penalties be calculated on:

- unpaid capital only
- unpaid capital + unpaid interest
- unpaid capital + unpaid interest + previous penalties
- total unpaid installment

The approved normal penalty rule uses:

```text
unpaid scheduled due excluding prior penalties
```

#### Accounting Treatment

Normal arrears carry-forward does not post a journal entry. The accounting balance changes when payments, penalty assessments, waivers, write-offs, or approved rescheduling/refinancing entries are posted.

### Decision

"Capitalized unpaid amounts" means arrears carry-forward for the normal loan lifecycle. It is a calculated view. It does not change principal, does not change the flat-interest base, does not make prior penalties generate new penalties, and does not post accounting entries by itself.

## 5. Accounting Balance Formula

Source: section 17, accounting balance.

Stakeholder text:

```text
Formule du solde comptable: A definir
Statuts inclus: A definir
Gestion des contrepassations: A definir
```

### Meaning

The accounting balance is the balance derived from accounting entries.

They already said:

```text
Use entered and validated ledger entries.
Reversals are shown only on the internal processing interface.
```

The unclear part is exactly which entries count.

### Choices To Explain

#### Formula

For an account whose normal balance is credit:

```text
Accounting balance = total credits - total debits
```

For an account whose normal balance is debit:

```text
Accounting balance = total debits - total credits
```

#### Included Statuses

Should the balance include:

- posted/validated entries only
- draft entries
- pending entries
- reversed entries
- reversal entries

Recommended implementation reading:

```text
Include posted/validated entries only.
Do not include drafts or pending entries.
Reversals affect balance only through posted reversal entries.
```

#### Reversal Handling

A reversal should not delete the original transaction. It should create an opposite accounting entry.

Example:

```text
Original deposit: +50,000
Reversal: -50,000
Net balance impact: 0
```

### Decision

Accounting balance is strictly based on posted/validated ledger entries. Pending operations do not affect accounting balance. Reversals affect balance only through posted reversal entries; the original entry remains in the audit trail.

## 6. Account Types

Source: section 17 note.

Stakeholder text:

```text
Besoin de preciser les types de comptes:
- Comptes de recuperation
- Comptes d'epargne ordinaires
```

### Meaning

Different account types can have different rules.

Examples:

- Savings account may require a minimum balance.
- Recovery account may be used for automatic loan recovery.
- Current account may allow zero minimum balance.

### Decision

Account type rules are product/configuration rules. Use these defaults unless a product overrides them:

- Ordinary savings minimum balance: 5,000 XAF.
- Current account minimum balance: 0 XAF.
- Recovery account rules must be configured explicitly for automatic loan recovery.
- Whether an account can be debited automatically, used for recovery, or closed while loans are active belongs to account-product configuration and authorization policy.

## 7. Pending Transactions

Source: sections 17, 18, and 21.

Stakeholder text:

```text
Transactions en attente: Bien vouloir preciser ce que signifie "transaction en attente"
Retraits en attente: A preciser
Regle des transactions en attente: A preciser
Traitement des transactions en attente: A preciser sur l'etat de fermeture
```

### Meaning

A pending transaction is an operation entered in the system but not yet fully validated, posted, or completed.

Examples:

- A withdrawal is entered by the teller but not approved.
- A cash transfer between tills is requested but not posted.
- A deposit is captured but not validated.
- A transaction failed and awaits correction.

### Decisions

#### Accounting Balance

Should pending transactions affect accounting balance?

```text
Pending transactions do not affect accounting balance.
Accounting balance uses posted/validated ledger entries only.
```

#### Available Balance

Pending withdrawals reduce available balance when the system records them as active holds or unavailable amounts.

Example:

```text
Accounting balance = 100,000
Pending withdrawal = 20,000
Minimum balance = 5,000
Available balance = 75,000 when pending withdrawal is recorded as a hold/unavailable amount
```

#### Till Closing

Should pending teller transactions block closure?

Possible rule:

```text
Pending transactions do not change theoretical cash.
They must be displayed on the close state.
Till cannot close until pending items are validated, cancelled, or explicitly carried forward.
```

### Decision

Pending statuses are workflow states. They do not affect accounting balance. They affect available balance only through active holds or unavailable amounts. Till closing should display pending operations and block or carry them forward according to the till workflow.

## 8. Savings Minimum Balance

Source: section 18, available balance.

Stakeholder text:

```text
Regle du solde minimum - Epargne: 5,000 XAF (a definir par la Direction)
```

### Meaning

They gave a provisional savings minimum of 5,000 XAF, but said Direction must define the policy.

### Choices To Explain

Option A: All ordinary savings accounts must keep 5,000 XAF.

Option B: Minimum balance depends on account product.

```text
Ordinary savings = 5,000
Special savings = 10,000
Current account = 0
```

Option C: Direction can change the minimum later, and new values apply only to new accounts.

Option D: Direction can change the minimum later, and new values apply to all active accounts.

### Decision

Use 5,000 XAF as the ordinary savings default. Store it as account-product configuration so Direction can define different minimums later without changing formulas.

## 9. Daily And Cumulative Movements

Source: section 19.

Stakeholder text:

```text
Date de transaction vs. date de comptabilisation: A contextualiser
Formule du mouvement journalier: A contextualiser
Formule du mouvement cumulatif: A contextualiser
```

### Meaning

Movement reports show how much money moved during a period.

The question is which date controls the report.

Example:

```text
Withdrawal requested on May 10.
Withdrawal posted on May 11.
```

If using transaction date:

```text
May 10 report includes the withdrawal.
```

If using posting date:

```text
May 11 report includes the withdrawal.
```

### Choices To Explain

Accounting reports usually use posting date because the ledger changes when posting happens.

Operational reports may use transaction date because staff want to see what happened at the counter that day.

### Decision Needed

Define which report uses which date:

- accounting movement report
- teller activity report
- customer statement
- management dashboard
- regulatory report

Implementation decision: use posting/business date for accounting reports and transaction date for operational reports when available, with batch day-close as the boundary.

## 10. Pending Transactions On Till Close

Source: section 21, till theoretical balance.

Stakeholder text:

```text
Traitement des transactions en attente: A preciser sur l'etat de fermeture
```

### Meaning

At till closing, the teller needs to know if there are operations that were started but not fully posted.

Example close state:

```text
Theoretical cash = 150,000
Counted cash = 150,000
Pending withdrawal = 10,000
```

The counted cash can match the posted theoretical cash, but the pending withdrawal still needs attention.

### Choices To Explain

Option A: Block closing while pending transactions exist.

Option B: Allow closing, but list pending transactions on the close report.

Option C: Allow closing only with supervisor approval.

### Decision

Pending teller transactions are displayed on the close state and are not included in theoretical balance until they are posted.

Standard close handling: block close while pending teller transactions exist, unless a supervisor workflow explicitly carries them forward. That keeps the teller cash position explainable: posted cash must reconcile to counted cash, while unfinished operations remain visible as operational exceptions.

## 11. Portfolio Outstanding Formula By Portfolio

Source: section 23, portfolio outstanding.

Stakeholder text:

```text
Formule: Somme des dettes et remboursements impayes (a preciser selon le portefeuille)
```

### Meaning

They said portfolio outstanding includes:

```text
Capital + interest + penalties
```

They also said:

```text
Written-off loans are not included.
Rescheduled loans remain in the original portfolio.
```

The grouping is an implementation/reporting dimension, not a blocker for the portfolio outstanding formula.

### Examples

Healthy portfolio:

```text
Loans without overdue installments.
Outstanding = remaining capital + due interest + due penalties, if any.
```

Unpaid/arrears portfolio:

```text
Loans with overdue installments.
Outstanding = overdue capital + overdue interest + overdue penalties.
```

Written-off/loss portfolio:

```text
Stakeholder response says written-off loans are not included in portfolio outstanding.
They may still appear in a separate loss/write-off report.
```

### Decision

```text
Portfolio outstanding = capital + interest + penalties for active non-written-off loans.
Written-off loans are excluded from outstanding exposure and reported separately.
Rescheduled loans keep their original portfolio identity and are also tagged in a restructured/rescheduled reporting dimension.
```

## 12. PAR30 Versus Delinquent Amount

Source: section 24, portfolio at risk / delinquency.

Stakeholder text says the PAR30 base is overdue amount, not the full outstanding principal.

### Meaning

This needs a finance correction. In microfinance, PAR30 normally measures the outstanding balance of loans that have at least one installment more than 30 days late. It is not just the late installment amount.

Example:

```text
Loan outstanding balance = 1,000,000
Overdue installment amount = 80,000
Oldest unpaid installment = 35 days late
```

Standard PAR30 exposure is:

```text
1,000,000
```

The overdue/delinquent amount is:

```text
80,000
```

Both metrics are useful, but they should not be given the same name.

### Decision

Implement both:

```text
Standard PAR30 = outstanding balance of loans with at least one installment > 30 days past due / gross outstanding loan portfolio.
Delinquent amount = overdue scheduled amount only.
```

Written-off loans are excluded from the active PAR denominator and reported separately.

Formally rescheduled loans that are current under the approved new schedule can be excluded from the main PAR30 report, but they must remain visible in a restructured watchlist. A rescheduled loan that becomes delinquent again must not disappear from risk reporting.

## 13. Collection Performance Versus Collection Gap

Source: section 25, collection performance.

Stakeholder text says:

```text
Expected collection = scheduled capital + scheduled interest.
Penalties are included in expected collection.
Fees are excluded.
Partial payments are counted immediately.
Cash and account debits are included.
Actual formula = expected collection - recognized collection.
```

### Meaning

The inclusion answers are clear. The only problem is naming.

`Expected collection - recognized collection` is not the actual collection. It is the uncollected gap.

Example:

```text
Scheduled principal due = 80,000
Scheduled interest due = 20,000
Penalty due = 5,000
Fee due = 3,000
Cash/account repayments allocated = 90,000
```

Expected collection is:

```text
80,000 + 20,000 + 5,000 = 105,000
```

The fee is excluded.

Recognized collection is:

```text
90,000
```

Collection performance rate is:

```text
90,000 / 105,000 * 100 = 85.71%
```

Collection shortfall is:

```text
105,000 - 90,000 = 15,000
```

### Decision

Implement both metrics:

```text
Expected collection = scheduled principal due + scheduled flat interest due + assessed penalties due.
Recognized collection = repayments allocated from cash/account debits to principal, interest, and penalties.
Collection performance rate = recognized collection / expected collection * 100.
Collection shortfall = max(expected collection - recognized collection, 0).
Collection surplus = max(recognized collection - expected collection, 0).
```

Fees are excluded from expected and recognized collection for this metric.

Unallocated customer account deposits are not recognized collection until the system allocates them to the loan.

If expected collection is zero, the rate should be `null`, not `0%`, because there was nothing due to collect.
