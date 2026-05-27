# IF-100 Implementation Plan: Moudaraba Product Configuration

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-100
Proof-method: proof by contradiction

## IF-100 Source Requirement

Goal: configure capital-provider and entrepreneur profit-sharing products.

Proof-by-contradiction invariant: assume fixed institution return is configured. Product activation must fail.

Acceptance criteria:

- Configure eligible business activities, capital rules, profit-sharing ratios, reporting cadence, evidence requirements, loss rules, misconduct/negligence/breach rules, liquidation rules, and mappings.
- Prohibit guaranteed return, interest, and fixed profit amount as institution entitlement.

Tests:

- Guaranteed return rejected.
- Missing reporting cadence blocks activation.
- Missing loss-rule policy blocks activation.

## Architecture Context

Current state:

- `IslamicProductWorkflow::storeProduct` still validates `contract_type` with `Rule::in(['murabaha'])`, so Moudaraba products cannot be created through normal write paths.
- `IslamicProductReadinessService` maps `moudaraba`, but no Moudaraba-specific readiness gates exist for reporting cadence, loss rules, and misconduct governance.
- `IslamicInterestGuardPolicy` blocks conventional interest semantics, but this alone does not enforce anti-guaranteed-return logic specific to profit-sharing structures.
- No first-class Moudaraba policy schema currently models capital/loss/misconduct/liquidation constraints.

Current contradiction gaps:

- Moudaraba family is recognized but not operationally configurable via APIs.
- Fixed/guaranteed institution return risk is not explicitly blocked by Moudaraba product rules.
- Reporting cadence and loss-rule requirements are not mandatory activation gates.

## Completion Definition For This Plan

IF-100 is sound only if all are true:

- Moudaraba products are configurable through strict schema-backed APIs.
- Guaranteed return/fixed institution entitlement is rejected at validation and activation.
- Reporting cadence and loss-rule policies are mandatory for activation.
- Misconduct/negligence/breach and liquidation controls are structured and auditable.

## Phase 1: Contract-Type Enablement

Enable `contract_type = moudaraba` in Islamic product write validation and canonical family normalization.

Proof by contradiction:

- Assume IF-100 complete while write path still rejects Moudaraba. Impossible because no compliant Moudaraba product can be persisted.

## Phase 2: Moudaraba Policy Schema

Introduce strict Moudaraba rules schema:

- `eligible_business_activities_policy`
- `capital_rules`
- `profit_sharing_ratio_rules`
- `reporting_cadence_policy`
- `evidence_requirements_policy`
- `loss_rules`
- `misconduct_negligence_breach_rules`
- `liquidation_rules`
- `accounting_mapping_profile`

Validation properties:

- required typed blocks
- ratio constraints and sum checks
- reject unknown/unsafe keys

## Phase 3: Anti-Guaranteed-Return Invariants

Add Moudaraba-specific prohibition checks:

- reject fixed institution return amount entitlements
- reject guaranteed minimum institution return rules
- reject hidden interest-like constructs in ratio/profit policies

Proof by contradiction:

- Assume guaranteed return passes activation. Contradiction with IF-100 invariant and acceptance criteria.

## Phase 4: Activation Readiness Gates

Require the following to activate Moudaraba products:

- reporting cadence defined and valid
- loss rule policy present and coherent
- capital + profit-sharing ratio policy valid
- misconduct/liability and liquidation controls present
- mapping profile valid/active

Gate failures must be keyed for deterministic API errors.

## Phase 5: Loss And Liability Separation Rules

Enforce product-level rule semantics:

- normal business loss follows configured capital-provider treatment
- entrepreneur liability route requires explicit misconduct/negligence/breach policy path
- no implicit transfer of normal loss to entrepreneur

## Phase 6: Template, Screening, And Mapping Bindings

Activation prerequisites:

- approved Moudaraba contract template binding (`moudaraba_contract_template`)
- approved screening policy linkage for scope
- approved/effective mapping profile with non-interest-class routes

## Phase 7: API Payload And Audit

Payload enhancements:

- normalized Moudaraba policy blocks
- anti-guaranteed-return validation results
- template/screening/mapping bindings

