# IF-072 Implementation Plan: Ijara Wa Iqtina Transfer

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-072
Proof-method: proof by contradiction

## IF-072 Source Requirement

Goal: transfer asset ownership only through approved transfer event.

Proof-by-contradiction invariant: assume asset ownership changes by direct field edit. Mutation must be rejected; transfer must use workflow.

Acceptance criteria:

- Transfer requires completed rental obligations or approved exception.
- Transfer captures residual amount, waiver if approved, transfer document, accounting posting, and customer acceptance.
- Asset status changes to transferred with audit evidence.

Tests:

- Direct transfer mutation rejected.
- Missing transfer evidence rejected.
- Transfer posts configured residual or approved zero-residual mapping.

## Architecture Context

Current state:

- `islamic_financed_assets.ownership_status` exists but currently transitions primarily in financing approval (`pending` -> `owned_by_institution`) via direct update.
- No dedicated Ijara wa Iqtina transfer endpoint/workflow currently enforces residual, waiver, evidence, and customer acceptance checks.
- No explicit guard blocks direct asset ownership status edits from bypassing transfer governance.
- Existing Islamic financing tests cover Murabaha approval ownership update but not transfer-event governance.

Current contradiction gaps:

- Ownership transfer is not constrained to an approved workflow event.
- Rental-obligation completion/exception checks are absent for transfer eligibility.
- Residual accounting and zero-residual approval route are not enforced as transfer-time invariants.

## Completion Definition For This Plan

IF-072 is sound only if all are true:

- Ownership transfer is executable only via dedicated approved transfer workflow.
- Direct ownership mutation paths are blocked/rejected.
- Transfer requires rental-completion or approved exception with evidence.
- Residual amount + accounting posting are validated (or approved zero-residual exception).
- Customer acceptance and transfer document evidence are mandatory and auditable.

## Phase 1: Transfer Domain Workflow Introduction

Create explicit transfer workflow for Ijara wa Iqtina assets:

- transfer request state (`requested`, `under_review`, `approved`, `posted`, `completed`, `rejected`)
- immutable transfer event record keyed by `public_id`
- linkage to financing, asset, product variant, and approval workflow/case

Proof by contradiction:

- Assume IF-072 complete without transfer workflow entity. Impossible because direct row updates remain possible and unverifiable.

## Phase 2: Hard Guard Against Direct Ownership Mutation

Enforce guardrails preventing ownership status changes except through workflow service:

- block direct API update paths for `ownership_status = transferred_to_customer` (or equivalent)
- model-level/service-level invariant checks (write-path gate + transaction assertions)
- detect and reject unauthorized mutation attempts with audit event

Potential implementation patterns:

- dedicated repository/service controlling status transitions
- database constraint/trigger strategy to disallow direct transfer-state mutations without matching transfer event reference

## Phase 3: Eligibility And Evidence Gates

Before transfer approval/posting:

- verify financing contract type/variant is transfer-capable (`ijara_wa_iqtina`)
- verify all rental obligations completed OR approved exception case exists and is active
- require transfer document reference (media/document id)
- require customer acceptance payload (accepted_at, accepted_by, acceptance channel/signature metadata)

Failure of any gate blocks transfer.

## Phase 4: Residual And Waiver Policy Enforcement

Transfer payload must capture:

- `residual_amount_minor`
- `waiver_amount_minor` / waiver reason when applicable
- net transfer settlement amount

Policy checks:

- if residual is non-zero: enforce configured residual mapping route
- if residual is zero: require approved zero-residual exception policy/evidence
- prohibit negative/illogical residual-waiver combinations

## Phase 5: Transfer Accounting Posting

At transfer posting stage:

- post journal entries through approved transfer mappings (residual receivable/cash settlement/asset derecognition as configured)
- enforce IF-050/IF-051 mapping lifecycle checks in-transaction
- block interest-class operation codes for transfer posting

Proof by contradiction:

- Assume transfer succeeds with no residual mapping and no approved zero-residual exception. Contradiction with acceptance criteria.

## Phase 6: Asset State Transition And Audit

On successful posted transfer event:

- update asset status to transferred state (e.g., `transferred_to_customer`)
- store transfer timestamp, actor, event reference, acceptance reference
- preserve append-only transfer event log and audit trail chain

Audit events:

