# IF-021 Implementation Plan: Screening Execution Engine

Date: 2026-05-24
Status: implementation plan
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-021
Proof-method: proof by contradiction

## IF-021 Source Requirement

Goal: run screening for customer, supplier, asset, goods, project, contract, and account context.

Proof-by-contradiction invariant: assume a contract activates without screening. Activation must fail because screening result is missing.

Acceptance criteria:

- Execute screening before product approval, contract approval, supplier use, asset acceptance, goods acceptance, project approval, and investment account pool assignment.
- Results include pass, fail, manual review, expired, and not applicable.
- Failed result blocks action and records blocked attempt.
- Manual review routes to compliance case.

Tests:

- Missing screening blocks activation.
- Failed screening records blocked attempt.
- Manual review opens compliance case.

## Architecture Context

Current state already provides IF-020 building blocks:

- `IslamicScreeningPolicyService` evaluates screening and persists `islamic_screening_results`.
- `IslamicProductReadinessService` enforces screening at `product_approval`.
- `IslamicComplianceCaseService` supports blocker types for all required contexts.

Current contradiction gap:

- Manual-review blocker routing in `IslamicScreeningPolicyService` maps only `product_approval` to `product_activation` and all other contexts to `contract_activation`, which is insufficient for supplier/asset/goods/project/account paths.
- No central "screening required before action" gate exists yet for all IF-021 contexts.

## Completion Definition For This Plan

IF-021 is sound only if all are true:

- Every required context has a deterministic pre-action screening gate.
- Missing screening in strict contexts cannot pass silently.
- Result taxonomy (`pass|fail|manual_review|expired|not_applicable`) is enforced consistently by all callers.
- Fail results both block action and write auditable blocked-attempt evidence.
- Manual-review results always route to a compliance case and context-correct blocker type.
- Contract activation cannot proceed without valid screening evidence.

## Phase 1: Canonical Context And Blocker Mapping

Create a dedicated mapping in `IslamicScreeningPolicyService`:

- `product_approval` -> `product_activation`
- `contract_approval` -> `contract_activation`
- `supplier_use` -> `supplier_use`
- `asset_acceptance` -> `asset_acceptance`
- `goods_acceptance` -> `goods_acceptance`
- `project_approval` -> `project_approval`
- `account_pool_assignment` -> `account_pool_assignment`

Rules:

- Unknown context type is rejected (`422`) in API-facing paths.
- Mapping is single-source and reused by all screening callers.

Proof by contradiction:

- Assume supplier screening opens blocker `contract_activation`. Impossible after explicit map + validation.

## Phase 2: Screening Execution Contract

Add a typed execution helper in `IslamicScreeningPolicyService`:

- `evaluateForAction(subjectType, subjectPublicId, contextType, facts, actor, strictPolicy=true)`

Behavior contract:

- strict context with no active policy => `fail` persisted with reason `No active screening policy for strict context.`
- `pass` => caller may continue.
- `fail` => caller must reject action and include blocking evidence.
- `manual_review` => compliance case + context blocker must exist before returning.
- `expired`/`not_applicable` in strict contexts => treated as blocking outcomes by caller.

Proof by contradiction:

- Assume contract approval can ignore `not_applicable` in strict mode. Impossible because strict mode converts missing policy path to fail and caller blocks non-pass outcomes.

## Phase 3: Integrate Mandatory Gates Across IF-021 Contexts

Integrate screening checks before each action:

- product approval: keep existing `IslamicProductReadinessService` gate.
- contract approval: add gate in financing/contract approval path (`IslamicFinancingWorkflow` or contract workflow entrypoint).
- supplier use: gate at supplier binding/use entrypoint.
- asset acceptance: gate at asset acceptance entrypoint.
- goods acceptance: gate at goods intake/acceptance entrypoint.
- project approval: gate at project approval entrypoint.
- investment account pool assignment: gate where account is assigned to investment pool.

Implementation rule:

- all gates must call the same screening service contract and run with strict policy enabled.

Proof by contradiction:

- Assume one context forgets screening. Impossible if every approved action path calls mandatory gate and rejects non-pass outcomes.

## Phase 4: Blocked Attempt Recording

Ensure fail outcomes persist blocked-attempt evidence with:

- subject type/public id
- context type
- matched rules
- block reason
- actor id
- timestamp

If existing `islamic_screening_results` row already captures these fields, enforce them as non-optional for fail rows and assert in tests.

Proof by contradiction:

- Assume failed screening blocks action but leaves no audit trace. Impossible because fail response is only generated from persisted result record.

## Phase 5: Manual Review Routing And Case Reuse

For `manual_review` outcomes:

- open or reuse compliance case with reason `screening_restricted_match`.
- attach context-specific blocker type from Phase 1 mapping.
- keep idempotent behavior under retries/concurrency.

Decision-use rule:

- while blocker remains active, action stays blocked.
- after case decision resolves and blocker released, action can retry and re-screen.

Proof by contradiction:

- Assume manual review does not produce enforceable stop. Impossible because blocker linkage is required before returning manual-review outcome.

## Phase 6: API Validation And Reporting

Update `IslamicScreeningPolicyWorkflow::evaluate` request validation:

- enforce allowed context types list for IF-021.
- enforce allowed subject types for IF-021 domains.

Reporting:

- `GET /api/v1/islamic-screening-results` supports filtering by context and result to audit coverage.

## Phase 7: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Minimum IF-021 tests:

1. `test_missing_screening_blocks_contract_activation`
2. `test_failed_screening_records_blocked_attempt_for_contract_approval`
3. `test_manual_review_opens_compliance_case_with_supplier_use_blocker`
4. `test_manual_review_opens_compliance_case_with_asset_acceptance_blocker`
5. `test_manual_review_opens_compliance_case_with_goods_acceptance_blocker`
6. `test_manual_review_opens_compliance_case_with_project_approval_blocker`
7. `test_manual_review_opens_compliance_case_with_account_pool_assignment_blocker`
8. `test_contract_activation_rejects_when_latest_screening_is_not_pass`
9. `test_evaluate_endpoint_rejects_unknown_context_type`
10. `test_strict_policy_missing_active_policy_persists_fail_result`

## Phase 8: Adversarial Review (Round 1)

Findings:

1. Risk: context-to-blocker collapse causes wrong enforcement domain.
2. Risk: execution may be implemented in API endpoint only, while internal workflows bypass it.
3. Risk: contract activation might rely on stale screening result without recency or context match.

Fixes:

1. canonical context->blocker map with hard validation.
2. mandatory service-level gate called from workflow entrypoints, not only controller.
3. require screening result generated for the same `subject_public_id + context_type` during current action flow, or re-evaluate inline.

## Phase 9: Adversarial Review (Round 2)

Findings:

1. Ambiguity: `expired` may be returned but callers might treat it as informational.
2. Risk: manual review case created, but blocker insertion fails and flow still returns success.
3. Risk: retries create duplicate open cases for same subject/reason.

Fixes:

1. strict contexts block all non-`pass` outcomes.
2. transactional unit for manual-review routing: case and blocker must both succeed or operation fails.
3. preserve uniqueness/idempotency strategy already used in compliance-case open + reload on race.

## Phase 10: Adversarial Review (Round 3)

Findings:

1. IF-021 requires account context specifically at pool assignment; if checked only at account creation, requirement is unproven.
2. IF-021 includes supplier/asset/goods/project "use/acceptance/approval" events; screening at registration time is insufficient.
3. Contradiction with invariant if contract can activate through alternate endpoint lacking gate.

Fixes:

1. place gate exactly at investment account pool assignment command.
2. place gates at operational action endpoints, not onboarding-only writes.
3. audit all activation endpoints and force shared gate helper before state transition.

## Phase 11: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are all IF-021 contexts covered by mandatory pre-action screening gates? Yes.
- Does missing screening in strict contexts block activation/use paths? Yes.
- Are outcome semantics (`pass/fail/manual_review/expired/not_applicable`) consistently enforced? Yes.
- Do fail outcomes persist blocked-attempt evidence? Yes.
- Do manual-review outcomes always create/reuse compliance case and context-correct blocker? Yes.
- Is contract activation impossible without screening evidence? Yes.

## Test Execution Instructions

Use these commands during IF-021 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Islamic finance screening and gating flows
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file when iterating on IF-021 cases
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicFinanceTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
