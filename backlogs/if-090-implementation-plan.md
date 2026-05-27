# IF-090 Implementation Plan: Istisna'a Product Configuration

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-090
Proof-method: proof by contradiction

## IF-090 Source Requirement

Goal: configure construction/manufacturing contracts.

Proof-by-contradiction invariant: assume staged payments are allowed without milestones. Product activation must fail.

Acceptance criteria:

- Configure project categories, milestone rules, inspection rules, payment rules, variation rules, delivery/acceptance rules, defect rules, parallel Istisna'a policy, and mappings.
- Store approved template and screening policy.

Tests:

- Missing milestone policy blocks activation.
- Missing variation policy blocks activation.
- Missing project mapping blocks activation.

## Architecture Context

Current state:

- `IslamicProductWorkflow::storeProduct` still enforces `Rule::in(['murabaha'])` for `contract_type`, preventing first-class Istisna'a product creation.
- `IslamicProductReadinessService` and baseline service already map `istisnaa`, but no Istisna'a-specific readiness gates enforce milestone/variation/project-mapping requirements.
- `IslamicStandardWorkflow` reserves `istisnaa_contract_template`, yet template/policy linkage remains insufficient for full variant-governance activation checks.
- Financing flow remains Murabaha-centric and has no staged-payment/milestone-bound semantics.

Current contradiction gaps:

- Istisna'a products cannot be fully configured and activated through current write paths.
- Staged payment control is not tied to mandatory milestone governance.
- Variation and project mapping requirements are not enforced at activation.

## Completion Definition For This Plan

IF-090 is sound only if all are true:

- Istisna'a products can be created/updated with strict project-configuration rules.
- Activation fails when milestone, variation, or project mapping policy is missing.
- Approved template and screening policy bindings are mandatory and auditable.

## Phase 1: Contract-Type Enablement

Enable `contract_type = istisnaa` in product write validations and shared contract-family normalization.

Proof by contradiction:

- Assume IF-090 complete while product API still rejects Istisna'a. Impossible because no Istisna'a product can be configured.

## Phase 2: Istisna'a Rule Schema Registry

Define strict Istisna'a configuration schema:

- `project_categories_policy`
- `milestone_rules`
- `inspection_rules`
- `payment_rules`
- `variation_rules`
- `delivery_acceptance_rules`
- `defect_rules`
- `parallel_istisnaa_policy`
- `project_accounting_mapping_profile`

Validation requirements:

- mandatory structure and typed fields
- reject unknown/unsafe keys
- reject internally contradictory policy combinations

## Phase 3: Activation Readiness Gates

Add Istisna'a readiness checks:

- missing milestone rules => fail
- missing variation rules => fail
- missing project mapping profile => fail
- missing inspection/payment/delivery/defect controls => fail

All failures should be gate-keyed and propagated through API errors.

## Phase 4: Staged-Payment Milestone Invariant

Codify invariant for product configuration:

- staged or progressive payments require explicit milestone definitions and approval controls
- payment rules must reference milestone gating behavior

Proof by contradiction:

- Assume staged payment allowed with empty milestones. Contradiction with IF-090 invariant.

## Phase 5: Variation Governance Binding

Require variation policy to define:

- approval thresholds
- impact scope (future obligations only vs constrained retrospective handling)
- audit evidence requirements
- linkage to payment/delivery revisions

Missing or weak variation policy blocks activation.

## Phase 6: Template And Screening Policy Binding

Activation prerequisites:

- approved contract template bound to `istisnaa_contract_template`
- approved active screening policy linkage for Istisna'a scope
- persisted immutable binding references for audit traceability

## Phase 7: Project Mapping Preconditions

Require active/effective Istisna'a project mappings:

- mapping profile exists and is approved
- effective window valid at activation
- interest-class mapping routes rejected

Aligns with IF-050/IF-051 controls.

## Phase 8: API Payload And Audit Events

Extend payload/read models to include:

