# Formula Guardrails While Stakeholder Decisions Are Pending

The backend must not calculate customer balances, loan schedules, interest, penalties, repayment allocation, teller reconciliation, or portfolio reports from assumptions.

## Implemented Safe Foundation

- Formula policy gates live in `config/formulas.php`.
- Calculation services must call `FormulaPolicyRegistry::requireApproved(...)` before executing formula-dependent behavior.
- The default for every formula policy is `approved: false`.
- If a future service tries to execute without approval, it must fail closed with `FormulaPolicyNotApproved`.
- Formula engines are plug-and-play drivers resolved by `FormulaEngineManager`, similar to payment gateway drivers.
- Every critical calculation area maps to a configured engine key: rounding, loan interest, installments, repayment allocation, fees/taxes/insurance, penalties/arrears, account balances, cash/till reconciliation, and portfolio reporting.
- The default `unavailable` engine is intentionally non-operational and exists to prevent guessed formulas.
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
4. A driver class implementing the relevant formula contract is added.
5. `config/formulas.php` maps the engine key to the approved driver.
6. The implementation adds tests for the approved rule and calls the engine through `FormulaEngineManager`, not directly by class name.

## Driver Pattern

Example shape:

```php
config([
    'formulas.policies.loan_interest_method.approved' => true,
    'formulas.engines.loan_interest' => 'approved_flat_interest',
    'formulas.drivers.approved_flat_interest' => ApprovedFlatInterestEngine::class,
]);
```

Application services should depend on `FormulaEngineManager` or a formula contract, never a hardcoded formula class. That allows replacing an approved formula later without rewriting the consuming workflow.
