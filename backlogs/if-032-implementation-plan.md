# IF-032 Implementation Plan: Contract Template Registry

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-032
Proof-method: proof by contradiction

## IF-032 Source Requirement

Goal: store versioned contract templates approved for each product family.

Proof-by-contradiction invariant: assume a contract is originated from an unapproved template. Origination must be rejected.

Acceptance criteria:

- Store template family, language, version, effective date, expiry, fields, approval status, and document attachment.
- Require Sharia and legal approval before use.
- Contract snapshots template version and resolved commercial terms.
- Retired template remains visible for historical contracts.

Tests:

- Draft template cannot originate contract.
- Expired template cannot originate new contract.
- Existing contract keeps old template snapshot.

## Architecture Context

Current state:

- `IslamicStandardWorkflow` still validates contract templates as reserved placeholder codes (`reserved_until_backlog: IF-032`).
- No first-class contract-template registry tables/endpoints exist yet.
- Financing creation currently does not bind or snapshot template version at origination.
- Approval workflow already supports reusable subject type `islamic_contract_template`.

Primary contradiction gap:

- Contract template references are symbolic placeholders, so origination cannot prove template approval/effective validity/version snapshot requirements.

## Completion Definition For This Plan

IF-032 is sound only if all are true:

- Contract templates are first-class versioned records per product family and language.
- Only approved + effective + non-expired templates can originate new contracts.
- Origination snapshot stores immutable template version and resolved commercial terms.
- Retired/expired templates remain queryable for history but blocked for new use.
- Standards links and readiness checks validate against real template registry, not reserved placeholders.

## Phase 1: Contract Template Registry Data Model

Create migration:

- `database/migrations/YYYY_MM_DD_HHMMSS_create_islamic_contract_template_tables.php`

### 1.1 `islamic_contract_templates`

Columns:

- `id`, `public_id`
- `family_code` (FK/logical reference to IF-030 catalog)
- `language_code` (e.g. `fr`, `en`, `ar`)
- `template_code` (stable logical code)
- `version` (integer)
- `status` (`draft`, `submitted`, `approved`, `suspended`, `revoked`, `expired`, `retired`, `archived`)
- `effective_from` (date)
- `effective_to` (date nullable)
- `fields_schema` (json)
- `commercial_terms_schema` (json)
- `document_id` (FK `documents`)
- `legal_signoff_ref` (string/json)
- `sharia_signoff_ref` (string/json)
- `metadata` (json nullable)
- timestamps

Constraints:

- unique (`template_code`, `version`, `language_code`)
- valid status enum
- valid date window (`effective_to IS NULL OR effective_to > effective_from`)

### 1.2 `islamic_contract_template_snapshots`

Purpose: immutable contract-origination snapshot.

Columns:

- `id`, `public_id`
- `contract_subject_type` (e.g. `islamic_financing`)
- `contract_subject_public_id`
- `template_public_id`
- `template_code`
- `template_version`
- `language_code`
- `template_snapshot` (json immutable)
- `resolved_terms_snapshot` (json immutable)
- `snapshot_hash` (string)
- `created_by_user_id`
- timestamps

Proof by contradiction:

- Assume old template content is edited and mutates existing contracts. Impossible because origination stores immutable snapshots.

## Phase 2: Workflow And Approval Integration

Create workflow service/controller:

- `IslamicContractTemplateWorkflow` (or integrate into existing Islamic finance adapter)

Endpoints:

- `GET /api/v1/islamic-contract-templates`
- `POST /api/v1/islamic-contract-templates`
- `GET /api/v1/islamic-contract-templates/{templatePublicId}`
- `PUT /api/v1/islamic-contract-templates/{templatePublicId}` (draft only)
- `POST /api/v1/islamic-contract-templates/{templatePublicId}/submit`
- `POST /api/v1/islamic-contract-templates/{templatePublicId}/approve`
- `POST /api/v1/islamic-contract-templates/{templatePublicId}/suspend`
- `POST /api/v1/islamic-contract-templates/{templatePublicId}/revoke`
- `POST /api/v1/islamic-contract-templates/{templatePublicId}/retire`
- `POST /api/v1/islamic-contract-templates/{templatePublicId}/archive`

Activation/use requirements:

- approval workflow subject `islamic_contract_template` must be usable.
- legal sign-off evidence required.
- sharia sign-off evidence required.
- effective date window active at use time.

## Phase 3: Replace Reserved Template Placeholder Path

Update `IslamicStandardWorkflow::validateAndResolveLink` for `contract_template`:

- remove reserved-only behavior.
- validate `linkable_code` against actual template registry (`public_id` or approved `template_code@version`).
- retain backward-compat path only during migration window with explicit deprecation errors.

Proof by contradiction:

- Assume standards baseline passes with nonexistent template reference. Impossible when links resolve only to registry-backed records.

## Phase 4: Origination Gate And Snapshot Enforcement

Integrate into financing origination path (and future contract workflows):

- resolve active template for product family + language + date.
- reject if none approved/effective.
- materialize resolved commercial terms from request + template schema.
- persist `islamic_contract_template_snapshots` in same transaction as contract creation.

Transactional invariant:

- no contract row committed without template snapshot row.

Proof by contradiction:

- Assume contract originates from draft template. Impossible because resolver requires approved+usable template state.

## Phase 5: Template Resolution Rules

Deterministic resolver precedence:

1. explicit template reference if provided and usable
2. otherwise latest approved version for family+language effective at `asOf`
3. fallback language policy (configured default language) only if explicitly allowed

Conflicts:

- multiple eligible templates same precedence => reject with deterministic conflict error; require explicit template selection.

