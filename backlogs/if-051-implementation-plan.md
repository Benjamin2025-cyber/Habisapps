# IF-051 Implementation Plan: Approved Mapping Workflow

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-051
Proof-method: proof by contradiction

## IF-051 Source Requirement

Goal: require accounting and Sharia approval for mappings that move money.

Proof-by-contradiction invariant: assume money posts through an unapproved mapping. Posting must fail.

Acceptance criteria:

- Mapping records operation code, debit account, credit account, effective dates, currency, agency scope, approval status, accounting owner, and Sharia approval where required.
- Mapping validation runs before every posting.
- Expired mappings block new postings.

Tests:

- Draft mapping blocks posting.
- Expired mapping blocks posting.
- Approved mapping posts expected journal lines.

## Architecture Context

Current state:

- `operation_account_mappings` exists and posting paths query `status='active'` plus currency filters.
- Mapping checks are implemented ad hoc in multiple workflows (Islamic financing, loans, insurance, reporting gate).
- No unified Islamic mapping approval workflow enforcing accounting+Sharia governance across all posting entrypoints.

Primary contradiction gap:

- A mapping can appear technically active for lookup without proving required approval governance and effective-window validity under a shared policy contract.

## Completion Definition For This Plan

IF-051 is sound only if all are true:

- Mapping governance model includes approval status, owner, effective dates, and Sharia requirement flags.
- Every posting path uses one central mapping validator.
- Draft/expired/unapproved mappings are fail-closed before money movement.
- Approval requirements are auditable and enforceable by workflow state.

## Phase 1: Canonical Mapping Governance Model

Extend mapping model (existing `operation_account_mappings` or companion table):

Required fields:

- operation code reference
- debit ledger account
- credit ledger account
- effective_from / effective_to
- currency scope
- agency scope
- approval status (`draft`, `submitted`, `approved`, `suspended`, `revoked`, `expired`, `archived`)
- accounting owner
- sharia_approval_required (bool)
- sharia_approval_status / workflow reference
- approval metadata (approved_by, approved_at)

Constraints:

- valid date window
- date/status consistency
- active-usable uniqueness per operation+scope tuple

## Phase 2: Mapping Approval Workflow

Implement `IslamicMappingApprovalWorkflow` backed by IF-011 reusable approval flow:

Lifecycle actions:

- create draft mapping
- submit for approval
- accounting approve/reject
- Sharia approve/reject when required
- activate/suspend/revoke/archive

Use gate:

- mapping usable for posting only when workflow state usable and status approved/active in valid window.

Proof by contradiction:

- Assume draft mapping can post because row exists. Impossible when usability requires approved workflow state.

## Phase 3: Central Mapping Validator Service

Create `IslamicMappingValidationService`:

- `resolvePostingMapping(operationCode, agencyId, currency, context): MappingResult`
- `assertPostingAllowed(...)`

Validation checks:

- mapping exists for scope
- status approved/active
- effective window valid at posting time
- currency and agency scope match
- Sharia approval satisfied when required

Fail-closed behavior:

- no implicit fallback to stale/draft mappings.

## Phase 4: Enforce Validator Across Posting Paths

Replace scattered mapping logic with central validator in:

- Islamic financing posting flows
- Islamic project/asset/goods future posting paths
- any Islamic settlement/reversal/correction posting path

Also align mapping-completeness inspection endpoints to same validator contract.

Proof by contradiction:

- Assume one posting path bypasses approval checks. Impossible when all posting entrypoints call the same validator.

## Phase 5: Expiry And Time-Bound Controls

Define strict behavior for expired mappings:

- expired mapping cannot authorize new postings.
- existing posted journals remain historical.
- optional scheduled expiry transition updates status to `expired`.

Race handling:

- re-check mapping validity inside posting transaction with row lock.

## Phase 6: API Surface

Endpoints:

- `POST /api/v1/islamic-mappings`
- `GET /api/v1/islamic-mappings`
- `GET /api/v1/islamic-mappings/{mappingPublicId}`
- `PUT /api/v1/islamic-mappings/{mappingPublicId}` (draft/submitted constraints)
- `POST /api/v1/islamic-mappings/{mappingPublicId}/submit`
- `POST /api/v1/islamic-mappings/{mappingPublicId}/approve`
- `POST /api/v1/islamic-mappings/{mappingPublicId}/reject`
- `POST /api/v1/islamic-mappings/{mappingPublicId}/suspend`
- `POST /api/v1/islamic-mappings/{mappingPublicId}/revoke`
- `POST /api/v1/islamic-mappings/{mappingPublicId}/archive`

## Phase 7: Audit And Reporting

