# IF-070 Implementation Plan: Ijara Product Configuration

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-070
Proof-method: proof by contradiction

## IF-070 Source Requirement

Goal: configure leasing products with or without ownership transfer.

Proof-by-contradiction invariant: assume ownership transfer occurs under ordinary Ijara without transfer terms. Transfer must be rejected.

Acceptance criteria:

- Configure leased asset categories, rental rules, maintenance responsibility, insurance/takaful handling, residual value, transfer option, damage/loss rules, termination rules, and accounting mappings.
- Distinguish Ijara from Ijara wa Iqtina.
- Store approved templates for each variant.

Tests:

- Transfer option unavailable on ordinary Ijara unless configured.
- Missing maintenance policy blocks activation.
- Missing residual policy blocks transfer variant activation.

## Architecture Context

Current state:

- Islamic product write API currently validates `contract_type` with `Rule::in(['murabaha'])` in `IslamicProductWorkflow::storeProduct`, preventing first-class Ijara product configuration.
- Product family baselines/readiness already recognize `ijara` and `ijara_wa_iqtina`, but write paths are not aligned.
- `islamic_products.rules` exists as JSON, but no enforced schema currently guarantees Ijara-specific configuration fields.
- `IslamicStandardWorkflow` reserves template codes for `ijara_contract_template` and `ijara_wa_iqtina_contract_template`, but IF-070 requires explicit approved-template binding and activation-time enforcement.

Current contradiction gaps:

- Ijara and Ijara wa Iqtina cannot be fully configured through the product API despite being recognized by baseline/readiness services.
- No strict product-rule invariant currently prevents ordinary Ijara from carrying transfer behavior.
- No activation gate currently enforces required maintenance/residual policy presence by variant.

## Completion Definition For This Plan

IF-070 is sound only if all are true:

- Product creation/update supports both `ijara` and `ijara_wa_iqtina` as first-class contract types.
- Rule schema enforces leasing fields and variant-specific constraints.
- Ordinary Ijara cannot expose ownership transfer unless explicitly configured under transfer-capable variant semantics.
- Ijara wa Iqtina activation is blocked when residual transfer policy is missing.
- Approved contract template linkage is explicit and auditable per variant.

## Phase 1: Product Contract-Type Enablement

Update Islamic product write validations and normalization to admit:

- `ijara`
- `ijara_wa_iqtina`

Keep canonical mapping consistent with readiness/baseline services to avoid family-resolution drift.

Proof by contradiction:

- Assume IF-070 implemented while `storeProduct` still rejects `ijara`. Impossible because no Ijara product configuration can be persisted.

## Phase 2: Ijara Rule Schema Registry

Introduce explicit rule schema validation for Ijara families in product creation/update:

- `leased_asset_categories` (non-empty array)
- `rental_rules` (schedule/rate/frequency policy object)
- `maintenance_responsibility` (enumeration + scope)
- `takaful_policy` (insurance/takaful handling object)
- `damage_loss_rules` (object)
- `termination_rules` (object)
- `accounting_mapping_profile` (approved mapping profile/code)
- `transfer_option` (boolean)
- `residual_value_policy` (required for transfer variant)

Reject unknown/unsafe keys for these families to avoid silent partial configuration.

## Phase 3: Variant Separation Invariants

Codify immutable variant semantics:

- `ijara`: transfer disabled by default; cannot activate transfer workflow unless product explicitly supports transfer and governance permits it.
- `ijara_wa_iqtina`: transfer-capable variant; requires residual transfer policy and transfer terms metadata.

Guardrails:

- Prevent mutation that flips variant semantics without draft/update governance trail.
- Prevent runtime transfer when financing product family resolves to ordinary Ijara without transfer authorization.

## Phase 4: Activation Readiness Gates

Extend `IslamicProductReadinessService` with Ijara-specific gates:

- `ijara`: maintenance policy mandatory.
- `ijara_wa_iqtina`: maintenance policy + residual policy mandatory.
- both variants: rental/takaful/damage-loss/termination/accounting mapping checks mandatory.

Readiness failures should be keyed and explainable for API error propagation.

## Phase 5: Approved Template Binding

Implement explicit template binding workflow per product variant:

- Store template standard reference (`islamic_standards.public_id` or equivalent immutable version binding).
- Enforce approved/active template status at approval/activation checkpoints.
- Require `ijara_contract_template` for ordinary Ijara and `ijara_wa_iqtina_contract_template` for transfer variant.

