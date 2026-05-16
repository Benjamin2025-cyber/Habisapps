# Financial Domain Adversarial Review Remediation Backlog

Review date: 2026-05-16

Scope: adversarial review of the current Modules 1-5 implementation against the approved stakeholder formula responses, the implementation audit, and the current loan/cash/accounting code. This backlog focuses only on findings that can distort balances, loan dues, controls, or accounting evidence.

## Reviewed Inputs

Primary docs/backlogs:
- `docs/domain/stakeholder-formula-responses.md`
- `backlogs/defined-modules-implementation-audit.md`
- `backlogs/module-4-credit-loans-backlog.md`
- `backlogs/module-5-cash-operations-completion-backlog.md`
- `backlogs/stakeholder-warning-notes-migration-impact.md`
- `backlogs/stakeholder-complete-migration-completion-audit.md`

Implementation surfaces checked:
- Loan setup charges, schedule generation, disbursement, repayment, early repayment, recovery, arrears/penalties, and reports.
- Cash deposit/withdrawal request validation and teller transaction posting.
- Accounting balance and available-balance calculation.
- Insurance schema and loan-linked borrower insurance assessment.

## Executive Finding

The implementation is structurally broad, but several current behaviors are not yet finance-safe enough to call the implementation complete. The highest-risk gaps are in the loan cash/control boundary: setup charges are assessed but not collected through a real posting workflow, installment schedules still include charges that stakeholder policy says are upfront, loan repayments post all components to a single loan ledger, and physical cash APIs do not enforce whole-XAF cash amounts.

## Implementation Progress

- [x] FIN-ADV-001: Posted setup-charge collection before disbursement supports same-client customer-account debit and teller-cash collection for dossier fee, setup tax, and guarantee deposit.
- [x] FIN-ADV-002: Standard generated schedules no longer spread upfront dossier fee, setup tax, or borrower insurance into installments unless a product explicitly marks a component as financed/periodic.
- [x] FIN-ADV-003: Loan repayment now posts component-specific credit lines using active loan operation account mappings instead of collapsing all allocated components into one aggregate loan ledger credit.
- [x] FIN-ADV-004: Physical XAF cash validation now rejects fractional cash minor amounts on teller deposits, withdrawals, manual cash journal till lines, and cash loan disbursement.
- [x] FIN-ADV-005: Direct loan repayments now check customer account available balance before posting.
- [x] FIN-ADV-006: Loan repayment idempotency now returns the existing posted repayment on replay.
- [x] FIN-ADV-007: J+5 arrears penalty boundary is implemented and covered for due date, J+4, J+5, and same-month repeat.
- [x] FIN-ADV-008: Penalty accounting policy is documented as cash-basis recognition on collection, with no journal posted merely for normal assessment.
- [x] FIN-ADV-009: Borrower insurance premium collection is implemented, disbursement recognizes posted premiums, and insurance partner/product/subscription/claim lifecycle APIs are covered. Claim settlement accounting remains intentionally unposted until an accounting policy is approved.

## Critical Findings

### FIN-ADV-001: Implement posted setup-charge collection before disbursement

Severity: Critical

Evidence:
- Stakeholder policy says dossier fee, setup tax, insurance, and guarantee deposit are upfront/setup items; see `docs/domain/stakeholder-formula-responses.md` sections 6-9.
- `AssessLoanSetupCharges` creates assessments and stores loan charge amounts, but only assesses them.
- Credit routes expose assessment and Direction decision endpoints only; there is no endpoint to collect/post setup charges.
- `DisburseLoan::ensureSetupSatisfied()` accepts statuses like `paid`, `collected`, or `posted`, but there is no application workflow that creates the cash/account debit and journals that justify those statuses.

Financial risk:
- Staff or tests can make disbursement depend on a status flag instead of an auditable cash/accounting event.
- Dossier fee income, VAT/tax payable, guarantee restricted liability, and insurance premium payable/receivable can be missing from the general ledger.
- Guarantee deposit money can be marked as collected without actually increasing a restricted customer guarantee liability.

Required remediation:
- Add a setup-charge collection workflow for `loan_charge_assessments`.
- Supported payment sources must include customer account debit and teller cash deposit path. Cash-originated collection must go through Module 5 teller controls.
- Post separate accounting lines per charge type:
  - Dossier fee: debit cash/customer account, credit fee income or receivable clearing according to operation mapping.
  - Setup tax: debit cash/customer account, credit tax payable.
  - Guarantee deposit: debit cash/customer account, credit restricted guarantee liability/customer guarantee balance.
