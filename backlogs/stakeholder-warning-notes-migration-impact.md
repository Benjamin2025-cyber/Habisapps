# Stakeholder Warning Notes Migration Impact

Source response: `stakeholderResources/Questionnaire_HABIBI 08052026 Retravaillé_120035.md`

This register extracts every stakeholder warning note marked `NOTE` and checks whether it changes the database migration scope. These notes must be treated as functional input, not as editorial comments.

Insurance is also covered below because the stakeholder response includes important insurance decisions outside the `NOTE` marker.

## Impact Summary

| Source section | Stakeholder note | Current migration coverage | Migration impact |
|---|---|---|---|
| Section 6, dossier fees | Exceptional cases for non-refundable dossier fees are still to be handled later. | `loan_products.fee_policy_key`, `loans.formula_policy_snapshot`, journal tables, and `loan_status_transitions` provide policy and audit foundations. There is no dedicated loan charge or fee exception table. | Possible later migration. Do not add a table only from this note yet, but if exceptions become structured approvals, add a `loan_charge_assessments` or `loan_fee_exceptions` table rather than hiding fee overrides in free text. |
| Section 10, penalties | The system must not penalize amounts below 1,000 XAF. | `loan_products.penalty_policy_key` and `loans.formula_policy_snapshot` can point to/store the penalty rule. | No new table required. This is a penalty policy parameter. Store the threshold in formula/policy configuration or the loan policy snapshot, not as a hard-coded migration column unless all policy parameters are later normalized. |
| Section 14, capitalized unpaid amounts | Because capital and interest are fixed, call the concept "capitalized unpaid amounts"; if an installment from month X remains unpaid in month Y, the unpaid amount is updated and penalties are applied. | `loan_schedule_lines` stores scheduled principal, interest, fees, insurance, tax, due date, and status. Journal tables can record accounting movements. There is no dedicated arrears, unpaid balance, penalty assessment, or delinquency tracking table. | Migration likely required. Add a persistent structure for unpaid installment tracking and penalty assessments before implementing this workflow. Candidate tables: `loan_arrears`, `loan_penalty_assessments`, or a broader `loan_charge_assessments` table linked to `loans` and optionally to `loan_schedule_lines`. |
| Section 15, early repayment / recoveries | Automate all recoveries: debit the credit account first; if incomplete, debit any other account of the client. All clients must be linked to their accounts, identified by code. | `clients.client_reference` covers client code. `customer_accounts.client_id` links accounts to clients. `teller_transactions` can link a transaction to a client, account, and loan. `account_holds` exists. There is no account debit priority, recovery mandate, recovery attempt history, or partial-recovery tracking table. | Migration required for reliable implementation. Add a recovery-account priority/mandate table and a recovery-attempt history table before automated cross-account debiting. Candidate tables: `loan_recovery_accounts` and `loan_recovery_attempts`, or a generic `auto_debit_mandates` plus `auto_debit_attempts`. |
| Section 17, account types | Account types need clarification: recovery accounts and ordinary savings accounts. | `customer_accounts.account_type` is a nullable string. There is no account type/product catalog, no account rules table, and no structured recovery eligibility/minimum-balance policy. | Migration likely required if account behavior differs by type. Add `account_types` or `account_products` and reference it from `customer_accounts`. This should carry rules such as recovery eligibility, protected/minimum balance, and ledger mapping if those rules must be configurable. |
| Section 20, billetage / cash closing | Configure the cash-closing interface. | `denominations`, `teller_sessions`, `till_reconciliations`, and `till_reconciliation_lines` already support denomination counting at opening/closing. `teller_sessions` has opening and closing declaration amounts. | No immediate migration required for the note itself. UI/configuration work can use existing tables. Add columns only if the closing workflow must persist approvals, close checklists, theoretical cash, counted cash, or difference totals separately from computed values. |
| Section 28, foreign exchange | Microfinance needs a separate cash drawer from the main drawer: a multi-currency till. | `tills.type` can label a till; `teller_sessions.currency`, `teller_transactions.currency`, `journal_lines.currency`, and `denominations.currency` support currency fields. There is no multi-currency till balance table, FX rate table, FX transaction table, or till-currency configuration table. | Migration required only if FX is accepted into scope. This is a high-impact new feature relative to the current formula work. Candidate tables: `till_currencies`, `till_currency_balances`, `exchange_rates`, and `fx_transactions`, plus ledger mapping for FX gains/losses and currency-specific cash accounts. |

## Insurance Response Impact

Section 8 is not a warning note, but it is migration-relevant.

Stakeholder decision:

- Loan insurance type: fixed percentage.
- Base: granted loan amount.
- Formula: granted amount * 2%.
- Timing: paid upfront.
- Early closure refund: no refund.
- Accounting treatment: automated by the system at setup.

Current migration coverage:

