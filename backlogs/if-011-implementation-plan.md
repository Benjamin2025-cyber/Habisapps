# IF-011 Implementation Plan: Sharia Approval Workflow

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-011
Proof-method: proof by contradiction

## IF-011 Source Requirement

Goal: provide reusable approval states for products, templates, policies, contracts, exceptions, mappings, and corrective actions.

Proof-by-contradiction invariant: assume an Islamic product is used while still draft. Origination must be impossible because only approved active records are selectable.

Acceptance criteria:

- States include `draft`, `submitted`, `approved`, `rejected`, `suspended`, `revoked`, `expired`, and `archived`.
- Approval captures approver, decision, comments, conditions, evidence, and effective dates.
- Suspension and revocation immediately block new use while preserving existing records.
- Conditional approval can enforce expiry and conditions.

Tests:

- Draft product cannot originate a contract.
- Suspended template cannot be used.
- Revoked policy blocks new approvals.

## Architecture Context

Current code already has Islamic decision points but inconsistent lifecycle modeling:

- `islamic_products.status` currently uses `draft/approved`.
- `islamic_compliance_reviews` currently uses pending/approved/rejected decision records.
- IF-001 standards, IF-002 sign-offs, and IF-010 authorities have status transitions but not a single reusable approval-state engine.

IF-011 introduces a unified approval-state workflow that can be reused by:

- Islamic products,
- contract templates,
- screening policies,
- exception records,
- mappings,
- corrective actions.

## Completion Definition For This Plan

IF-011 is sound only if all are true:

- All IF-011 lifecycle states are modeled once and reused consistently.
- Approval records include approver, decision, comments, conditions, evidence, and effective dates.
- New-use selection gates reject `draft`, `rejected`, `suspended`, `revoked`, `expired`, `archived`.
- Suspension/revocation does not delete or mutate historical approved records.
- Conditional approval with expiry/conditions is enforceable at selection/use time.
- Audit trail captures state transitions and condition enforcement decisions.

## Phase 1: Canonical Approval Data Model

Create migration:

- `database/migrations/YYYY_MM_DD_HHMMSS_create_islamic_approval_workflow_tables.php`

### 1.1 `islamic_approval_workflows`

Purpose: one row per approvable subject.

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `subject_type` (`string(64)`) values:
  - `islamic_product`
  - `islamic_contract_template`
  - `islamic_screening_policy`
  - `islamic_exception`
  - `islamic_mapping`
  - `islamic_corrective_action`
- `subject_public_id` (`string(64)`)
- `current_state` (`string(32)->default('draft')`)
- `effective_from` (`date()->nullable()`)
- `effective_to` (`date()->nullable()`)
- `is_blocking` (`boolean()->default(true)`)
- `version` (`unsignedInteger()->default(1)`)
- `created_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `updated_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- timestamps

Constraints:

- `current_state` in `draft/submitted/approved/rejected/suspended/revoked/expired/archived`
- unique (`subject_type`, `subject_public_id`)
- `effective_to IS NULL OR effective_to > effective_from`

### 1.2 `islamic_approval_decisions`

Purpose: immutable state-transition log and decision details.

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `workflow_id` (`foreignId()->constrained('islamic_approval_workflows')->cascadeOnDelete()`)
- `from_state` (`string(32)`)
- `to_state` (`string(32)`)
- `decision` (`string(32)`) values:
  - `submit`
  - `approve`
  - `reject`
  - `suspend`
  - `revoke`
  - `expire`
  - `archive`
  - `restore_to_draft`