- Store `journal_entry_id`, `paid_at`, `paid_by_user_id`, source account/session metadata, and idempotency key on the collection record or assessment.
- Keep Direction waiver/reversal separate from normal collection. Waiver must not be equivalent to paid cash.

Acceptance criteria:
- Disbursement refuses an assessed but uncollected setup charge.
- Disbursement accepts only a charge collected by the posting workflow or explicitly waived by Direction where policy permits.
- The workflow is idempotent: replaying the same collection key returns the already posted result.
- Setup charge collection creates balanced journal entries with component-specific ledgers.
- A guarantee deposit creates a restricted liability and can be released only after full loan closure.

Verification:
- Feature tests for cash and account-debit setup charge collection.
- Feature test that manual status mutation is not enough through public APIs.
- Accounting assertions on fee income, tax payable, guarantee liability, and cash/customer account lines.

### FIN-ADV-002: Remove upfront charges from standard loan installment schedules

Severity: Critical

Evidence:
- Stakeholder sections 6-9 say dossier fee, setup tax, insurance, and guarantee deposit are assessed/paid upfront before disbursement.
- `GenerateLoanSchedule` currently splits `dossier_fees_minor`, `insurance_amount_minor`, and `dossier_fees_tax_minor` across installment lines.
- Current tests assert scheduled `fees_minor`, `insurance_minor`, and `tax_minor` on generated installments, so the test suite is locking in the wrong behavior for standard upfront products.

Financial risk:
- The system can collect the same setup charge twice: once before disbursement and again through installments.
- Arrears and penalties can be inflated because schedule dues include upfront charges that should already have been paid.
- Collection performance and portfolio reports can include excluded fees/tax/insurance in operational repayment due calculations.

Required remediation:
- For the approved standard microfinance product, generated installment lines must include principal and flat interest only, plus recurring charges only if a future product rule explicitly marks them as financed or periodic.
- Keep upfront assessed values in `loan_charge_assessments` and `insurance_premium_assessments`; do not spread them into ordinary schedule lines.
- If the schema keeps `fees_minor`, `insurance_minor`, and `tax_minor` columns, document them as optional financed/periodic charge components, defaulting to zero for the approved upfront-charge policy.
- Update repayment allocation to skip zero upfront charge components in standard schedules.

Acceptance criteria:
- A loan with assessed dossier fee, setup tax, borrower insurance, and guarantee deposit generates a standard schedule with `fees_minor = 0`, `insurance_minor = 0`, and `tax_minor = 0`.
- Setup charge collection still blocks disbursement until complete.
- Arrears penalty base excludes upfront charges already collected at setup.
- Existing reports continue to compute expected collection from principal, interest, and penalties only.

Verification:
- Replace the current schedule-generation test assertions that expect per-installment setup charges.
- Add regression coverage proving no double collection between setup-charge collection and loan repayment allocation.

### FIN-ADV-003: Post loan repayment by component ledger, not one aggregate loan ledger

Severity: Critical

Evidence:
- `RecordLoanRepayment` debits the customer account for the allocated total and credits a single loan product ledger for the same total.
- Repayment allocations separately know whether money went to principal, interest, fees, insurance, tax, or penalty, but the journal entry ignores those components.

Financial risk:
- Principal recovery, interest income, penalty income, fee income, tax payable, and insurance premium settlements cannot be reconciled from the ledger.
- The loan asset account can be credited with interest and penalties, understating income and distorting portfolio balances.
- Audit evidence disagrees with operational repayment allocations.

Required remediation:
- Resolve accounting accounts per repayment component through `operation_account_mappings`, loan product mappings, or explicit configured ledger fields.
- Post one debit to the customer account for the allocated amount.
- Post separate credits for principal, interest, penalty, and any approved non-upfront scheduled components.
- Principal repayment must reduce the loan receivable/loan asset. Interest and penalty must go to income or receivable clearing according to accounting policy.
- Tax and insurance components, if ever financed into a schedule, must credit tax payable and insurer/insurance clearing accounts, not the loan receivable account.

Acceptance criteria:
- Repayment journal lines reconcile exactly to repayment allocation components.
- Principal outstanding reports continue to derive from principal allocations, not aggregate journal credits.
- Missing component ledger mappings fail closed with a clear validation error before posting.

