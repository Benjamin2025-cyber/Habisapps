# Loan Assurance V1 Domain Remediation Backlog

## Evidence

- The v1 loan product UI exposes guarantor, collateral/guarantee, setup-charge, tax, penalty, accounting, and calculation-policy fields.
- The v1 loan product UI does not expose insurer partners, insurance products, subscriptions, premium assessments, premium payments, claims, remittances, commissions, or renewals.
- Bancassurance is explicitly future/non-v1 scope. It must not be inferred from a loan product `insurance_rate` field.

## Corrected Boundary

For v1 credit/loans:

- `guarantor_required` and collateral/guarantee controls are loan security requirements.
- `guarantee_deposit` is a loan setup charge/liability.
- `insurance_rate` may calculate a loan-level assurance amount for compatibility with existing loan formula fields.
- A loan-level assurance amount is not an insurance premium workflow.
- Loan setup must not create `insurance_subscriptions`, `insurance_premium_assessments`, or `insurance_premium_payments`.
- Loan disbursement readiness must not depend on insurance premium assessment or collection.
- The loan API must not expose a loan-scoped premium collection endpoint in v1.

For future bancassurance:

- Insurance partners, insurance products, subscriptions, premium schedules, premium payments, claims, remittances, commissions, renewals, endorsements, cancellations, and refunds remain in the insurance/bancassurance domain.
- Bancassurance workflows may reference loans only through an explicit future product decision and UI/API contract.

## Implementation Tasks

- [x] Stop loan setup assessment from creating insurance subscription and premium rows.
- [x] Keep `loans.insurance_amount_minor` as a calculated loan field without treating it as a premium.
- [x] Remove insurance premiums from loan setup readiness serialization and disbursement blockers.
- [x] Remove the loan-scoped premium collection route and controller delegation.
- [x] Remove `loan_insurance_premium` from loan operation mapping readiness checks.
- [x] Update Module 4 feature tests to prove no insurance premium rows are created by loan setup.
- [x] Update Module 4 feature tests to prove disbursement readiness is based on setup charges only.
- [ ] Product decision: clarify whether the UI label `Taux d'assurance` should be renamed to a loan-domain term or removed from v1.
- [ ] Future bancassurance backlog: define explicit integration rules if a later release sells borrower insurance through the insurance module.

## Regression Rules

- A loan setup assessment with `insurance_rate` must not insert into `insurance_subscriptions`.
- A loan setup assessment with `insurance_rate` must not insert into `insurance_premium_assessments`.
- `GET /api/v1/loans/{loan}/setup-charges` must not return premium payment instructions.
- `POST /api/v1/loans/{loan}/disburse` must not fail because of missing insurance premium collection.
- Operation-account readiness for v1 loan posting must not require `loan_insurance_premium`.

