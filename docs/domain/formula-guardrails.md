# Formula Guardrails For Approved And Pending Formula Rules

The backend must not calculate customer balances, loan schedules, interest, penalties, repayment allocation, teller reconciliation, or portfolio reports from assumptions. A formula gate may open only when the rule is explicit and the implementation is tested.

## Implemented Safe Foundation

- Formula policy gates live in `config/formulas.php`.
- Calculation services must call `FormulaPolicyRegistry::requireApproved(...)` before executing formula-dependent behavior.
- Formula policies default to `approved: false` until we intentionally approve a rule. `xaf_rounding` is approved because the stakeholder answer clearly separates whole-XAF physical cash from exact 2-decimal loan/account ledger amounts.
- If a future service tries to execute without approval, it must fail closed with `FormulaPolicyNotApproved`.
- Formula engines are plug-and-play drivers resolved by `FormulaEngineManager`, similar to payment gateway drivers.
- Every critical calculation area maps to a configured engine key: rounding, loan interest, installments, repayment allocation, fees/taxes/insurance, penalties/arrears, account balances, cash/till reconciliation, and portfolio reporting.
- The default `unavailable` engine is intentionally non-operational and exists to prevent guessed formulas.
- Reusable value objects exist for amounts, percentage rates, date ranges, and journal entry drafts.
- Journal entry drafts validate accounting invariants such as balanced debits and credits without choosing business formulas.

## Allowed Before Formula Approval

- Validate money currencies and prevent cross-currency arithmetic.
- Validate percentage and date-range inputs.
- Build immutable journal-entry drafts and ensure debits equal credits.
- Build API/resource/request scaffolding that does not compute amounts owed.
- Add tests proving formula-dependent services fail closed.

## Not Allowed Before Formula Approval

- Generating repayment schedules.
- Calculating interest, penalties, taxes, insurance, or fees.
- Allocating repayments across principal, interest, penalties, or tax.
- Computing customer available balances.
- Computing till theoretical balances or reconciliation postings.
- Computing portfolio-at-risk or collection-performance reports.

## Approval Flow

1. Capture the stakeholder answer or implementation decision in the relevant domain document.
2. Confirm the rule is finance-aligned and exact enough to test.
3. Approve the relevant key in `config/formulas.php`.
4. Add or update the implementation and tests for the approved rule.
5. Use a driver through `FormulaEngineManager` where the formula is a replaceable calculation engine.

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