Verification:
- Feature test for a repayment containing principal, interest, and penalty that asserts component-specific GL lines.
- Feature test for missing operation mapping that rejects posting.

## High Findings

### FIN-ADV-004: Enforce whole-XAF physical cash on all cash-originated APIs

Severity: High

Evidence:
- `config/money.php` defines account scale `2` and physical cash scale `0`.
- Stakeholder section 1 says physical XAF cash deposits are whole-cash amounts and cash channels should reject fractional physical XAF.
- `StoreCashDepositRequest` and `StoreCashWithdrawalRequest` validate only `amount_minor` as integer/minimum. With scale 2, `83333` represents 833.33 XAF and currently passes cash validation.

Financial risk:
- Teller cash records can contain impossible physical XAF amounts.
- Till reconciliation can balance mathematically while being physically impossible by denomination.
- The exact-account deduction clarification becomes unsafe because the cash intake side no longer guarantees whole-XAF deposits.

Required remediation:
- Add centralized physical-cash validation: when currency is XAF and account scale is 2 with physical cash scale 0, physical cash `amount_minor` must be divisible by 100.
- Apply it to teller deposits, teller withdrawals, cash loan disbursement, cash setup-charge collection, manual cash journals, and denomination reconciliation totals.
- Keep account debit loan repayments allowed at 2-decimal precision because that is not physical cash.

Acceptance criteria:
- Cash deposit of `85000` minor is accepted; `83333` minor is rejected.
- Loan repayment from customer account can still allocate exact `83333` minor.
- Denomination totals reconcile to the accepted cash amount.

Verification:
- Module 5 tests for cash deposit, withdrawal, manual cash journal, and cash disbursement precision.
- Module 4 test proving whole-cash deposit followed by exact-account loan deduction leaves the excess on the customer account.

### FIN-ADV-005: Add available-balance enforcement to direct loan repayments

Severity: High

Evidence:
- Automated recovery uses `AccountingBalanceCalculator::availableForCustomerAccount()` before calling `RecordLoanRepayment`.
- Direct `RecordLoanRepayment` validates customer account ownership and currency but does not check available balance before debiting the customer account.

Financial risk:
- A direct loan repayment can overdraw the customer's deposit liability account.
- Minimum balances, holds, and unavailable amounts can be bypassed through loan repayment even though teller withdrawals correctly enforce them.
- The owner’s clarified flow assumes the customer deposits money first, then the system deducts exactly what is due from an available account balance.

Required remediation:
- Inject/use the available-balance calculator in direct loan repayment posting.
- Reject direct repayment when `amount_minor` exceeds available balance after holds, minimum balance, and unavailable amount.
- Keep automated recovery behavior as a reusable path rather than a separate weaker rule.

Acceptance criteria:
- Direct repayment fails when the account does not have enough available balance.
- Direct repayment succeeds when a prior cash deposit creates enough available balance.
- Holds and minimum balances reduce loan repayment availability.

Verification:
- Feature tests for insufficient direct repayment balance, hold-restricted balance, and exact deduction after whole-XAF deposit.

### FIN-ADV-006: Make loan repayment idempotency replay-safe

Severity: High

Evidence:
- `RecordLoanRepayment` computes an idempotency key and writes it to `journal_entries` and `loan_repayments`.
- The method does not first look up an existing repayment/journal by that key. A replay can hit a database unique constraint instead of returning the original posted repayment.

Financial risk:
- Network retry can return a server error even though the first repayment posted.
- Operators may retry manually and create operational confusion around whether the repayment is posted.

Required remediation:
- Before creating a journal entry, look up an existing `loan_repayments.idempotency_key`.
- If found, return the existing repayment and journal without recalculating allocations.
- If a matching journal exists without repayment, fail closed and surface a reconciliation error.

Acceptance criteria:
- Retrying the same repayment payload returns the original repayment public id and does not create new allocations or journal lines.
- Changing amount/date/account creates a different idempotency key and posts separately.

Verification:
- Feature test for replayed direct repayment.
- Feature test for replayed automated recovery attempt if it calls the repayment workflow.

### FIN-ADV-007: Clarify and test the J+5 penalty boundary

Severity: High

Evidence:
- Stakeholder section 11 says the late rule is J+5.
- `AssessLoanArrearsAndPenalties::isPastGrace()` uses `greaterThan(due_date + grace_days)`.
- Current tests assess a May 1 due installment on May 7. They do not test whether May 6 is late.