- `decision_comments` (`text()->nullable()`)
- `conditions` (`json()->nullable()`) structured conditional approval terms
- `evidence_document_id` (`foreignId()->nullable()->constrained('documents')->nullOnDelete()`)
- `decided_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `decided_at` (`timestamp()`)
- `effective_from` (`date()->nullable()`)
- `effective_to` (`date()->nullable()`)
- `metadata` (`json()->nullable()`)
- timestamps

Constraints:

- `from_state` and `to_state` must be in canonical state set
- `effective_to IS NULL OR effective_to > effective_from`
- conditional approval requires:
  - `to_state = approved`
  - non-empty `conditions`
  - at least one of `effective_to` or explicit condition expiry key

Proof by contradiction:

- Assume state typo `aproved` silently passes: impossible via enum checks.
- Assume approved state has no approver context: impossible (`decided_by_user_id`/`decided_at` required).
- Assume conditional approval has no enforceable boundary: impossible because conditions/effective window required.

## Phase 2: State Machine Rules

Create:

- `app/Application/IslamicFinance/IslamicApprovalStateMachine.php`

Canonical transitions:

- `draft -> submitted`
- `submitted -> approved|rejected`
- `approved -> suspended|revoked|expired|archived`
- `rejected -> draft|archived`
- `suspended -> approved|revoked|archived`
- `revoked -> archived`
- `expired -> archived|approved` (re-approval path only if policy allows)
- `archived` terminal by default

Validation rules:

- reject invalid transitions with explicit reason.
- `approve` requires IF-010 authority check where subject is material.
- self-approval prohibited for material decision subjects.

Proof by contradiction:

- Assume `draft -> approved` direct transition bypasses review: impossible by transition map.
- Assume revoked policy can return to active via ordinary update: impossible unless explicit transition allows and evidence is recorded.

## Phase 3: Reusable Workflow Service

Create:

- `app/Application/IslamicFinance/IslamicApprovalWorkflowService.php`

Core operations:

- `ensureWorkflow(subjectType, subjectPublicId, actor)`
- `submit(...)`
- `approve(...)`
- `reject(...)`
- `suspend(...)`
- `revoke(...)`
- `expire(...)`
- `archive(...)`
- `isUsableForNewActions(subjectType, subjectPublicId, asOf): array{ok: bool, reasons: list<string>}`

Service responsibilities:

- lock row + apply state machine transition.
- write immutable decision log.
- enforce conditions/effective date boundaries.
- expose consistent gate-check API for caller workflows.

## Phase 4: Integrate Existing Islamic Flows

### 4.1 Product Flow

Update:

- `app/Application/IslamicFinance/IslamicProductWorkflow.php`
- `app/Application/IslamicFinance/IslamicFinancingWorkflow.php`

Changes:

- replace hardcoded product status logic with workflow state checks.
- `storeComplianceReview` should transition product workflow `draft -> submitted`.
- approval decision should transition `submitted -> approved`.
- financing origination (`storeFinancing`) should call `isUsableForNewActions(islamic_product, product_public_id)` and reject non-usable states.

### 4.2 Template/Policy/Mapping Entry Points

As these subject types are introduced by later backlogs, IF-011 provides base reusable endpoints and service hooks now.

Current-phase concrete implementation:

- add route/controller endpoints to manage workflow transitions for any registered subject type.
- add deny-by-default behavior for unknown subject type registrations.

Proof by contradiction:

- Assume product remains draft but financing originates: blocked by `isUsableForNewActions`.
- Assume suspended subject is still selectable for new use: blocked by centralized gate.

## Phase 4.3: Legacy Status Cutover

To prevent long-term dual-write drift:

- Keep legacy `status` columns as compatibility mirrors during transition window.
- Add one-way invariant: workflow state is source of truth; subject `status` must be derived from workflow state mapping.
- Add reconciliation command:
  - `php artisan islamic:approval-workflow:reconcile-statuses`
  - reports mismatches and optionally fixes mirror values.
- Define cutover checkpoint:
  - all selectors in Islamic flows must depend on workflow service,
  - status-column direct checks become forbidden by static scan rule/policy test.

Proof by contradiction:

- Assume an old code path checks subject status only and bypasses workflow restrictions. Reconciliation and policy tests must fail build until path is migrated.

## Phase 5: Conditional Approval Enforcement

Condition model baseline:

- `conditions` JSON supports keys:
  - `required_controls` (list<string>)
  - `required_documents` (list<string>)
  - `max_notional_minor` (int|null)
  - `allowed_agencies` (list<string>|null)
  - `expires_on` (date|null)

Enforcement points:

- `isUsableForNewActions` evaluates effective dates + condition expiry.
- caller workflows can request strict control checks using provided condition evaluator helpers.

Policy:

- if condition cannot be evaluated at call site, deny usage by default and emit audit reason.

## Phase 6: API Surface

Add routes in `routes/api/v1/islamic_finance.php`:

- `POST /api/v1/islamic-approval-workflows/{subjectType}/{subjectPublicId}/submit`
- `POST /api/v1/islamic-approval-workflows/{subjectType}/{subjectPublicId}/approve`
- `POST /api/v1/islamic-approval-workflows/{subjectType}/{subjectPublicId}/reject`
- `POST /api/v1/islamic-approval-workflows/{subjectType}/{subjectPublicId}/suspend`
- `POST /api/v1/islamic-approval-workflows/{subjectType}/{subjectPublicId}/revoke`
- `POST /api/v1/islamic-approval-workflows/{subjectType}/{subjectPublicId}/expire`
- `POST /api/v1/islamic-approval-workflows/{subjectType}/{subjectPublicId}/archive`
- `GET /api/v1/islamic-approval-workflows/{subjectType}/{subjectPublicId}`

Response fields:

- workflow `public_id`, `subject_type`, `subject_public_id`, `current_state`, `effective_from`, `effective_to`
- latest decision summary
- conditions
- usability status preview (optional)

No internal numeric IDs exposed.

## Phase 7: Audit Events

Record:

- `islamic.approval_workflow.created`
- `islamic.approval.submitted`
- `islamic.approval.approved`
- `islamic.approval.rejected`
- `islamic.approval.suspended`
- `islamic.approval.revoked`
- `islamic.approval.expired`
- `islamic.approval.archived`
- `islamic.approval.use_blocked`

Each event includes:

- subject type/public id
- previous/new state
- decision actor
- conditions/effective boundaries
- block reasons when denied

## Phase 8: Tests

Add/extend tests in:

- `tests/Feature/Api/IslamicFinanceTest.php`
- optional focused file: `tests/Feature/Api/IslamicApprovalWorkflowTest.php`

Minimum tests:

1. `draft` product cannot originate financing contract.
2. `submitted` product cannot originate financing contract.
3. `approved` product can originate financing contract (assuming other gates pass).
4. `suspended` subject cannot be used for new action.
5. `revoked` policy subject blocks new approvals.
6. invalid transition (`draft -> revoked`) is rejected.
7. conditional approval with past expiry fails usability check.
8. self-approval on material subject is rejected.
9. expired approval state blocks new use while preserving historical records.
10. audit trail includes all transitions and block events.
11. workflow-to-legacy status reconciliation detects and reports mismatches.
12. direct status-only gate checks in Islamic flows are rejected by policy test after cutover.

Proof-by-contradiction alignment tests:

- `test_draft_product_cannot_originate_contract`
- `test_suspended_template_cannot_be_used`
- `test_revoked_policy_blocks_new_approvals`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: state modeling duplicates existing subject status columns and can drift.
2. Risk: legacy code may still read old status columns and bypass workflow gate.
3. Risk: conditional approvals become passive metadata if not enforced centrally.
4. Risk: status mirror drift between workflow and legacy subject columns.

Fixes:

1. Declare workflow as source of truth for new-use eligibility; keep existing subject status as compatibility mirror during migration.
2. Require all new-use checks in Islamic flows to call `isUsableForNewActions`.
3. Deny-by-default if required condition cannot be evaluated at call site.
4. Add explicit status-cutover phase with reconciliation command and policy test to prevent bypass.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity on "approved active" semantics when `effective_from` is future.
2. Ambiguity on how `expired` state is set (manual vs derived).
3. Risk of silent state change without immutable history.

Fixes:

1. `approved` is usable only when `effective_from <= asOf` and not expired by date/conditions.
2. expiration can be derived at read-time and/or materialized via `expire` transition; either way usability gate uses temporal checks first.
3. every transition requires an immutable `islamic_approval_decisions` entry.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. IF-011 requires reuse across templates/policies/mappings, but those modules may not exist yet.
2. Risk that implementers postpone reuse by hardcoding product-only logic.

Fixes:

1. workflow tables and service are subject-generic from first implementation.
2. transition endpoints and service APIs require explicit `subject_type` and reject unsupported values; this enforces reusable architecture now.
3. product integration is only the first concrete consumer, not the only design target.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are all required lifecycle states modeled canonically? Yes.
- Are transitions constrained by an explicit state machine? Yes.
- Is approver/decision/comments/conditions/evidence/effective date data captured immutably? Yes.
- Can draft/submitted/suspended/revoked/expired/archived subjects be blocked from new use centrally? Yes.
- Can conditional approvals enforce expiry and conditions at use-time? Yes.
- Does product origination require approved-and-usable workflow state? Yes.
- Are suspension/revocation non-destructive to historical records? Yes.
- Is the model reusable across products/templates/policies/contracts/exceptions/mappings/corrective actions? Yes.
- Are transition and block decisions fully auditable? Yes.

## Test Execution Instructions

Use these commands during IF-011 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for approval-workflow changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused workflow tests (if extracted)
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicApprovalWorkflowTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Evidence (2026-05-25)

Contradiction findings discovered:

1. A screening policy revoked via IF-011 workflow endpoint could still be selected as an active screening policy if legacy `status=active` remained, creating workflow-vs-selection drift.
2. Enforcing workflow usability unconditionally for screening policy selection initially broke compatibility for legacy policies created before workflow rows existed.

Fixes applied:

1. Screening policy resolution now enforces workflow usability for `islamic_screening_policy` subjects when a workflow row exists.
2. Backward-compatibility fallback added: policies with no workflow row continue to use legacy status/effective-window selection until migrated.
3. Added contradiction test:
   - `test_revoked_screening_policy_workflow_blocks_strict_screening_use`
   - proves workflow-revoked policy cannot continue to yield strict `pass` screening results.

Verification runs:

```bash
php artisan test --parallel --recreate-databases --filter IslamicApprovalWorkflowTest
```

Result:
- `OK (17 tests, 247 assertions)` in ~7.2s.

```bash
php artisan test --parallel --recreate-databases --filter "test_restricted_sector_creates_compliance_review|test_policy_version_is_snapshotted_on_result|test_manual_review_on_contract_approval_creates_contract_blocker_case|test_manual_override_without_approved_exception_workflow_is_rejected|test_approved_exception_workflow_allows_override_with_audit|test_scoped_policy_resolution_prefers_product_family_over_institution|test_revoked_screening_policy_workflow_blocks_strict_screening_use"
```

Result:
- `OK (7 tests, 106 assertions)` in ~6.6s.

```bash
composer test
```

Result:
- `OK (566 tests, 8574 assertions)` in ~41.5s.
