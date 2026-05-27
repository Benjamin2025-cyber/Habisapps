# IF-041 Implementation Plan: Salam Goods Registry

Date: 2026-05-24
Status: implemented and verified
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-041
Proof-method: proof by contradiction

## IF-041 Source Requirement

Goal: track specified goods for Salam contracts.

Proof-by-contradiction invariant: assume goods are described vaguely. Contract approval must fail until required specification fields are complete.

Acceptance criteria:

- Store goods category, quality, quantity, unit, delivery date, delivery place, counterparty, inspection requirements, and acceptance rules.
- Support partial delivery, substitution request, rejection, and non-delivery states.
- Link delivery evidence to inventory or settlement treatment.

Tests:

- Missing quantity blocks approval.
- Missing delivery date blocks approval.
- Partial delivery opens settlement workflow.

## Architecture Context

Current state:

- Screening and compliance already have `islamic_goods` subject and `goods_acceptance` blocker context.
- No first-class Salam goods registry/lifecycle is implemented yet.
- No approval gate currently enforces detailed Salam goods specifications.

Primary contradiction gap:

- Salam contracts can be created without a structured goods registry proving specification completeness and delivery-state evidence.

## Completion Definition For This Plan

IF-041 is sound only if all are true:

- Salam goods specs are stored with required fields.
- Contract approval cannot proceed when required specification fields are missing.
- Goods lifecycle supports partial delivery, substitution request, rejection, and non-delivery.
- Delivery evidence links to inventory/settlement handling.

## Phase 1: Salam Goods Data Model

Create migration:

- `create_islamic_salam_goods_tables`

Tables:

- `islamic_salam_goods`
- `islamic_salam_goods_deliveries`
- optional `islamic_salam_goods_transitions`

`islamic_salam_goods` fields:

- public id
- financing/contract reference
- goods category
- quality spec
- quantity
- unit
- delivery date
- delivery place
- counterparty reference
- inspection requirements
- acceptance rules
- status
- metadata

Status enum:

- `specified`
- `partially_delivered`
- `delivered`
- `substitution_requested`
- `rejected`
- `non_delivery`
- `settled`
- `cancelled`

## Phase 2: Validation And Contract Gate

Enforce Salam-specific specification validation before approval:

- quantity required and > 0
- unit required
- delivery date required
- delivery place required
- quality spec required

Integrate gate in Salam approval path:

- missing required goods spec fields => hard block with explicit reasons.

Proof by contradiction:

- Assume approval passes without quantity. Impossible because gate rejects contract.

## Phase 3: Lifecycle State Machine

Implement `SalamGoodsStateMachine`:

- controlled transitions only
- evidence requirements per transition

Required support:

- partial delivery
- substitution request
- rejection
- non-delivery

Transition examples:

- `specified -> partially_delivered` requires delivery evidence.
- `partially_delivered -> delivered` requires final delivery evidence.
- `specified/partially_delivered -> substitution_requested` requires substitution rationale.
- `specified/partially_delivered -> non_delivery` requires breach/non-delivery evidence.

## Phase 4: Evidence Link To Inventory/Settlement

For each delivery event:

- capture delivery evidence document(s)
- map to inventory intake or settlement action reference

Add explicit linkage fields:

- `inventory_reference`
- `settlement_reference`

Proof by contradiction:

- Assume delivery is recorded without downstream accounting/settlement trace. Impossible with mandatory linkage policy.

## Phase 5: Screening/Compliance Integration

Before acceptance transitions (`partially_delivered`, `delivered`):

- evaluate screening in `goods_acceptance` context.
- `fail` blocks transition.
- `manual_review` opens compliance case/blocker.

## Phase 6: API Surface

Endpoints:

- `POST /api/v1/islamic-salam-goods`
- `GET /api/v1/islamic-salam-goods/{goodsPublicId}`
- `POST /api/v1/islamic-salam-goods/{goodsPublicId}/transition`
- `POST /api/v1/islamic-salam-goods/{goodsPublicId}/deliveries`
- `GET /api/v1/islamic-salam-goods/{goodsPublicId}/timeline`