Financial risk:
- If J+5 means due date plus five calendar days inclusive, the current implementation assesses one day late.
- Penalty timing affects client charges and regulatory/audit defensibility.

Required remediation:
- Decide the microfinance policy explicitly in docs and tests: "penalty starts on the first business/calendar day after five full grace days" or "at J+5".
- If J+5 is the boundary date, change the comparison to `greaterThanOrEqualTo(due_date + grace_days)`.
- If day 6 is intended, rename the policy text to "after five full grace days" so stakeholders and staff do not read it as same-day J+5.

Acceptance criteria:
- Tests cover due date, J+4, J+5, and J+6.
- The chosen policy text, config snapshot, and implementation agree.

Verification:
- Unit/feature tests for the penalty boundary and monthly repeat behavior.

## Medium Findings

### FIN-ADV-008: Add accounting policy for assessed but unpaid penalties

Severity: Medium

Evidence:
- Penalty assessment updates `loan_schedule_lines.penalty_minor` and `loan_arrears.last_penalized_at`.
- No journal entry is created at assessment time.

Financial risk:
- This is acceptable only if the policy is cash-basis recognition for penalties. If management expects accrual at assessment, income and receivable reporting will be incomplete.

Required remediation:
- Document the selected policy.
- If cash basis: keep no journal at assessment and recognize penalty income only on collection.
- If accrual basis: post penalty receivable and penalty income when assessed, then clear receivable on repayment.

Acceptance criteria:
- Policy is recorded in formula/accounting docs.
- Reports use the same policy consistently.

Verification:
- Test proving assessed unpaid penalties appear operationally in arrears/PAR.
- Test proving GL treatment matches the selected basis.

### FIN-ADV-009: Complete insurance payment and claim workflows before calling insurance complete

Severity: Medium

Evidence:
- The migration creates insurance products, subscriptions, premium assessments, premium payments, and claims.
- The application creates a loan-linked subscription/premium assessment from loan setup.
- Borrower premium payment posting now exists for loan-linked premiums.
- Insurance partner, product, subscription, claim filing, and claim decision APIs now exist.

Financial risk:
- Borrower insurance is partly represented, but full bancassurance is schema-first and not operationally complete.
- A premium assessment can block disbursement, but there is no posted payment workflow through the insurance module.

Required remediation:
- Separate "borrower insurance setup integration" from "full insurance module complete" in the audit.
- Borrower insurance premium payment posting is implemented through `POST /api/v1/loans/{loan}/insurance-premiums/{premiumPublicId}/collect` with `insurance_premium_payments` and accounting journal records.
- Product/subscription/claim lifecycle APIs are implemented for full bancassurance operations.
- Claim settlement accounting must remain a separate approved accounting policy; the claim decision API updates operational status and does not invent ledger entries.

Acceptance criteria:
- Borrower insurance premium can be paid and posted.
- Disbursement recognizes the posted premium payment.
- Full insurance module claims have API/test coverage for filing and decision lifecycle.

Verification:
- Feature tests for borrower premium collection.
- Backlog/audit wording updated to avoid marking schema-only insurance as complete behavior.

## Required Backlog Order

1. FIN-ADV-004 physical cash validation, because it protects every cash-originated flow.
2. FIN-ADV-001 setup-charge collection, because disbursement controls depend on it.
3. FIN-ADV-002 schedule cleanup, because it prevents double collection and corrupt arrears.
4. FIN-ADV-003 component-level repayment accounting, because it fixes the ledger truth.
5. FIN-ADV-005 direct repayment available-balance guard, because it prevents account overdrafts.
6. FIN-ADV-006 repayment idempotency replay, because it protects retries after postings.
7. FIN-ADV-007 penalty boundary, because it controls client charges.
8. FIN-ADV-008 penalty accounting policy, because it must match reporting basis.
9. FIN-ADV-009 insurance workflow completeness, because it separates borrower insurance from full bancassurance.

## Completion Gates

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --memory-limit=1G`
- Targeted Module 4 tests covering setup charges, schedule generation, repayment, early repayment, recovery, arrears, and reporting.
- Targeted Module 5 tests covering cash deposit, withdrawal, cash disbursement, manual cash journals, and reconciliation precision.
- Schema integrity tests after `migrate:fresh --env=testing`.
- Full `php artisan test` only when explicitly allowed, because the current full suite is long-running.
