# IF-060 Implementation Plan: Mourabaha Product Configuration

Date: 2026-05-24
Status: implemented and verified
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-060
Proof-method: proof by contradiction

## IF-060 Source Requirement

Goal: configure Mourabaha as a cost-plus sale product.

Proof-by-contradiction invariant: assume Mourabaha is configured as an interest-bearing loan. Configuration must be rejected.

Acceptance criteria:

- Configure allowed asset categories, allowed costs, margin rule, repayment schedule rules, delivery requirements, early settlement policy, late-payment policy, cancellation policy, and accounting mappings.
- Store Sharia approval and contract template version.
- Prohibit interest formulas.

Tests:

- Interest formula rejected.
- Missing allowed-cost policy blocks activation.
- Approved configuration can originate contract.

## Architecture Context

Current state:

- Islamic product creation supports `contract_type` and optional `default_margin_rate` plus generic `rules` payload.
- Late-payment treatment guard exists and blocks forbidden interest-like treatment values.
- No full Mourabaha configuration schema enforcement exists for all required IF-060 policy dimensions.

Primary contradiction gap:

- Mourabaha products can be created/approved without explicit, validated cost-plus governance fields, allowing under-specified or loan-like behavior.

## Completion Definition For This Plan

IF-060 is sound only if all are true:

- Mourabaha configuration captures every required policy dimension from IF-060.
- Interest formulas are structurally prohibited in Mourabaha configuration.
- Activation gate blocks missing allowed-cost policy and other required dimensions.
- Approved products carry Sharia approval and contract-template version linkage.

## Phase 1: Canonical Mourabaha Configuration Schema

Define `mourabaha_configuration` schema (JSON contract + validator):

Required sections:

- `allowed_asset_categories`
- `allowed_costs_policy`
- `margin_rule`
- `repayment_schedule_rules`
- `delivery_requirements`
- `early_settlement_policy`
- `late_payment_policy`
- `cancellation_policy`
- `accounting_mapping_requirements`

Validation basics:

- non-empty required arrays/objects
- numeric bounds and enum constraints
- no unknown critical keys in strict mode

## Phase 2: Interest Formula Prohibition

Add explicit prohibition checks for Mourabaha configuration:

- forbid interest formula keys/engines/taxonomies.
- forbid interest terminology in schedule-generation modes.
- forbid operation-code mapping classes marked conventional-interest.

Proof by contradiction:

- Assume Mourabaha config uses interest formula. Impossible because validator rejects forbidden keys/classes before persistence/activation.

## Phase 3: Product Create/Update Enforcement

Update `IslamicProductWorkflow` for `contract_type=murabaha`:

- require `rules.mourabaha_configuration` or equivalent canonical payload.
- validate with schema and policy service.
- reject partial/ambiguous payloads.

Error contract:

- return actionable failures under `mourabaha_configuration` / `islamic_interest_guardrails` keys.

## Phase 4: Activation Readiness Gate Integration

Extend readiness checks (IF-031 integration) for Mourabaha:

- required policy sections complete.
- contract template linkage present and approved (IF-032 seam).
- accounting mapping requirements satisfied (IF-051 seam).
- Sharia approval workflow usable.

Hard block:

- missing allowed-cost policy must block activation.

Proof by contradiction:

- Assume activation passes with missing allowed-cost policy. Impossible because readiness gate fails with explicit missing gate.

## Phase 5: Sharia Approval + Template Version Linkage

Require persistent references on product config:

- Sharia approval workflow/public id snapshot
- contract template public id + version snapshot

Behavior:

- approval transition to usable state requires both references valid and approved.

## Phase 6: Accounting Mapping Compatibility

Attach expected mapping set for Mourabaha events:

- sale receivable
- cost/allowed-cost payable
- deferred profit
- reversal/correction mappings

Validator ensures:

- required mappings are present, approved, active, and compatible with Mourabaha operation classes.

## Phase 7: API Surface

Endpoints (existing product endpoints extended):

- create/update Mourabaha product config
- read Mourabaha config summary
- validate-config dry-run endpoint (optional)

Read response includes:

- normalized configuration
- template version reference
- Sharia approval reference
- readiness completeness hints

## Phase 8: Audit Trail

Record:

- `islamic.mourabaha_config.created`
- `islamic.mourabaha_config.updated`
- `islamic.mourabaha_config.validation_blocked`
- `islamic.mourabaha_config.approved`