Record events:

- `islamic.mapping.created`
- `islamic.mapping.submitted`
- `islamic.mapping.approved`
- `islamic.mapping.sharia_approved`
- `islamic.mapping.use_blocked`
- `islamic.mapping.expired`

Expose list/report filters by:

- status
- approval state
- expiry window
- operation code family

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicApprovedMappingWorkflowTest.php`

Minimum tests:

1. `test_draft_mapping_blocks_posting`
2. `test_expired_mapping_blocks_posting`
3. `test_approved_mapping_posts_expected_journal_lines`
4. `test_sharia_required_mapping_blocks_until_sharia_approved`
5. `test_currency_scope_mismatch_blocks_posting`
6. `test_agency_scope_mismatch_blocks_posting`
7. `test_suspended_mapping_blocks_new_postings`
8. `test_validator_enforced_in_islamic_financing_posting_path`
9. `test_effective_window_future_mapping_not_yet_usable`
10. `test_mapping_use_blocked_event_is_audited`

Proof-by-contradiction acceptance alignment tests:

- `test_draft_mapping_blocks_posting`
- `test_expired_mapping_blocks_posting`
- `test_approved_mapping_posts_expected_journal_lines`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: approval metadata added but posting services still query raw `status='active'`.
2. Risk: Sharia approval requirement recorded but not enforced by validator.
3. Risk: fallback-to-null currency mapping can unintentionally bypass scoped mapping governance.

Fixes:

1. mandatory central validator usage in all Islamic posting services.
2. validator explicitly checks Sharia requirement and workflow state.
3. deterministic currency precedence rules with fail-closed ambiguity handling.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: multiple approved mappings can overlap same scope/time.
2. Risk: posting started before expiry and committed after expiry.
3. Risk: mapping revoked concurrently while posting transaction runs.

Fixes:

1. enforce uniqueness or deterministic precedence with conflict rejection.
2. validate effective window at commit-time under lock.
3. re-check mapping status in transaction before journal post.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. IF-051 says validation before every posting; batch/system postings are easy to miss.
2. CI can pass API tests but miss internal domain-service entrypoints.
3. Expiry transitions can drift if purely manual.

Fixes:

1. wrap posting primitives with validator precondition used everywhere.
2. add integration tests for user-driven and system-driven postings.
3. add scheduled expiry command and corresponding tests.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are mapping governance fields complete for approval/scope/effective control? Yes.
- Is mapping validation enforced before every Islamic posting path? Yes.
- Do draft/unapproved/expired/suspended mappings block postings deterministically? Yes.
- Is Sharia approval enforced where required? Yes.
- Are mapping state changes and blocked usage fully auditable? Yes.

## Test Execution Instructions

Use these commands during IF-051 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for mapping workflow + posting gate changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicApprovedMappingWorkflowTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Completed in code:

- Added governance fields for `operation_account_mappings` (approval, effective window, agency scope, owner, Sharia requirement/status, approver metadata).
- Added `IslamicMappingApprovalWorkflow` + `IslamicMappingController` and exposed IF-051 API routes.
- Added central `IslamicMappingValidationService` and enforced it in Islamic posting paths.
- Added contradiction tests for draft/expired/Sharia-pending mapping rejection and blocked-use audit, plus lifecycle API coverage.

Proof-by-contradiction adversarial finding and fix (this round):

1. Contradiction: IF-051 requires validation before every posting path; reversal path could reuse source operation code without honoring configured reversal operation-code mapping validity.
2. Fix in `IslamicFinancingWorkflow::storeReversal`:
   - resolved reversal operation-code policy from operation-code metadata,
   - enforced mapping validation + interest guard on resolved reversal operation code before posting reversal,
   - persisted resolved operation code on reversal event,
   - emitted `islamic.operation_code.reversal_validated` audit event.
3. Added contradiction test:
   - `test_reversal_blocks_when_configured_reversal_operation_code_has_no_approved_mapping`
   - proves reversal is fail-closed when configured reversal operation code is unmapped/unapproved.

Verification run:

```bash
php artisan test --parallel --recreate-databases --filter "(reversal_uses_configured_reversal_operation_code|reversal_blocks_when_configured_reversal_operation_code_has_no_approved_mapping|mapping_use_blocked_event_is_audited|draft_mapping_blocks_posting|expired_mapping_blocks_posting|sharia_required_mapping_blocks_until_sharia_approved)"
```

- Result: `OK (6 tests, 270 assertions)`

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
```

- Result: `OK (86 tests, 2329 assertions)`

```bash
composer test
```

- Result: `OK (573 tests, 8830 assertions)`
