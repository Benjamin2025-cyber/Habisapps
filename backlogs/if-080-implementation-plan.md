# IF-080 Implementation Plan: Salam Product Configuration

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-080
Proof-method: proof by contradiction

## IF-080 Source Requirement

Goal: configure Salam as upfront purchase of specified goods delivered later.

Proof-by-contradiction invariant: assume Salam is used as unrestricted cash financing. Contract approval must fail because goods and delivery terms are required.

Acceptance criteria:

- Configure allowed goods, specification requirements, payment timing, delivery rules, inspection rules, substitution policy, non-delivery policy, parallel Salam policy, and accounting mappings.
- Store approved template and screening policy.

Tests:

- Missing goods policy blocks activation.
- Missing upfront payment mapping blocks activation.
- Cash-only request rejected.

## Architecture Context

Current state:

- `IslamicProductWorkflow::storeProduct` still validates `contract_type` with `Rule::in(['murabaha'])`, so Salam product configuration cannot be persisted through normal product APIs.
- `IslamicProductReadinessService` maps `salam` family, but no Salam-specific configuration gate enforces required policies (goods spec, delivery, inspection, substitution, non-delivery, parallel Salam).
- `IslamicStandardWorkflow` contains reserved `salam_contract_template` code and screening-policy reserved codes, but registry linkage is still constrained around reserved identifiers and not Salam-governance-complete.
- `IslamicFinancingWorkflow::storeFinancing` is Murabaha-only and does not provide Salam-specific anti-cash-only controls for product usage context.

Current contradiction gaps:

- Salam is recognized in family maps but not executable through product write paths.
- No strict Salam rules schema currently blocks vague/unrestricted cash-financing configurations.
- Upfront payment mapping prerequisites are not enforced as Salam-activation invariants.

## Completion Definition For This Plan

IF-080 is sound only if all are true:

- Salam products can be created/updated with strict Salam rule schema.
- Salam activation is blocked when goods policy or upfront payment mapping policy is missing.
- Cash-only unrestricted usage is rejected by policy gates.
- Approved Salam template and screening policy linkage is explicit and auditable.

## Phase 1: Salam Product Contract-Type Enablement

Enable `contract_type = salam` in Islamic product write validations and shared contract-family normalization.

Proof by contradiction:

- Assume IF-080 complete while Salam contract type remains rejected at write-time. Impossible because no Salam product can exist for activation.

## Phase 2: Salam Rule Schema Definition

Introduce strict Salam rules contract for product configuration:

- `allowed_goods_policy` (allowed categories/codes)
- `specification_requirements` (quality/quantity/spec-detail thresholds)
- `payment_timing_policy` (upfront payment semantics and constraints)
- `delivery_rules` (date/place/tolerance windows)
- `inspection_rules` (inspection authority, acceptance criteria)
- `substitution_policy` (when and how substitutions are permitted)
- `non_delivery_policy` (default/remedy/escalation handling)
- `parallel_salam_policy` (enablement constraints and risk controls)
- `accounting_mapping_profile` (approved Salam mapping set)

Reject unknown/unsafe keys to prevent silent partial configuration.

## Phase 3: Readiness Gates For Salam Activation

Extend readiness validation for Salam products:

- missing goods policy => activation fail
- missing upfront payment mapping/profile => activation fail
- missing required delivery/inspection/non-delivery policies => activation fail
- reject policies that reduce Salam to unrestricted cash financing

Error responses should be gate-keyed for operational diagnosability.

## Phase 4: Anti Cash-Only Constraint

Add explicit Salam guardrail:

- product rules must include goods-spec and delivery commitments
- financing/approval paths must reject requests with cash-only intent and no goods/delivery traceability

Proof by contradiction:

- Assume cash-only Salam request is accepted. Then Salam behaves like unrestricted cash financing, contradicting IF-080 invariant.

## Phase 5: Template And Screening Policy Linkage

For Salam product activation:

- require approved contract template linkage aligned to `salam_contract_template`
- require approved active screening policy linkage for Salam scope
- ensure linkage evidence is persisted and auditable

## Phase 6: Accounting Mapping Preconditions

Require approved Salam upfront-payment mapping at activation readiness:

- mapping exists, active, and within effective window
- mapping operation family is Salam-compatible
- interest-class operation codes rejected

This phase aligns with IF-050/IF-051 mapping governance.

## Phase 7: API Payload And Governance Events

Extend product payload/read responses to expose:

- normalized Salam rules
- template binding reference
- screening policy binding reference
- mapping profile reference

Audit events:

- `islamic.product.salam_configured`
- `islamic.product.salam_activation_blocked_missing_goods_policy`
- `islamic.product.salam_activation_blocked_missing_upfront_mapping`
- `islamic.product.salam_cash_only_rejected`
- `islamic.product.salam_template_bound`

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/SalamProductConfigurationTest.php`

Minimum tests:

1. `test_salam_product_can_be_created_with_required_rules`
2. `test_missing_goods_policy_blocks_salam_activation`
3. `test_missing_upfront_payment_mapping_blocks_salam_activation`
4. `test_cash_only_salam_request_is_rejected`
5. `test_salam_requires_template_binding_before_activation`
6. `test_salam_requires_active_screening_policy_before_activation`
7. `test_salam_rule_schema_rejects_unknown_or_unsafe_keys`
8. `test_salam_payload_exposes_policy_bindings_and_mapping_profile`

Proof-by-contradiction acceptance alignment tests:

- `test_missing_goods_policy_blocks_activation`
- `test_missing_upfront_payment_mapping_blocks_activation`
- `test_cash_only_request_rejected`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: adding `salam` contract type without strict schema allows weak placeholder rules.
2. Risk: goods policy may exist nominally but contain wildcard semantics equivalent to unrestricted cash use.
3. Risk: upfront mapping checks may be implemented only as existence checks, ignoring status/effective windows.

Fixes:

1. enforce Salam schema with mandatory structures and semantic validation.
2. reject wildcard/empty goods policy shapes that collapse traceability.
3. validate mapping status and effective dates within activation transaction.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: parallel Salam policy can be enabled without counterparty separation controls.
2. Risk: inspection policy could be optional in practice due to nullable rule parsing.
3. Risk: screening policy linkage can drift from active to expired after approval.

Fixes:

1. require counterparty and risk-separation constraints when parallel Salam is enabled.
2. make inspection policy mandatory with explicit validator failures.
3. re-check screening policy active status at activation commit time.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. Risk: later financing flow might bypass product-level cash-only guard.
2. Risk: policy snapshots may be mutable, weakening audit defensibility.
3. Risk: tests may assert HTTP rejection but not verify precise blocker reason category.

Fixes:

1. enforce anti-cash-only check at both product activation and financing approval paths.
2. persist immutable rule/template/policy snapshot hash at activation.
3. assert structured failure keys/messages for goods/mapping/cash-only blockers.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Can Salam products be configured with strict required policies? Yes.
- Do missing goods policy and missing upfront mapping each block activation? Yes.
- Is unrestricted cash-only Salam usage rejected? Yes.
- Are approved template and screening policy bindings required and auditable? Yes.
- Are Salam mappings validated as approved/effective and non-interest-class? Yes.

## Test Execution Instructions

Use these commands during IF-080 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Salam product configuration changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/SalamProductConfigurationTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume Salam can be configured with arbitrary/unknown rule keys and still pass as valid configuration.
   - Contradiction: draft-time Salam shape validation now rejects unknown keys and unsafe wildcard goods policies.
2. Assume Salam activation can proceed without upfront payment mapping.
   - Contradiction: readiness activation failure includes `islamic_product_upfront_payment_mapping` when missing.
3. Assume unrestricted cash semantics can still be configured as Salam.
   - Contradiction: `cash_only=true` is explicitly rejected at activation gating.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if080_'
php artisan test --parallel --recreate-databases --filter 'test_if040_|test_if041_|test_if042_|test_if080_|test_if081_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `test_if080_` subset passed within targeted bundle.
- `test_if040_|test_if041_|test_if042_|test_if080_|test_if081_` passed: `OK (41 tests, 852 assertions)`.
- `IslamicFinanceTest` passed: `OK (146 tests, 3557 assertions)`.
- `composer test` passed: `OK (633 tests, 10058 assertions)`.