- transfer requested
- transfer rejected (reason)
- direct mutation blocked
- transfer posted
- asset transferred

## Phase 7: API Surface

Introduce dedicated transfer endpoints:

- `POST /api/v1/islamic-financings/{financingPublicId}/assets/{assetPublicId}/transfer-requests`
- `POST /api/v1/islamic-transfer-events/{transferEventPublicId}/approve`
- `POST /api/v1/islamic-transfer-events/{transferEventPublicId}/post`
- `GET /api/v1/islamic-transfer-events/{transferEventPublicId}`

Response payload includes:

- eligibility check result
- residual/waiver snapshot
- accounting journal reference
- customer acceptance evidence references
- resulting asset status

## Phase 8: Concurrency And Idempotency

Protect transfer posting from race conditions:

- lock financing + asset + transfer event rows during approval/posting
- idempotency key for transfer post action
- reject replay and stale-state submissions

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IjaraWaIqtinaTransferTest.php`

Minimum tests:

1. `test_direct_asset_transfer_mutation_is_rejected`
2. `test_transfer_requires_transfer_capable_ijara_variant`
3. `test_transfer_requires_completed_rental_obligations_or_approved_exception`
4. `test_transfer_rejects_missing_transfer_document`
5. `test_transfer_rejects_missing_customer_acceptance`
6. `test_transfer_posts_configured_residual_mapping`
7. `test_transfer_requires_approved_zero_residual_exception_when_residual_is_zero`
8. `test_transfer_success_updates_asset_status_and_writes_audit_chain`

Proof-by-contradiction acceptance alignment tests:

- `test_direct_transfer_mutation_rejected`
- `test_missing_transfer_evidence_rejected`
- `test_transfer_posts_configured_residual_or_approved_zero_residual_mapping`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: adding a transfer endpoint without blocking legacy direct updates leaves bypass path intact.
2. Risk: transfer can be approved before rental obligations are final due to stale reads.
3. Risk: customer acceptance can be nominal boolean without verifiable evidence linkage.

Fixes:

1. enforce write-path invariants and optional DB-level guard tied to transfer event reference.
2. evaluate obligations under row lock in transfer approval/post transaction.
3. require structured acceptance metadata + evidence id and persist immutable snapshot.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: zero residual may be abused to bypass settlement without explicit approval.
2. Risk: waiver amounts may exceed residual or create negative net settlement.
3. Risk: mapping checks can pass preflight but fail effective-date conditions at commit time.

Fixes:

1. require explicit zero-residual exception case + approver identity + reason code.
2. enforce arithmetic invariants (`0 <= waiver <= residual`, `net >= 0`).
3. re-validate mapping status/effective window within same posting transaction.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. Risk: transfer posting and asset state update can diverge on partial failure.
2. Risk: replay of transfer post can duplicate accounting entries.
3. Risk: tests may pass by checking status only, without journal/audit linkage integrity.

Fixes:

1. wrap posting + state transition + audit write in single transaction with rollback guarantees.
2. enforce idempotency and unique source reference constraints for transfer journals.
3. assert event -> journal -> asset state -> audit chain in feature tests.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are direct ownership transfer mutations rejected outside approved workflow? Yes.
- Does transfer require completed obligations or approved exception plus evidence? Yes.
- Are residual/waiver/zero-residual policies enforced and auditable? Yes.
- Does transfer posting use approved mappings and produce accounting references? Yes.
- Does successful transfer set asset status to transferred with full audit evidence? Yes.

## Test Execution Instructions

Use these commands during IF-072 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Ijara wa Iqtina transfer changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IjaraWaIqtinaTransferTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume asset ownership transfer can be applied by direct status mutation.
   - Contradiction: direct transition to `transferred` for `ijara_wa_iqtina` is rejected and audited; transfer must use dedicated workflow endpoints.
2. Assume transfer can be requested without completed rental obligations or approved exception.
   - Contradiction: transfer request gate enforces obligations completion or explicit approved exception evidence.
3. Assume transfer posting can proceed without residual/zero-residual policy handling and mapping.
   - Contradiction: posting enforces residual arithmetic and approved mapping resolution (`ijara_residual_transfer` or approved zero-residual path).

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if070_|test_if071_|test_if072_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `composer test` passed: `OK (623 tests, 9816 assertions)`.
