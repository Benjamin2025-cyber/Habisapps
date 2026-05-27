# IF-102 Implementation Plan: Moudaraba Loss, Misconduct, And Exit

Date: 2026-05-24
Status: implementation plan
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-102
Proof-method: proof by contradiction

## IF-102 Source Requirement

Goal: handle losses, misconduct, liquidation, and exit correctly.

Proof-by-contradiction invariant: assume normal business loss is charged to entrepreneur. Charge must fail.

Acceptance criteria:

- Record normal business loss against capital provider as configured.
- Entrepreneur liability requires approved misconduct, negligence, or breach finding.
- Exit/liquidation records final assets, recoveries, losses, and distributions.
- Accounting posts impairment, loss, recovery, and liquidation events through approved mappings.

Tests:

- Normal loss cannot be charged to entrepreneur.
- Misconduct recovery requires approved finding.
- Liquidation report reconciles postings.

## Architecture Context

Current state:

- Moudaraba product/contract workflow is not yet first-class in current implementation seams; no explicit loss/misconduct/liquidation engine is present.
- Existing Islamic posting flows are Murabaha-centric and do not encode Moudaraba loss attribution semantics.
- No dedicated workflow currently binds entrepreneur liability to approved misconduct/negligence/breach findings.
- No liquidation reconciliation report pipeline exists for Moudaraba-specific final assets/recoveries/loss/distribution traceability.

Current contradiction gaps:

- Normal business loss attribution can drift without enforced capital-provider rule.
- Recovery charge paths can be executed without formal misconduct finding workflow.
- Exit/liquidation accounting and reconciled reporting are not represented as governed lifecycle steps.

## Completion Definition For This Plan

IF-102 is sound only if all are true:

- Normal loss attribution is constrained to capital-provider treatment per policy.
- Entrepreneur liability/recovery is impossible without approved misconduct finding.
- Liquidation records and postings reconcile in auditable report outputs.
- Impairment/loss/recovery/liquidation postings use approved mappings only.

## Phase 1: Loss Event Domain Model

Create Moudaraba loss-event entities:

- event type (`normal_business_loss`, `misconduct_loss`, `recovery`, `impairment`, `liquidation`)
- period/context linkage
- amount/currency
- evidence references
- policy/finding references

## Phase 2: Normal Loss Attribution Guard

Implement strict rule:

- `normal_business_loss` postings allocate to capital-provider side only
- entrepreneur charge route for normal loss is rejected

Proof by contradiction:

- Assume normal loss posts to entrepreneur liability. Impossible once event-type guard blocks such mapping route.

## Phase 3: Misconduct/Negligence/Breach Finding Workflow

Add finding workflow:

- open allegation case
- collect evidence and review
- decision states (`approved`, `rejected`, `needs_info`)
- only `approved` finding enables entrepreneur recovery posting

## Phase 4: Recovery Posting Gate

For entrepreneur recovery actions:

- require linked approved misconduct/negligence/breach finding
- require approved recovery mapping
- reject recovery posting otherwise

Proof by contradiction:

- Assume recovery posts without approved finding. Contradiction with IF-102 acceptance and workflow guard.

## Phase 5: Exit/Liquidation Workflow

Model liquidation lifecycle:

- capture final asset realization values
- capture recoveries and unresolved losses
- compute final distribution basis
- finalize exit decision with approvals/evidence

States example:

- `liquidation_started`
- `asset_realization_recorded`
- `distribution_computed`
- `liquidation_closed`

## Phase 6: Accounting Mapping Enforcement

Posting flows for:

- impairment
- loss
- recovery
- liquidation

must enforce:

- approved active mapping profile
- effective-window validity
- non-interest-class operation codes

## Phase 7: Liquidation Reconciliation Report

Generate liquidation report per contract:

- opening capital
- cumulative profit/loss
- impairments
- recoveries
- realized assets
- final distributions
- reconciliation delta = zero requirement

Report must trace every figure to posted journal references.

## Phase 8: API Surface

Endpoints (illustrative):

- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/loss-events`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/misconduct-findings`
- `POST /api/v1/islamic-moudaraba-findings/{findingPublicId}/approve`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/recoveries`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/liquidations/start`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/liquidations/close`
- `GET /api/v1/islamic-moudaraba-contracts/{contractPublicId}/liquidation-report`

## Phase 9: Audit Trail

Record events:

- `islamic.moudaraba.loss_recorded`
- `islamic.moudaraba.loss_charge_to_entrepreneur_rejected`
- `islamic.moudaraba.finding_requested`
- `islamic.moudaraba.finding_approved`
- `islamic.moudaraba.recovery_blocked_missing_finding`
- `islamic.moudaraba.recovery_posted`
- `islamic.moudaraba.liquidation_started`
- `islamic.moudaraba.liquidation_closed`
- `islamic.moudaraba.liquidation_report_generated`

## Phase 10: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/MoudarabaLossMisconductExitTest.php`

Minimum tests:

1. `test_normal_business_loss_cannot_be_charged_to_entrepreneur`
2. `test_normal_business_loss_posts_to_capital_provider_route`
3. `test_misconduct_recovery_requires_approved_finding`
4. `test_recovery_posts_after_approved_finding`
5. `test_impairment_loss_recovery_liquidation_require_approved_mappings`
6. `test_liquidation_captures_final_assets_recoveries_losses_and_distributions`
7. `test_liquidation_report_reconciles_postings_to_zero_delta`
8. `test_rejected_finding_blocks_entrepreneur_recovery`

Proof-by-contradiction acceptance alignment tests:

- `test_normal_loss_cannot_be_charged_to_entrepreneur`
- `test_misconduct_recovery_requires_approved_finding`
- `test_liquidation_report_reconciles_postings`

## Phase 11: Adversarial Review (Round 1)

Findings:

1. Risk: loss type misclassification can bypass attribution guard.
2. Risk: finding approval and recovery posting split endpoints can permit race-based bypass.
3. Risk: liquidation report may summarize values without direct journal linkage.

Fixes:

1. enforce strict event-type enum with immutable classification after approval.
2. re-check finding approval under lock in recovery posting transaction.
3. persist mandatory journal-reference arrays in liquidation report model.

## Phase 12: Adversarial Review (Round 2)

Findings:

1. Ambiguity: partial misconduct findings may over-attribute liability.
2. Risk: mapping validity may change between precheck and commit.
3. Risk: concurrent loss/recovery events can create inconsistent net positions.

Fixes:

1. bind recovery ceiling to approved finding scope/amount.
2. re-validate mapping active/effective status in posting transaction.
3. lock contract-period aggregate rows during posting.

## Phase 13: Adversarial Review (Round 3)

Findings:

1. Risk: liquidation closure may proceed with unresolved disputes/findings.
2. Risk: audit logs may not capture rationale for attribution decisions.
3. Risk: tests may verify API response only and miss reconciliation integrity.

Fixes:

1. block liquidation close until blocking disputes/findings are resolved or approved exceptions recorded.
2. include policy/finding rationale fields in audit payload.
3. assert liquidation report totals equal journal-derived totals in tests.

## Phase 14: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Is normal business loss prevented from charging entrepreneur accounts? Yes.
- Does entrepreneur recovery require approved misconduct/negligence/breach finding? Yes.
- Are impairment/loss/recovery/liquidation postings restricted to approved mappings? Yes.
- Does liquidation reporting reconcile to posted accounting evidence? Yes.

## Test Execution Instructions

Use these commands during IF-102 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Moudaraba loss/misconduct/exit changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/MoudarabaLossMisconductExitTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
