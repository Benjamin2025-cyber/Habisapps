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

### Decision Needed

Define which exceptional setup cases exist and what the system should do for each: refund, no refund, recalculate, reverse, waive, or require approval.

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

The unresolved part is whether unpaid penalties are themselves added to the next penalty base.

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

### Decision Needed

Confirm whether penalties remain separate or become part of the amount used for later penalties.

Recommended implementation reading from their response: keep capitalization disabled unless a specific capitalized-unpaid-amount rule is approved.

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

### Items To Explain

#### Trigger

When does the unpaid amount get updated?

Examples:

- Every month on the 26th.
- At end-of-day batch after the 5-day grace period.
- Only when a manager validates arrears.
- Only when the loan becomes PAR30.

#### Formula

What is the updated unpaid amount?

Example:

```text
Old unpaid = 20,000
Penalty = 5,000 + 2% of 20,000 = 5,400
New unpaid = 25,400
```

Alternative:

```text
Old unpaid = 20,000
Penalty = 5,400
Unpaid remains 20,000
Penalty remains separate
```

#### Future Interest Base

Should future interest use the original principal or the increased unpaid amount?

For flat interest, the likely answer is:

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

#### Accounting Treatment

When the unpaid amount is updated, should the system post a journal entry immediately, or only record an arrears calculation?

### Decision Needed

Define whether "capitalized unpaid amounts" changes accounting balances, or only changes the arrears/penalty calculation base.

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

### Decision Needed

Confirm that accounting balance is strictly based on posted/validated ledger entries and reversal postings.

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

### Decision Needed

List each account type and the rules attached to it:

- minimum balance
- can receive deposits
- can be debited automatically
- can be used for loan recovery
- can be closed while loans are active
- affects available balance or not

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

### Decisions Needed

#### Accounting Balance

Should pending transactions affect accounting balance?

Recommended implementation reading:

```text
No. Accounting balance should use posted/validated ledger entries only.
```

#### Available Balance

Should pending withdrawals reduce available balance?

Example:

```text
Accounting balance = 100,000
Pending withdrawal = 20,000
Minimum balance = 5,000
Available balance = 75,000 if pending withdrawals reduce availability
Available balance = 95,000 if pending withdrawals do not reduce availability
```

#### Till Closing

Should pending teller transactions block closure?

Possible rule:

```text
Pending transactions do not change theoretical cash.
They must be displayed on the close state.
Till cannot close until pending items are validated, cancelled, or explicitly carried forward.
```

### Decision Needed

Define pending statuses and their effect on accounting balance, available balance, and till closing.

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

### Decision Needed

Confirm whether 5,000 XAF is final for ordinary savings and whether minimum balance is global or product-specific.

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

Recommended implementation reading: use posting date for accounting reports and transaction date for operational reports, with batch day-close as the boundary.

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

### Decision Needed

Define whether pending teller transactions block close, require approval, or are only displayed.

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

The unresolved part is how to group the calculation by portfolio type.

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

### Decision Needed

Define each portfolio group and which loan statuses enter each group.

Recommended implementation reading:

```text
Portfolio outstanding = capital + interest + penalties for active non-written-off loans.
Written-off loans are excluded from outstanding exposure and reported separately.
```