## Phase 7: Audit Trail

Record events:

- `islamic.salam_goods.created`
- `islamic.salam_goods.transitioned`
- `islamic.salam_goods.transition_blocked`
- `islamic.salam_goods.delivery_recorded`

Audit payload includes status changes, evidence references, and settlement/inventory linkage.

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicSalamGoodsRegistryTest.php`

Minimum tests:

1. `test_missing_quantity_blocks_salam_approval`
2. `test_missing_delivery_date_blocks_salam_approval`
3. `test_partial_delivery_opens_settlement_workflow`
4. `test_substitution_request_transition_requires_reason`
5. `test_non_delivery_transition_requires_evidence`
6. `test_goods_acceptance_screening_fail_blocks_delivery_transition`
7. `test_goods_acceptance_manual_review_opens_compliance_case`
8. `test_delivery_evidence_links_to_inventory_or_settlement_reference`
9. `test_invalid_status_transition_is_rejected`
10. `test_timeline_is_append_only_for_goods_transitions`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: specs stored but not enforced at approval gate.
2. Risk: lifecycle statuses exist but transitions bypass evidence validation.
3. Risk: partial delivery recorded without settlement consequences.

Fixes:

1. hard-block gate in Salam approval path.
2. state machine is only allowed write path for status changes.
3. require settlement/inventory linkage on delivery records.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: substitution and rejection may conflict without precedence rules.
2. Risk: screening run only at initial spec creation, not acceptance transitions.
3. Risk: concurrent updates can corrupt delivered quantity tracking.

Fixes:

1. explicit transition precedence and terminal-state rules.
2. enforce screening at acceptance transitions.
3. row-level locking and quantity invariants in transition transaction.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. IF-041 requires vague-description prevention; weak quality schema allows ambiguity.
2. CI may pass field presence checks without proving lifecycle behavior.
3. Missing settlement linkage can hide operational debt despite passing status transitions.

Fixes:

1. structured quality schema with required keys per category.
2. add end-to-end lifecycle tests through partial delivery to settlement.
3. require settlement/inventory reference consistency checks.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are all required Salam goods specification fields captured and validated? Yes.
- Does missing quantity or delivery date block approval? Yes.
- Are partial delivery, substitution, rejection, and non-delivery fully modeled? Yes.
- Is delivery evidence linked to inventory or settlement treatment? Yes.
- Are acceptance transitions screened and compliance-blocked when required? Yes.

## Test Execution Instructions

Use these commands during IF-041 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Salam goods registry and approval gates
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicSalamGoodsRegistryTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume delivery-state transitions can bypass inventory/settlement linkage by calling the generic transition endpoint.
   - Contradiction: transitions into `partially_delivered`/`delivered` are blocked on generic endpoint; delivery lifecycle must go through `/deliveries`.
2. Assume Salam delivery lifecycle can advance without quantitative delivery controls.
   - Contradiction: delivery endpoint enforces quantity ceilings, evidence, and inventory/settlement reference requirements.
3. Assume Salam financing approval can proceed even when linked goods are already in terminal/breach states.
   - Contradiction: `assertGoodsReadyForApproval` now blocks approval when linked goods are `non_delivery`, `rejected`, `settled`, or `cancelled` (new contradiction test added).

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if040_|test_if041_|test_if042_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `composer test` passed: `OK (626 tests, 9918 assertions)`.

Re-validation (2026-05-25):

```bash
php artisan test --parallel --recreate-databases --filter 'test_if040_|test_if041_|test_if042_'
php artisan test --parallel --recreate-databases --filter 'test_if041_salam_financing_approval_rejects_terminal_or_breached_goods_statuses'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

- Passed: `OK (34 tests, 712 assertions)` for `test_if040_|test_if041_|test_if042_`.
- Passed: `OK (3 tests, 102 assertions)` for the new IF-041/042 contradiction hardening tests.
- Passed: `OK (139 tests, 3417 assertions)` for `IslamicFinanceTest`.
- Passed: `OK (626 tests, 9918 assertions)` for `composer test`.