Proof by contradiction:

- Assume template is optional under IF-070. Then untemplated contracts can activate, contradicting "Store approved templates for each variant."

## Phase 6: API Surface And Payload Contracts

Extend product payload/read endpoints to return:

- normalized Ijara rules
- variant classification
- transfer capability flags
- template binding metadata

Validation errors must identify which required policy is missing (`maintenance`, `residual`, etc.).

## Phase 7: Audit And Governance Events

Record auditable events:

- `islamic.product.ijara_configured`
- `islamic.product.ijara_transfer_option_rejected`
- `islamic.product.ijara_activation_blocked_missing_maintenance`
- `islamic.product.ijara_wa_iqtina_activation_blocked_missing_residual`
- `islamic.product.template_bound`

Each event includes actor, product public id, variant, and validation outcome.

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IjaraProductConfigurationTest.php`

Minimum tests:

1. `test_ijara_product_can_be_created_with_required_leasing_rules`
2. `test_ijara_wa_iqtina_product_can_be_created_with_transfer_rules`
3. `test_transfer_option_unavailable_on_ordinary_ijara_without_configuration`
4. `test_missing_maintenance_policy_blocks_ijara_activation`
5. `test_missing_residual_policy_blocks_ijara_wa_iqtina_activation`
6. `test_wrong_template_variant_binding_is_rejected`
7. `test_template_must_be_approved_and_active_for_activation`
8. `test_ijara_payload_exposes_variant_and_transfer_flags`

Proof-by-contradiction acceptance alignment tests:

- `test_transfer_option_unavailable_on_ordinary_ijara_unless_configured`
- `test_missing_maintenance_policy_blocks_activation`
- `test_missing_residual_policy_blocks_transfer_variant_activation`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: adding `ijara` to contract-type allowlist without rule schema enables weak/empty configurations.
2. Risk: transfer can leak into ordinary Ijara via permissive `rules` JSON payload.
3. Risk: template reservation exists but no runtime binding check means stale/non-approved templates may pass.

Fixes:

1. require strict schema validation for Ijara families before persistence.
2. enforce variant-specific transfer invariants both at write time and activation time.
3. verify template status/version linkage in readiness gates.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: maintenance responsibility may be interpreted differently across products without normalized enum.
2. Risk: residual policy may be present but zero/negative/invalid under transfer variant.
3. Risk: accounting mapping profile can drift from approved mapping lifecycle windows.

Fixes:

1. normalize maintenance semantics to fixed policy enums + optional detailed clauses.
2. validate residual policy structure and permitted zero-residual exception path with approval evidence.
3. re-check mapping profile approval/effective window at activation transaction time.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. Risk: variant change on an already approved product can bypass intended maker-checker flow.
2. Risk: concurrent updates can race and remove required policies between approval and activation.
3. Risk: tests can pass without proving transfer rejection path on ordinary Ijara financing actions.

Fixes:

1. require draft regression + renewed compliance review when variant-defining fields change.
2. apply row-level lock and version check on product rules during approval/activation.
3. add end-to-end test asserting transfer operation is rejected for ordinary Ijara unless transfer option is explicitly and validly configured.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Can both Ijara variants be configured through product APIs with strict validated rules? Yes.
- Is ordinary Ijara prevented from transfer behavior unless explicitly permitted and governed? Yes.
- Does missing maintenance policy block activation? Yes.
- Does missing residual policy block transfer variant activation? Yes.
- Are approved templates bound per variant and enforced at activation? Yes.

## Test Execution Instructions

Use these commands during IF-070 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Ijara product configuration changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IjaraProductConfigurationTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume ordinary `ijara` can enable transfer behavior directly in product rules.
   - Contradiction: draft validation rejects `transfer_option=true` for ordinary Ijara and readiness gates enforce variant-specific transfer semantics.
2. Assume Ijara variant payloads are opaque and do not expose transfer capability metadata.
   - Contradiction: product payload now includes variant classification and transfer capability flags.
3. Assume transfer-variant template linkage can point to the wrong reserved template code and still activate.
   - Contradiction: readiness gate rejects mismatched template code binding for `ijara_wa_iqtina`.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if070_|test_if071_|test_if072_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `composer test` passed: `OK (623 tests, 9816 assertions)`.