## Phase 6: Historical Visibility Guarantees

Behavior:

- retired/expired templates remain listable/showable.
- new origination disallows statuses: `draft`, `submitted`, `suspended`, `revoked`, `expired`, `retired`, `archived`.
- existing contracts continue to return historical snapshot even if source template retired.

## Phase 7: IF-031 Readiness Hook

Update readiness gate `contract_template` dimension:

- check actual approved/effective template availability for product family.
- include template identity/version in readiness snapshot.

This removes IF-031 temporary placeholder dependency.

## Phase 8: Audit Events

Record:

- `islamic.contract_template.created`
- `islamic.contract_template.updated`
- `islamic.contract_template.status_changed`
- `islamic.contract_template.use_blocked`
- `islamic.contract_template.snapshot_stored`

Audit payload includes template public id, family, version, status, and contract subject references.

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicContractTemplateRegistryTest.php`

Minimum tests:

1. `test_draft_template_cannot_originate_contract`
2. `test_expired_template_cannot_originate_new_contract`
3. `test_approved_effective_template_allows_origination`
4. `test_origination_persists_template_version_and_terms_snapshot`
5. `test_existing_contract_keeps_old_template_snapshot_after_retirement`
6. `test_standards_link_requires_registry_backed_contract_template`
7. `test_template_resolution_rejects_conflicting_candidates`
8. `test_template_language_fallback_policy_is_enforced`
9. `test_unapproved_template_is_not_selectable_by_explicit_reference`
10. `test_template_snapshot_write_failure_aborts_contract_creation`

Proof-by-contradiction acceptance alignment tests:

- `test_draft_template_cannot_originate_contract`
- `test_expired_template_cannot_originate_new_contract`
- `test_existing_contract_keeps_old_template_snapshot`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: version stored but no immutable snapshot; later edits alter contract meaning.
2. Risk: approval state checked, but effective date window ignored.
3. Risk: legal/sharia sign-off references stored as free text without enforceable linkage.

Fixes:

1. mandatory immutable template + resolved terms snapshot at origination.
2. enforce effective window at template resolution time.
3. require sign-off linkage validation against authoritative records/workflows.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: fallback language can silently choose wrong legal wording.
2. Risk: migration keeps reserved template codes active too long, bypassing registry checks.
3. Risk: concurrent origination and template status change race may allow forbidden use.

Fixes:

1. require explicit fallback policy and audit when fallback occurs.
2. hard deprecation date for reserved-code acceptance; default deny once registry available.
3. lock selected template row during origination transaction and re-check usability.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. IF-032 requires retired templates visible historically; aggressive cleanup could remove them.
2. IF-031 template gate can pass if it checks only existence, not effective approved version.
3. CI can pass with API CRUD only, without proving origination gating and snapshot invariants.

Fixes:

1. prohibit physical deletion for templates referenced by snapshots/contracts.
2. readiness template gate validates approved+effective+family match.
3. add end-to-end origination tests asserting gate and snapshot behavior.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are contract templates first-class, versioned, and family/language-scoped? Yes.
- Is origination blocked unless template is approved and effective? Yes.
- Is immutable template+terms snapshot persisted with origination? Yes.
- Are retired/expired templates still visible for historical references but blocked for new use? Yes.
- Are standards/readiness integrations using registry-backed template identity instead of reserved placeholders? Yes.

## Test Execution Instructions

Use these commands during IF-032 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for template-registry and origination gate changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicContractTemplateRegistryTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Proof-by-contradiction adversarial review findings and fixes:

1. Contradiction: template language fallback was implicit.
   - Previous behavior allowed silent language fallback when `template_language_code` had no approved/effective match.
   - Fix in `IslamicFinancingWorkflow::resolveTemplateForOrigination`:
     - fallback now requires explicit `allow_template_language_fallback=true`.
     - otherwise origination fails with deterministic error.

2. Contradiction: resolver silently selected one template when multiple top-precedence candidates existed.
   - Previous behavior used `orderByDesc(...)->first()` which hides ambiguity.
   - Fix:
     - added deterministic conflict detection at latest version for selection scope.
     - origination now rejects conflicting eligible candidates and requires explicit `contract_template_public_id`.

3. Contradiction: IF-032 audit requirements were incomplete.
   - Added `islamic.contract_template.snapshot_stored` on origination snapshot write.
   - Added `islamic.contract_template.use_blocked` when origination is denied for template-related reasons.
   - Added `islamic.contract_template.language_fallback_used` when explicit fallback path is used.

4. Historical immutability proof:
   - Added test proving retirement blocks new use while existing financing keeps immutable template snapshot.

Proof-by-contradiction tests added:

- `test_template_language_fallback_policy_is_enforced`
- `test_template_resolution_rejects_conflicting_candidates`
- `test_existing_contract_keeps_old_template_snapshot_after_retirement`

Existing IF-032 contradiction tests retained and passing:

- `test_draft_template_cannot_originate_contract`
- `test_expired_template_cannot_originate_new_contract`
- `test_origination_persists_template_snapshot`

Verification commands and results:

```bash
php artisan test --parallel --recreate-databases --filter "(draft_template_cannot_originate_contract|expired_template_cannot_originate_new_contract|template_language_fallback_policy_is_enforced|template_resolution_rejects_conflicting_candidates|existing_contract_keeps_old_template_snapshot_after_retirement|origination_persists_template_snapshot)"
```

- Result: `OK (6 tests, 196 assertions)`

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
```

- Result: `OK (84 tests, 2232 assertions)`

```bash
composer test
```

- Result: `OK (571 tests, 8733 assertions)`