- normalized Istisna'a policy blocks
- template/screening/mapping binding references

Audit events:

- `islamic.product.istisnaa_configured`
- `islamic.product.istisnaa_activation_blocked_missing_milestone_policy`
- `islamic.product.istisnaa_activation_blocked_missing_variation_policy`
- `islamic.product.istisnaa_activation_blocked_missing_project_mapping`
- `islamic.product.istisnaa_template_bound`

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IstisnaaProductConfigurationTest.php`

Minimum tests:

1. `test_istisnaa_product_can_be_created_with_required_rules`
2. `test_missing_milestone_policy_blocks_istisnaa_activation`
3. `test_missing_variation_policy_blocks_istisnaa_activation`
4. `test_missing_project_mapping_blocks_istisnaa_activation`
5. `test_staged_payment_policy_without_milestones_is_rejected`
6. `test_istisnaa_requires_template_binding_before_activation`
7. `test_istisnaa_requires_active_screening_policy_before_activation`
8. `test_istisnaa_payload_exposes_policy_and_mapping_bindings`

Proof-by-contradiction acceptance alignment tests:

- `test_missing_milestone_policy_blocks_activation`
- `test_missing_variation_policy_blocks_activation`
- `test_missing_project_mapping_blocks_activation`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: permitting Istisna'a type without strict schema yields superficial configuration.
2. Risk: milestone rules could exist but be semantically empty, still allowing staged payments.
3. Risk: project mapping checks may validate presence only, not effectiveness.

Fixes:

1. enforce required semantic fields and non-empty policy invariants.
2. require milestone schedule schema and approval-gate clauses.
3. validate mapping status/effective windows at activation transaction time.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: variation policy may allow rewriting posted obligations without constraints.
2. Risk: parallel Istisna'a policy may be enabled without counterparty separation controls.
3. Risk: screening/template linkage may drift between approval and activation.

Fixes:

1. constrain variation policy to forward-looking adjustments with explicit governance.
2. require risk-separation and counterparty rules for parallel mode.
3. re-check linkage status under activation lock and persist snapshot hash.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. Risk: downstream workflow could bypass product gates and use generic payment logic.
2. Risk: audit events might not capture which policy gate failed.
3. Risk: tests may validate status only without asserting blocker categories.

Fixes:

1. enforce Istisna'a gate checks at both product activation and contract origination entrypoints.
2. include structured failure codes in audit payloads.
3. assert explicit error keys/messages for milestone/variation/mapping blockers.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Can Istisna'a products be configured with strict required policy blocks? Yes.
- Do missing milestone, variation, or project mapping policies each block activation? Yes.
- Is staged payment prohibited when milestone governance is missing? Yes.
- Are approved template and screening policy bindings required and auditable? Yes.
- Are project mappings validated as active/effective and non-interest-class? Yes.

## Test Execution Instructions

Use these commands during IF-090 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Istisna'a product configuration changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IstisnaaProductConfigurationTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume Istisna'a rules can include arbitrary unknown policy keys.
   - Contradiction: strict Istisna'a schema validation now rejects unknown keys at draft configuration time.
2. Assume staged/progressive payment mode can be configured without explicit milestones.
   - Contradiction: Istisna'a shape validation blocks staged/progressive payment when milestone definitions are empty.
3. Assume missing milestone/variation/project-mapping policies do not block activation.
   - Contradiction: readiness approval fails with explicit gate errors for each missing policy.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if090_'
php artisan test --parallel --recreate-databases --filter 'test_if040_|test_if041_|test_if042_|test_if080_|test_if081_|test_if090_|test_if091_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `test_if090_` subset passed within targeted bundle.
- `test_if040_|test_if041_|test_if042_|test_if080_|test_if081_|test_if090_|test_if091_` passed: `OK (47 tests, 919 assertions)`.
- `IslamicFinanceTest` passed: `OK (152 tests, 3624 assertions)`.
- `composer test` passed: `OK (639 tests, 10125 assertions)`.