Audit payload includes rejected keys/reasons for policy failures.

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/MourabahaProductConfigurationTest.php`

Minimum tests:

1. `test_interest_formula_rejected_for_mourabaha_configuration`
2. `test_missing_allowed_cost_policy_blocks_activation`
3. `test_valid_mourabaha_configuration_allows_origination`
4. `test_missing_delivery_requirements_blocks_activation`
5. `test_missing_late_payment_policy_blocks_activation`
6. `test_forbidden_interest_operation_mapping_rejected_in_mourabaha_config`
7. `test_sharia_approval_and_template_version_required_before_usable_state`
8. `test_mourabaha_accounting_mapping_requirements_must_be_approved`
9. `test_mourabaha_config_read_api_exposes_normalized_policy`
10. `test_config_validation_errors_are_structured_and_actionable`

Proof-by-contradiction acceptance alignment tests:

- `test_interest_formula_rejected`
- `test_missing_allowed_cost_policy_blocks_activation`
- `test_approved_configuration_can_originate_contract`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: generic `rules` blob allows silent omission of required Mourabaha sections.
2. Risk: interest prohibition enforced on create but not update.
3. Risk: approved status may be granted before template/sharia references are valid.

Fixes:

1. strict schema validator with required sections and versioned contract.
2. apply identical prohibition checks on create and update.
3. gate usable-state transition on validated references.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: margin rule may allow effectively interest-like compounding behavior.
2. Risk: mapping compatibility checked at activation only, then drifts later.
3. Risk: late-payment policy can be set to allowed value string but unmapped in accounting.

Fixes:

1. margin-rule validator bans compounding/interest-like calculus classes.
2. add runtime compatibility check before origination/posting.
3. require approved mapping route for every configured late-payment treatment mode.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. IF-060 requires approved config can originate contract; tests may cover activation only.
2. CI may pass schema tests without end-to-end origination proof.
3. Backward compatibility aliases (`murabaha`/`mourabaha`) can cause inconsistent policy lookup.

Fixes:

1. add end-to-end test from approved config to successful contract draft/origination.
2. include integration test covering readiness + origination path.
3. normalize family code at ingress and use canonical family key in policy lookups.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are all required Mourabaha policy dimensions explicitly configurable and validated? Yes.
- Are interest formulas and conventional-interest mappings prohibited? Yes.
- Does missing allowed-cost policy block activation? Yes.
- Are Sharia approval and contract-template version stored and enforced? Yes.
- Can approved configuration successfully originate contract through gated path? Yes.

## Test Execution Instructions

Use these commands during IF-060 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Mourabaha config + readiness/origination gates
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/MourabahaProductConfigurationTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Completed in code:

- Added strict Mourabaha draft/schema enforcement in `app/Application/IslamicFinance/IslamicProductFamilyRegistry.php`:
  - required `rules.mourabaha_configuration`
  - required policy sections:
    - `allowed_asset_categories`
    - `allowed_costs_policy`
    - `margin_rule`
    - `repayment_schedule_rules`
    - `delivery_requirements`
    - `early_settlement_policy`
    - `late_payment_policy`
    - `cancellation_policy`
    - `accounting_mapping_requirements`
    - `sharia_approval_reference`
    - `contract_template_reference`
  - explicit contradiction checks:
    - interest-like semantic tokens rejected
    - compounding rejected in margin and late-payment policies
    - forbidden mapping semantics rejected
    - required Mourabaha operation-code set enforced in config
- Integrated Mourabaha readiness gates in `app/Application/IslamicFinance/IslamicProductReadinessService.php`:
  - `islamic_mourabaha_references`
  - `islamic_mourabaha_mapping_requirements`
  - family activation failures now include `mourabaha_configuration` gate failures.
- Added/updated proof-by-contradiction tests and fixtures in:
  - `tests/Feature/Api/IslamicFinanceTest.php`
  - `tests/Feature/Api/IslamicApprovalWorkflowTest.php`
  - `tests/Feature/Api/IslamicRegulatorySignoffTest.php`
  - `tests/Feature/Api/IslamicShariaAuthorityTest.php`
  - `tests/Feature/Api/IslamicStandardsTest.php`

Adversarial findings during implementation and fixes:

1. Contradiction bug: semantic-token matcher flagged `compounding=false` as forbidden only because of key name.
   - Fix: removed blanket `compound*` token blocking and kept explicit boolean compounding checks.
2. Contradiction test bug: recursive merge did not remove `allowed_costs_policy`, producing false pass.
   - Fix: mutate persisted product rules and remove section before approval to prove activation gate rejection.
3. Fixture drift: one standards readiness fixture lacked Mourabaha config under new IF-060 invariant.
   - Fix: updated fixture payload to include canonical Mourabaha configuration.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
php artisan test --parallel --recreate-databases --filter "IslamicApprovalWorkflowTest|IslamicRegulatorySignoffTest|IslamicShariaAuthorityTest|IslamicStandardsTest"
composer test
```

Verification results:

- `IslamicFinanceTest`: pass (`65 tests`, `1352 assertions`).
- Cross-suite impacted Islamic tests: pass (`71 tests`, `935 assertions`).
- Full suite (`composer test`): pass (`549 tests`, `7789 assertions`).

Re-verification (current state):

- `composer test`: pass (`613 tests`, `9553 assertions`).
