# IF-031 Implementation Plan: Product Readiness Checklist

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-031
Proof-method: proof by contradiction

## IF-031 Source Requirement

Goal: block product activation until governance, accounting, contracts, evidence, screening, and reports are complete.

Proof-by-contradiction invariant: assume a product activates without accounting mapping. Activation must fail on readiness check.

Acceptance criteria:

- Checklist includes standards baseline, legal sign-off, Sharia approval, contract template, accounting mappings, screening policy, document requirements, report category, authorization rules, and operational procedure.
- Checklist is product-family specific.
- Readiness result explains missing items.
- Activation stores readiness snapshot.

Tests:

- Missing template blocks activation.
- Missing mapping blocks activation.
- Completed checklist allows activation.

## Architecture Context

Current implementation already provides partial readiness checks:

- `IslamicProductReadinessService` checks standards baseline, regulatory sign-off, and screening.
- `IslamicProductWorkflow::reviewCompliance` blocks approval when readiness failures exist.
- Security audit event `islamic.product.readiness_blocked` already exists.

Current contradiction gaps:

- No canonical, explicit checklist model containing all IF-031 checklist dimensions.
- No persisted readiness snapshot on activation/approval decision.
- Missing gates for contract template, accounting mappings completeness, evidence/documents, report category, authorization rules, and operational procedure.
- Limited product-family-specific checklist shaping.

## Completion Definition For This Plan

IF-031 is sound only if all are true:

- Readiness checklist is explicit, complete, and family-specific.
- Every checklist gate has deterministic pass/fail logic.
- Activation is impossible when any required gate fails.
- Failure response explains missing items by gate and detail.
- Successful activation stores immutable readiness snapshot tied to product approval event.

## Phase 1: Canonical Readiness Checklist Schema

Create checklist schema artifact (DB-backed template or code-first registry sourced from IF-030 catalog profile):

Checklist dimensions (required):

- `standards_baseline`
- `legal_signoff`
- `sharia_approval`
- `contract_template`
- `accounting_mappings`
- `screening_policy`
- `document_requirements`
- `report_category`
- `authorization_rules`
- `operational_procedure`

Each dimension includes:

- `required` (bool)
- `evidence_type`/validation hints
- `blocking_mode` (`hard` default)
- `family_overrides`

Proof by contradiction:

- Assume readiness passes while contract template is missing. Impossible because template dimension is explicit and hard-blocking.

## Phase 2: Readiness Evaluation Engine

Refactor `IslamicProductReadinessService` into gate engine returning structured output:

Return shape:

- `overall_status` (`pass`|`fail`)
- `family_code`
- `evaluated_at`
- `gates`: list of gate results
- `failures_by_gate`
- `missing_items`

Each gate result:

- `gate_key`
- `status`
- `reasons`
- `evidence_refs`

Implementation rule:

- replace implicit ad-hoc checks with one gate-per-dimension evaluation pipeline.

Proof by contradiction:

- Assume readiness response only says "failed" without explanation. Impossible because gate contract requires per-gate reasons.

## Phase 3: Implement Missing Gate Checks

Add deterministic checks for currently missing IF-031 dimensions:

- `contract_template`: active approved family template exists (IF-032 dependency seam).
- `accounting_mappings`: required operation mappings for family profile are present and approved/usable.
- `document_requirements`: required document evidence exists and is valid.
- `report_category`: product has valid reporting category bound from family profile.
- `authorization_rules`: required maker/checker and authority scope rules present.
- `operational_procedure`: procedure reference/version attached and active.
- `legal_signoff`: legal approval artifact present and active.
- `sharia_approval`: reusable approval workflow state usable + authority mandate passes.

Retain and normalize existing checks:

- standards baseline,
- regulatory signoff,
- screening policy.

## Phase 4: Product-Family-Specific Checklist Resolution

Read checklist template from IF-030 family catalog profile:

- family can require different mappings, document sets, procedures, and authorization controls.
- family with inapplicable gate marks gate `not_applicable` and must not block.

Proof by contradiction:

- Assume all families share identical checklist. Impossible because gate resolver loads per-family template and requirement sets.

## Phase 5: Activation Snapshot Persistence

Create readiness snapshot persistence:

- table: `islamic_product_readiness_snapshots` (or field in product approval workflow snapshot payload)
- store immutable snapshot when readiness passes and approval decision proceeds:
  - product public id
  - family code
  - checklist template version
  - gate results + evidence refs
  - snapshot hash/checksum
  - actor and timestamp

Enforcement:

- product status cannot transition to approved without successful snapshot write in same transaction.

Proof by contradiction:

- Assume product approved with no readiness snapshot. Impossible because status transition and snapshot persist atomically.

## Phase 6: API Surface For Readiness Transparency

Add endpoints:

- `GET /api/v1/islamic-products/{productPublicId}/readiness`
- optional `GET /api/v1/islamic-products/{productPublicId}/readiness-snapshots`

Response requirements:

- current readiness status
- gate-by-gate outcomes
- missing items list
- latest snapshot metadata when approved

## Phase 7: Integration Into Approval/Activation Flow

Wire readiness gate into product approval path as hard blocker:

- `IslamicProductWorkflow::reviewCompliance` must use structured readiness result.
- if any blocking gate fails, return `422` with `failures_by_gate`.
- on pass, persist readiness snapshot then continue approval transition.

Add TOCTOU protection:

- lock product row and relevant approval rows during final gate + snapshot + status transition.