- `loan_schedule_lines.insurance_minor` can store insurance as part of an installment schedule line.
- `loans.formula_policy_snapshot` can preserve the effective formula configuration for the loan.
- Journal tables can record the accounting movement once the charge is assessed.

Current gaps:

- `loan_products` has `interest_policy_key`, `penalty_policy_key`, `repayment_allocation_policy_key`, and `fee_policy_key`, but no explicit `insurance_policy_key`.
- There is no durable table for assessed upfront loan charges. Insurance is paid upfront, so relying only on `loan_schedule_lines.insurance_minor` is not enough if the premium is collected at setup rather than as part of each installment.
- There is no table that records whether the insurance premium was assessed, collected, waived, reversed, or posted to accounting.

Migration impact:

- Add an `insurance_policy_key` to `loan_products`, or replace the narrow fee-only approach with a broader charge policy model if fees, tax, insurance, and guarantee deposit are all handled through the same engine.
- Add a durable upfront charge assessment structure before implementation. A generic `loan_charge_assessments` table is preferable to an insurance-only table because dossier fees, tax, insurance, penalties, and possibly guarantee-deposit assessments need similar lifecycle fields.
- Suggested minimum fields for `loan_charge_assessments`: `loan_id`, `charge_type`, `base_amount_minor`, `rate`, `assessed_amount_minor`, `currency`, `assessed_at`, `due_at`, `status`, `paid_at`, `journal_entry_id`, and optional `reversal_journal_entry_id`.
- If insurance is handled completely now, design Section 8 loan insurance and Section 27 bancassurance as one insurance domain. Loan insurance should then be a loan-linked insurance subscription/premium, not a separate one-off field that will later conflict with the bancassurance module.

### Complete Insurance Domain

Section 27 expands insurance beyond the loan formula. It describes products, subscriptions, premium payments, claims, insurer partners, and reports. If the project scope is to handle insurance completely, the migration set should include a real insurance domain instead of only adding `insurance_policy_key` to loans.

Recommended base tables:

| Table | Purpose | Important links |
|---|---|---|
| `insurance_partners` | External insurers such as AXA Assurance. | Agency, ledger/accounting setup, status. |
| `insurance_products` | Insurance products such as borrower, health, life, savings, agricultural, home, business, vehicle, school, travel, funeral, and equipment insurance. | Partner, product code, product type, premium rule, status. |
| `insurance_product_coverages` | Covered risks such as death, disability, hospitalization, crop loss, theft, fire, or accident. | Insurance product. |
| `insurance_subscriptions` | Client enrollment in an insurance product. Borrower insurance can be linked to a loan. | Client, optional loan, insurance product, start/end dates, status, coverage amount. |
| `insurance_premium_assessments` | Premiums due from a subscription, including upfront loan premiums and recurring premiums. | Subscription, optional loan, amount, currency, due date, status, journal entry. |
| `insurance_premium_payments` | Actual payment or deduction records for premiums. | Premium assessment, customer account or teller transaction, journal entry. |
| `insurance_claims` | Claim files: claim type, incident date, status, settlement/indemnity state. | Client, subscription, product, documents. |

Loan-insurance handling with this model:

- Section 8 formula becomes the default borrower-insurance product rule: granted loan amount * 2%, paid upfront, non-refundable.
- The Section 27 borrower example can coexist as another product/rate rule, for example code `ASS`, partner `AXA Assurance`, risks `death` and `disability`, premium `1.5%`, payment mode `automatic_deduction`.
- `loan_schedule_lines.insurance_minor` can remain a schedule projection/output, but the authoritative premium lifecycle should live in `insurance_premium_assessments` and `insurance_premium_payments`.
- If other setup charges stay outside insurance, keep `loan_charge_assessments` for dossier fees, tax, guarantee deposit, and penalties; do not use it as the only source of truth for insurance once the full insurance module exists.

## Migration Work Implied By Notes

The notes point to these schema gaps:

1. Automated recoveries need durable recovery priority and attempt history.
2. Recovery accounts versus ordinary savings accounts need structured account type/product rules if behavior differs by type.
3. Capitalized unpaid amounts need durable arrears or penalty-assessment records; schedule-line status alone is too thin for repeated month-to-month updates.
4. Fee exceptions may eventually need structured approval/assessment records, but the stakeholder note says they will be specified later.
5. Upfront loan insurance needs policy and assessment persistence; schedule-line insurance alone does not cover setup-time collection.
6. Multi-currency tills require a separate FX schema only if that new feature is accepted into scope.

## Not Migration Blockers

These notes should not block current migrations by themselves:

- The 1,000 XAF penalty floor is a formula/policy parameter.
- The cash-closing interface note can use the existing cash tables for the first implementation.
- Dossier-fee exceptional cases are explicitly deferred by the stakeholder response.
