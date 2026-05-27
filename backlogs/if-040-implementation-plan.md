# IF-040 Implementation Plan: Financed Asset Registry

Date: 2026-05-24
Status: implemented and verified
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-040
Proof-method: proof by contradiction

## IF-040 Source Requirement

Goal: track assets for Mourabaha and Ijara workflows.

Proof-by-contradiction invariant: assume an asset-backed contract activates without asset evidence. Activation must fail.

Acceptance criteria:

- Store asset category, description, supplier, acquisition cost, ownership/control status, condition, documents, location, customer request, screening result, and status.
- Statuses include requested, quoted, purchased, controlled, delivered, leased, transferred, returned, impaired, disposed, and cancelled.
- Asset status transitions are audited and product-aware.

Tests:

- Mourabaha approval requires purchased or controlled asset as configured.
- Ijara activation requires owned or controlled leased asset.
- Asset status transition without evidence is rejected.

## Architecture Context

Current implementation baseline:

- `islamic_financed_assets` exists and is linked to `islamic_financings`.
- `storeFinancingAsset` captures basic fields and defaults `ownership_status` to `pending`.
- `approveFinancing` currently checks only asset count > 0 and then force-updates ownership to `owned_by_institution`.

Current contradiction gaps:

- Required IF-040 status taxonomy is not modeled/enforced.
- No state-machine rules for evidence-backed transitions.
- No product-aware asset gate at approval/activation beyond mere existence.
- Missing fields for location, condition lifecycle, screening result linkage, and customer request traceability in canonical way.

## Completion Definition For This Plan

IF-040 is sound only if all are true:

- Asset registry captures all required IF-040 fields.
- Asset lifecycle statuses are explicit and enforced.
- Transition rules require appropriate evidence and are auditable.
- Financing/product activation gates validate product-aware asset readiness.
- Invalid transitions or missing evidence are rejected deterministically.

## Phase 1: Canonical Asset Registry Model

Add/extend asset model and migration(s):

- `islamic_financed_assets` enhancements and optional `islamic_financed_asset_transitions` audit table.

Required fields:

- `asset_category`
- `description`
- `supplier_name` / supplier reference
- `acquisition_cost_minor`
- `ownership_or_control_status`
- `condition_status`
- `document_bundle` (references)
- `location`
- `customer_request_ref`
- `screening_result_public_id`
- `status`

Status enum:

- `requested`
- `quoted`
- `purchased`
- `controlled`
- `delivered`
- `leased`
- `transferred`
- `returned`
- `impaired`
- `disposed`
- `cancelled`

Proof by contradiction:

- Assume asset is tracked but cannot distinguish quoted vs purchased. Impossible with explicit status enum and constraints.

## Phase 2: Asset State Machine And Transition Rules

Implement `IslamicFinancedAssetStateMachine` + service:

- `transition(assetPublicId, toStatus, context, actor, evidence)`

Transition policy:

- only allowed from configured prior states.
- transition requires evidence set per target status.
- reject unknown/unreachable transitions.

Examples:

- `quoted -> purchased`: requires purchase evidence document + supplier pricing reference.
- `purchased -> controlled`: requires ownership/control evidence.
- `controlled -> delivered` (mourabaha): requires delivery evidence.
- `controlled -> leased` (ijara): requires lease commencement evidence.

Proof by contradiction:

- Assume status can move to `controlled` without title/control evidence. Impossible because transition validator blocks missing evidence.

## Phase 3: Product-Aware Activation Gates

Integrate asset gate into financing approval/activation:

- For `mourabaha`: require at least one eligible asset in `purchased` or `controlled` (configurable strictness).
- For `ijara`: require at least one eligible asset in owned/controlled + leasing readiness state (e.g., `controlled` then `leased` at activation boundary).

Update `approveFinancing`:

- replace count-based check with policy check using asset statuses/evidence.
- do not mass-force ownership state; ownership changes must come through asset transitions.

Proof by contradiction:

- Assume financing approves with only `requested` assets. Impossible due to product-aware gate enforcement.

## Phase 4: Screening And Compliance Integration

Before acceptance transitions (`purchased`, `controlled`, `leased` as needed):

- run screening in `asset_acceptance` context (IF-021 integration).
- `fail` blocks transition and records blocked attempt.
- `manual_review` opens compliance case and blocker.

Store screening reference on asset:

- latest relevant screening result public id
- optional historical linkage table for multiple evaluations.

## Phase 5: API Surface For Asset Lifecycle

Add endpoints:

- `POST /api/v1/islamic-financings/{financingPublicId}/assets` (enhanced payload)
- `GET /api/v1/islamic-financings/{financingPublicId}/assets`
- `GET /api/v1/islamic-financed-assets/{assetPublicId}`
- `POST /api/v1/islamic-financed-assets/{assetPublicId}/transition`
- `GET /api/v1/islamic-financed-assets/{assetPublicId}/timeline`

Transition response includes:

- from/to status
- evidence refs accepted
- transition audit id/public id
- blocking/compliance info when applicable

## Phase 6: Audit Trail

Create explicit transition audit records:

- actor
- timestamp
- from/to
- reason
- evidence ids
- product/family context
- screening/compliance references

Security events:

- `islamic.asset.created`
- `islamic.asset.transitioned`
- `islamic.asset.transition_blocked`
- `islamic.asset.acceptance_screening_blocked`

Proof by contradiction:

- Assume unauthorized or invalid transition happens with no trace. Impossible due to mandatory transition audit insertion.

## Phase 7: Data Integrity Constraints

DB constraints/checks:

- valid status enum values.
- status/evidence consistency checks where feasible.
- prevent deletion when asset referenced by approved financing/journal postings; use soft lifecycle (`disposed/cancelled`) instead.

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicFinancedAssetRegistryTest.php`

Minimum tests:

1. `test_mourabaha_approval_requires_purchased_or_controlled_asset`
2. `test_ijara_activation_requires_owned_or_controlled_leased_asset`
3. `test_asset_transition_without_required_evidence_is_rejected`
4. `test_valid_asset_transition_persists_audit_timeline`
5. `test_requested_asset_cannot_jump_directly_to_leased`
6. `test_asset_acceptance_screening_fail_blocks_transition`
7. `test_asset_acceptance_manual_review_opens_compliance_case`
8. `test_approve_financing_does_not_force_asset_ownership_without_transition`
9. `test_asset_registry_stores_required_if040_fields`
10. `test_disposed_or_cancelled_assets_are_not_eligible_for_activation_gate`

Proof-by-contradiction acceptance alignment tests:

- `test_mourabaha_approval_requires_purchased_or_controlled_asset`
- `test_ijara_activation_requires_owned_or_controlled_leased_asset`
- `test_asset_status_transition_without_evidence_is_rejected`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: status enum introduced but transitions remain direct DB updates.
2. Risk: approval gate validates status but not evidence provenance.
3. Risk: legacy forced ownership update in approval path bypasses lifecycle.

Fixes:

1. make transition endpoint/service the only write path for status changes.
2. require evidence references and validation per transition.
3. remove forced ownership update; replace with validated transitions.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: product-aware rule may be hardcoded to murabaha only.
2. Risk: screening checks run at create-time only, not at acceptance transitions.
3. Risk: concurrent transitions can produce invalid state races.

Fixes:

1. central product-family policy resolver for asset gate requirements.
2. enforce screening at transition points that imply acceptance/control use.
3. lock asset row during transition and revalidate current state before commit.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. IF-040 requires both ownership/control status and asset lifecycle status; conflating them loses semantics.
2. Evidence documents may exist but not be linked to specific transition context.
3. CI can pass path tests without proving timeline immutability.

Fixes:

1. keep separate fields for ownership/control and lifecycle status.
2. transition audit stores explicit evidence linkage and reason codes.
3. add tests ensuring historical transition records are append-only and immutable.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are all IF-040 asset fields captured and queryable? Yes.
- Are lifecycle statuses explicit and transition-validated? Yes.
- Are status transitions evidence-gated and audited? Yes.
- Are Mourabaha/Ijara activation gates product-aware and enforced? Yes.
- Are screening and compliance blockers integrated at asset acceptance points? Yes.

## Test Execution Instructions

Use these commands during IF-040 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for asset-registry and financing-approval changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicFinancedAssetRegistryTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume non-Mourabaha financings still require Murabaha posting/mappings/installments at approval time.
   - Contradiction: approval flow now branches by product family; Murabaha-only posting chain runs only for `mourabaha`.
2. Assume IF-040 gating is bypassed during financing approval.
   - Contradiction: `mourabaha`/`ijara` families still require eligible asset lifecycle states through activation-gate checks.

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
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

- Passed: `OK (34 tests, 712 assertions)` for `test_if040_|test_if041_|test_if042_`.
- Passed: `OK (139 tests, 3417 assertions)` for `IslamicFinanceTest`.
- Passed: `OK (626 tests, 9918 assertions)` for `composer test`.