Audit events:

- `islamic.product.moudaraba_configured`
- `islamic.product.moudaraba_activation_blocked_guaranteed_return`
- `islamic.product.moudaraba_activation_blocked_missing_reporting_cadence`
- `islamic.product.moudaraba_activation_blocked_missing_loss_rules`
- `islamic.product.moudaraba_template_bound`

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/MoudarabaProductConfigurationTest.php`

Minimum tests:

1. `test_moudaraba_product_can_be_created_with_required_rules`
2. `test_guaranteed_return_is_rejected`
3. `test_fixed_institution_profit_amount_entitlement_is_rejected`
4. `test_missing_reporting_cadence_blocks_activation`
5. `test_missing_loss_rules_blocks_activation`
6. `test_moudaraba_ratio_rules_must_be_valid`
7. `test_moudaraba_requires_template_and_screening_bindings_before_activation`
8. `test_moudaraba_payload_exposes_liability_and_liquidation_policies`

Proof-by-contradiction acceptance alignment tests:

- `test_guaranteed_return_rejected`
- `test_missing_reporting_cadence_blocks_activation`
- `test_missing_loss_rule_policy_blocks_activation`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: contract-type enablement without strict schema allows invalid profit-sharing constructs.
2. Risk: anti-interest guard may pass while guaranteed-return logic still sneaks through non-interest labels.
3. Risk: reporting cadence may exist as free text but be non-operational.

Fixes:

1. enforce structured schema with numeric and semantic constraints.
2. add dedicated guaranteed-return/fixed-entitlement validators beyond keyword scans.
3. require cadence as typed interval/frequency with bounded values.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: loss rules might contradict capital rules under edge conditions.
2. Risk: misconduct policy may be absent while liability transfer is configured.
3. Risk: mapping pre-checks may ignore effective windows and stale approvals.

Fixes:

1. validate cross-rule consistency (capital vs loss allocation policy).
2. block entrepreneur-liability routes unless misconduct policy is explicit.
3. re-check mapping active/effective status in activation transaction.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. Risk: downstream capital-deployment workflows may bypass product-level anti-guaranteed-return constraints.
2. Risk: mutable rules after approval can erode audit defensibility.
3. Risk: tests may assert rejection but not verify correct blocker category.

Fixes:

1. enforce Moudaraba guardrails at product activation and contract origination entrypoints.
2. persist immutable policy snapshot hash at activation.
3. assert specific error keys/messages for guaranteed-return/reporting/loss blockers.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Can Moudaraba products be configured through strict policy schema? Yes.
- Are guaranteed return and fixed institution entitlement configurations rejected? Yes.
- Does missing reporting cadence block activation? Yes.
- Does missing loss-rule policy block activation? Yes.
- Are misconduct/liability/liquidation controls and mappings explicit and auditable? Yes.

## Test Execution Instructions

Use these commands during IF-100 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Moudaraba product configuration changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/MoudarabaProductConfigurationTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume Moudaraba can hide guaranteed/fixed institution entitlement under non-interest labels.
   - Contradiction: dedicated Moudaraba validations now reject guaranteed return and fixed institution profit entitlement semantics.
2. Assume Moudaraba rules can be configured with arbitrary unknown keys.
   - Contradiction: strict Moudaraba schema validation rejects unknown keys at draft configuration.
3. Assume missing reporting cadence or loss-rule policy does not block activation.
   - Contradiction: readiness approval fails with explicit `islamic_product_reporting_cadence_policy` and `islamic_product_loss_rules` gate failures.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if100_'
php artisan test --parallel --recreate-databases --filter 'test_if080_|test_if081_|test_if090_|test_if091_|test_if100_|test_if040_|test_if041_|test_if042_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `test_if100_` subset passed with related Moudaraba guard test.
- `test_if080_|test_if081_|test_if090_|test_if091_|test_if100_|test_if040_|test_if041_|test_if042_` passed: `OK (51 tests, 955 assertions)`.
- `IslamicFinanceTest` passed: `OK (156 tests, 3660 assertions)`.
- `composer test` passed: `OK (643 tests, 10161 assertions)`.
