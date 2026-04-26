# Formula Guardrails While Stakeholder Decisions Are Pending

The backend must not calculate customer balances, loan schedules, interest, penalties, repayment allocation, teller reconciliation, or portfolio reports from assumptions.

## Implemented Safe Foundation

- Formula policy gates live in `config/formulas.php`.
- Calculation services must call `FormulaPolicyRegistry::requireApproved(...)` before executing formula-dependent behavior.
- The default for every formula policy is `approved: false`.
- If a future service tries to execute without approval, it must fail closed with `FormulaPolicyNotApproved`.
- Reusable value objects exist for amounts, percentage rates, date ranges, and journal entry drafts.
- Journal entry drafts validate accounting invariants such as balanced debits and credits without choosing business formulas.

## Allowed Before Stakeholder Sign-Off

- Validate money currencies and prevent cross-currency arithmetic.
- Validate percentage and date-range inputs.
- Build immutable journal-entry drafts and ensure debits equal credits.
- Build API/resource/request scaffolding that does not compute amounts owed.
- Add tests proving formula-dependent services fail closed.

## Not Allowed Before Stakeholder Sign-Off

- Generating repayment schedules.
- Calculating interest, penalties, taxes, insurance, or fees.
- Allocating repayments across principal, interest, penalties, or tax.
- Computing customer available balances.
- Computing till theoretical balances or reconciliation postings.
- Computing portfolio-at-risk or collection-performance reports.

## Approval Flow

1. Stakeholders complete `docs/domain/stakeholder-formula-questions.md`.
2. The approved rule is documented in the relevant domain document.
3. The relevant key in `config/formulas.php` is explicitly approved with owner and date.
4. The implementation adds tests for the approved rule and calls the policy gate.