## Phase 8: Audit Events

Record:

- `islamic.product.readiness.evaluated`
- `islamic.product.readiness.blocked`
- `islamic.product.readiness.snapshot_stored`
- `islamic.product.readiness.approved`

Audit payload includes:

- product public id
- family code
- failed gate keys
- snapshot public id/hash

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicProductReadinessChecklistTest.php`

Minimum tests:

1. `test_missing_contract_template_blocks_product_activation`
2. `test_missing_accounting_mapping_blocks_product_activation`
3. `test_missing_document_requirements_block_product_activation`
4. `test_missing_operational_procedure_blocks_product_activation`
5. `test_readiness_result_returns_gate_level_failures`
6. `test_completed_family_specific_checklist_allows_activation`
7. `test_activation_persists_immutable_readiness_snapshot`
8. `test_readiness_endpoint_exposes_current_status_and_missing_items`
9. `test_not_applicable_family_gate_does_not_block_activation`
10. `test_concurrent_activation_cannot_bypass_snapshot_persistence`

Proof-by-contradiction acceptance alignment tests:

- `test_missing_template_blocks_activation`
- `test_missing_mapping_blocks_activation`
- `test_completed_checklist_allows_activation`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: checklist keys exist but not all are enforced as hard blockers.
2. Risk: readiness snapshot stored after status change can fail and leave approved-without-snapshot state.
3. Risk: family-specific gates may silently fallback to generic defaults.

Fixes:

1. explicit blocking-mode semantics per gate with defaults to hard.
2. transactional atomicity: gate pass + snapshot write + status transition.
3. require family template resolution; missing template is blocking failure.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: IF-032 not fully available yet could cause permanent template-gate failures.
2. Risk: stale readiness results reused after checklist-relevant data changes.
3. Risk: screening/manual-review case created but readiness still marked pass.

Fixes:

1. define temporary reserved-template compatibility mode with explicit blocking policy and sunset flag.
2. evaluate readiness fresh at approval time; no cached pass reuse without data-version match.
3. treat non-pass screening outcomes (`fail`, `manual_review`, `expired`) as readiness fail.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. IF-031 requires explanation of missing items; flat error arrays may be insufficiently specific.
2. Product-family expansion (IF-030) can introduce new required gates not reflected in hardcoded evaluator.
3. CI can pass unit checks without verifying approved-status snapshot invariant.

Fixes:

1. enforce structured `failures_by_gate` + `missing_items` response contract.
2. derive required gates dynamically from family profile rather than static list in service.
3. add integration test that asserts approval transaction fails if snapshot insert fails.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Does checklist cover every IF-031 required dimension? Yes.
- Is checklist resolved and enforced per product family? Yes.
- Are readiness failures explained per gate with missing-item detail? Yes.
- Is activation blocked for any unresolved required gate? Yes.
- Is readiness snapshot persisted atomically with approval transition? Yes.

## Test Execution Instructions

Use these commands during IF-031 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for readiness-checklist and product-approval changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicProductReadinessChecklistTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Proof-by-contradiction adversarial review findings and fixes:

1. Contradiction: IF-031 phase-8 requires explicit readiness lifecycle audit events (`evaluated`, `blocked`, `snapshot_stored`, `approved`), but implementation only emitted legacy `islamic.product.readiness_blocked`.
   - Fixes in `IslamicProductWorkflow`:
     - emit `islamic.product.readiness.evaluated` at readiness evaluation time.
     - emit `islamic.product.readiness.blocked` on readiness gate failure (while keeping legacy `islamic.product.readiness_blocked` for compatibility).
     - emit `islamic.product.readiness.snapshot_stored` when readiness snapshot is inserted.
     - emit `islamic.product.readiness.approved` after successful readiness-backed approval.

2. Contradiction: IF-031 and IF-030 must share family taxonomy source-of-truth in readiness-adjacent link validators.
   - Fixes:
     - `IslamicStandardWorkflow` and `IslamicRegulatorySignoffWorkflow` now validate `product_family`/`account_type` link codes through `IslamicProductFamilyRegistry` (`family_kind` checks), removing static-array drift.

3. Added/updated contradiction tests:
   - `test_missing_contract_template_blocks_product_activation`
     - now asserts `islamic.product.readiness.blocked` audit event.
   - `test_product_approval_persists_readiness_snapshot_and_exposes_it`
     - now asserts `islamic.product.readiness.evaluated`, `islamic.product.readiness.snapshot_stored`, and `islamic.product.readiness.approved`.
   - `test_family_registry_prevents_product_family_account_type_drift_in_links`
     - proves account family codes are rejected for `product_family` links and accepted for `account_type` links.

Verification commands and results:

```bash
php artisan test --parallel --recreate-databases --filter "(missing_contract_template_blocks_product_activation|product_approval_persists_readiness_snapshot_and_exposes_it|product_readiness_endpoint_exposes_gate_level_failures)"
```

- Result: `OK (3 tests, 79 assertions)`

```bash
php artisan test --parallel --recreate-databases --filter "(family_registry_prevents_product_family_account_type_drift_in_links|unknown_product_family_is_rejected_on_product_creation|product_family_metadata_is_exposed_via_catalog_api|required_fields_differ_by_family_metadata|financing_creation_rejects_account_family_kind)"
```

- Result: `OK (5 tests, 67 assertions)`

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
```

- Result: `OK (81 tests, 2124 assertions)`

```bash
composer test
```

- Result: `OK (568 tests, 8625 assertions)`
