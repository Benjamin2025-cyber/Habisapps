# IF-110 Implementation Plan: Moucharaka Product Configuration

Date: 2026-05-24
Status: implementation plan
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-110
Proof-method: proof by contradiction

## IF-110 Source Requirement

Goal: configure partnership products with capital participation.

Proof-by-contradiction invariant: assume loss-sharing follows profit ratio where capital ratio differs. Configuration or posting must fail unless approved exception exists.

Acceptance criteria:

- Configure contribution rules, profit ratio rules, loss ratio rules, governance rights, reporting cadence, reserve policy, additional capital, impairment, buyout, valuation, exit, and mappings.
- Store whether diminishing partnership is enabled; if enabled, require its own transfer and valuation rules.

Tests:

- Loss by profit ratio rejected without approved exception.
- Missing valuation policy blocks buyout-enabled activation.
- Missing contribution evidence policy blocks activation.

## Architecture Context

Current state:

- `IslamicProductWorkflow::storeProduct` still allows only `murabaha` contract type, so Moucharaka products cannot be created via current write path.
- `IslamicProductReadinessService` maps `moucharaka`, but no Moucharaka-specific readiness gates enforce contribution evidence, valuation policy, and loss-ratio constraints.
- No first-class policy schema currently models diminishing partnership transfer/valuation requirements.
- Existing posting and financing logic is Murabaha-centric; no Moucharaka ratio-governance checks are integrated.

Current contradiction gaps:

- Moucharaka family recognition is not backed by operational product configuration support.
- Loss allocation safeguards (capital ratio vs profit ratio) are not explicitly enforceable at product level.
- Buyout/diminishing-partnership prerequisites are not mandatory activation gates.

## Completion Definition For This Plan

IF-110 is sound only if all are true:

- Moucharaka products are configurable with strict policy schema.
- Loss-by-profit-ratio is rejected unless approved exception exists.
- Buyout-enabled products require valuation policy.
- Contribution evidence policy is mandatory.
- Diminishing partnership mode requires dedicated transfer and valuation rules.

## Phase 1: Contract-Type Enablement

Enable `contract_type = moucharaka` in product write validations and canonical family normalization.

Proof by contradiction:

- Assume IF-110 complete while write path rejects Moucharaka. Impossible because no compliant Moucharaka product can be persisted.

## Phase 2: Moucharaka Policy Schema

Introduce strict policy blocks:

- `contribution_rules`
- `contribution_evidence_policy`
- `profit_ratio_rules`
- `loss_ratio_rules`
- `governance_rights_policy`
- `reporting_cadence_policy`
- `reserve_policy`
- `additional_capital_policy`
- `impairment_policy`
- `buyout_policy`
- `valuation_policy`
- `exit_policy`
- `accounting_mapping_profile`
- `diminishing_partnership` (boolean)
- `diminishing_transfer_rules` (required if diminishing enabled)
- `diminishing_valuation_rules` (required if diminishing enabled)

Reject unknown/unsafe keys and inconsistent configurations.

## Phase 3: Loss-Ratio Constraint Engine

Enforce core rule:

- default loss allocation must follow capital ratio
- if loss ratio follows profit ratio (or another exception), require explicit approved exception policy reference and evidence

Proof by contradiction:

- Assume loss-by-profit-ratio passes without exception. Contradiction with IF-110 invariant.

## Phase 4: Activation Readiness Gates

Activation checks:

- missing contribution evidence policy => fail
- buyout enabled + missing valuation policy => fail
- diminishing enabled + missing transfer/valuation rules => fail
- invalid loss-ratio exception linkage => fail
- missing mapping profile => fail

## Phase 5: Template, Screening, Mapping Bindings

Require before activation:

- approved Moucharaka contract template binding (`moucharaka_contract_template`)
- approved active screening policy linkage for scope
- approved/effective mapping profile (non-interest-class)

## Phase 6: Diminishing Partnership Mode Guardrails

When diminishing mode enabled:

- require transfer cadence/eligibility terms
- require valuation method/version and evidence requirements
- require governance controls for ownership-share transition

## Phase 7: API Payload And Audit

Payload additions:

- normalized Moucharaka rules
- loss-ratio exception metadata
- diminishing mode details
- template/screening/mapping bindings

Audit events:

- `islamic.product.moucharaka_configured`
- `islamic.product.moucharaka_activation_blocked_loss_ratio_exception_missing`
- `islamic.product.moucharaka_activation_blocked_missing_valuation_policy`
- `islamic.product.moucharaka_activation_blocked_missing_contribution_evidence_policy`
- `islamic.product.moucharaka_activation_blocked_missing_diminishing_rules`

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/MoucharakaProductConfigurationTest.php`

Minimum tests:

1. `test_moucharaka_product_can_be_created_with_required_rules`
2. `test_loss_by_profit_ratio_rejected_without_approved_exception`
3. `test_loss_by_profit_ratio_allowed_with_approved_exception`
4. `test_buyout_enabled_missing_valuation_policy_blocks_activation`
5. `test_missing_contribution_evidence_policy_blocks_activation`
6. `test_diminishing_mode_requires_transfer_and_valuation_rules`
7. `test_moucharaka_requires_template_screening_and_mapping_bindings`
8. `test_moucharaka_payload_exposes_loss_and_diminishing_policy_details`

Proof-by-contradiction acceptance alignment tests:

- `test_loss_by_profit_ratio_rejected_without_exception`
- `test_missing_valuation_policy_blocks_buyout_enabled_activation`
- `test_missing_contribution_evidence_policy_blocks_activation`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: adding `moucharaka` type without strict schema enables invalid ratio definitions.
2. Risk: loss ratio exception may be declared but not formally approved.
3. Risk: buyout policy may exist while valuation method is effectively undefined.

Fixes:

1. enforce typed rule schema with ratio consistency checks.
2. require approved exception reference + evidence artifact.
3. require explicit valuation method/version and policy status checks.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: contribution evidence policy may be too generic to prove both-party contributions.
2. Risk: diminishing mode rules may be present but incompatible with valuation policy.
3. Risk: mapping checks may ignore effective windows at activation commit.

Fixes:

1. require party-scoped contribution evidence requirements.
2. validate cross-policy consistency between diminishing transfer and valuation rules.
3. re-check mapping active/effective status in activation transaction.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. Risk: downstream partnership workflow may bypass product-level ratio restrictions.
2. Risk: rule updates after approval can weaken audit defensibility.
3. Risk: tests may verify rejection but not precise blocker category.

Fixes:

1. enforce Moucharaka guardrails at activation and workflow origination entrypoints.
2. persist immutable approved-rule snapshot hash.
3. assert structured error keys/messages for ratio/valuation/contribution blockers.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Can Moucharaka products be configured through strict schema-backed policies? Yes.
- Is loss-by-profit-ratio blocked unless an approved exception exists? Yes.
- Does missing valuation policy block buyout-enabled activation? Yes.
- Does missing contribution evidence policy block activation? Yes.
- Does diminishing mode require dedicated transfer and valuation rules? Yes.

## Test Execution Instructions

Use these commands during IF-110 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Moucharaka product configuration changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/MoucharakaProductConfigurationTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
