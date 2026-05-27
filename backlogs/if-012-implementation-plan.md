# IF-012 Implementation Plan: Compliance Review Case Management

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-012
Proof-method: proof by contradiction

## IF-012 Source Requirement

Goal: track reviews across products, customers, assets, goods, projects, suppliers, accounts, contracts, and transactions.

Proof-by-contradiction invariant: assume a flagged contract proceeds with an unresolved review. Activation must be blocked.

Acceptance criteria:

- Create review cases with subject type, reason, risk, checklist version, assigned reviewer, due date, decision, and evidence.
- Support approved, rejected, needs information, conditionally approved, suspended, and corrective action decisions.
- Link reviews to workflow blockers.
- Expose reportable status and audit trail.

Tests:

- Unresolved blocking review prevents activation.
- Conditional approval expires and blocks future action.
- Corrective action closure is audited.

## Architecture Context

Current state:

- `IslamicProductWorkflow` stores product reviews in `islamic_compliance_reviews` with narrow statuses (`pending`, `approved`, `rejected`).
- Readiness gating for product use already exists (`IslamicProductReadinessService`, `IslamicApprovalWorkflowService` usage checks).
- No reusable review-case model exists for non-product subjects.

IF-012 adds a generic compliance-case subsystem and blocker checks reusable by all Islamic domains.

## Completion Definition For This Plan

IF-012 is sound only if all are true:

- Review cases are generic and can reference all required subject classes.
- Case decisions support all IF-012 decision outcomes.
- Blocking reviews are centrally enforceable by use/activation workflows.
- Conditional approvals enforce expiry and condition compliance at decision-use time.
- Corrective actions have explicit lifecycle and closure auditing.
- Reporting surface exposes open/blocked/expired/closed states and audit timeline.

## Phase 1: Canonical Case Data Model

Create migration:

- `database/migrations/YYYY_MM_DD_HHMMSS_create_islamic_compliance_case_tables.php`

### 1.1 `islamic_compliance_cases`

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `subject_type` (`string(64)`) values:
  - `islamic_product`
  - `islamic_customer`
  - `islamic_asset`
  - `islamic_goods`
  - `islamic_project`
  - `islamic_supplier`
  - `islamic_account`
  - `islamic_contract`
  - `islamic_transaction`
- `subject_public_id` (`string(64)`)
- `reason_code` (`string(64)`)
- `risk_level` (`string(16)`) values: `low`, `medium`, `high`, `critical`
- `checklist_version` (`string(64)`)
- `assigned_reviewer_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `due_at` (`timestamp()->nullable()`)
- `status` (`string(32)->default('open')`) values: `open`, `in_review`, `blocked`, `resolved`, `archived`
- `blocking_mode` (`string(16)->default('hard')`) values: `hard`, `soft`
- `latest_decision` (`string(32)->nullable()`)
- `latest_decided_at` (`timestamp()->nullable()`)
- `created_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `closed_at` (`timestamp()->nullable()`)
- `closed_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `metadata` (`json()->nullable()`)
- timestamps

Constraints:

- valid `subject_type` enum
- valid `risk_level` enum
- valid `status` enum
- valid `blocking_mode` enum
- unique (`subject_type`, `subject_public_id`, `reason_code`, `status`) for active statuses (`open`, `in_review`, `blocked`) via partial index

### 1.2 `islamic_compliance_case_decisions`

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `case_id` (`foreignId()->constrained('islamic_compliance_cases')->cascadeOnDelete()`)
- `decision` (`string(32)`) values:
  - `approved`
  - `rejected`
  - `needs_information`
  - `conditionally_approved`
  - `suspended`
  - `corrective_action_required`
  - `corrective_action_closed`
- `decision_comments` (`text()->nullable()`)
- `conditions` (`json()->nullable()`)
- `evidence_document_id` (`foreignId()->nullable()->constrained('documents')->nullOnDelete()`)
- `decided_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `decided_at` (`timestamp()`)
- `effective_from` (`timestamp()->nullable()`)
- `effective_to` (`timestamp()->nullable()`)
- `metadata` (`json()->nullable()`)
- timestamps

Constraints:

- decision enum check
- `effective_to IS NULL OR effective_to > effective_from`
- conditional approval requires non-empty conditions and an enforceable expiry boundary (`effective_to` or `conditions.expires_on`)

### 1.3 `islamic_compliance_case_blockers`

Purpose: explicit linkage between case and blocked action.

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `case_id` (`foreignId()->constrained('islamic_compliance_cases')->cascadeOnDelete()`)
- `blocker_type` (`string(64)`) values:
  - `product_activation`
  - `contract_activation`
  - `supplier_use`
  - `asset_acceptance`
  - `goods_acceptance`
  - `project_approval`
  - `account_pool_assignment`
  - `transaction_authorization`
- `target_subject_type` (`string(64)`)
- `target_subject_public_id` (`string(64)`)
- `is_active` (`boolean()->default(true)`)
- `activated_at` (`timestamp()`)
- `released_at` (`timestamp()->nullable()`)
- `released_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `release_reason` (`text()->nullable()`)
- timestamps

Constraints:

- valid blocker type
- one active blocker per (`case_id`, `blocker_type`, `target_subject_type`, `target_subject_public_id`)

Proof by contradiction:

- Assume a case exists but cannot block anything because linkage is implicit. False: blocker rows are explicit and queryable.
- Assume conditional approval has no expiry boundary. False: decision constraint rejects it.
- Assume corrective action closure is invisible. False: explicit decision type + blocker release fields + audit event.

## Phase 2: Case Workflow Service

Create:

- `app/Application/IslamicFinance/IslamicComplianceCaseService.php`

Core operations:

- `openCase(...)`
- `assignReviewer(...)`
- `recordDecision(...)`
- `addBlocker(...)`
- `releaseBlocker(...)`
- `activeBlockerFailures(blockerType, targetSubjectType, targetSubjectPublicId, asOf): array`
- `isConditionallyUsable(casePublicId, asOf, context): array{ok: bool, reasons: list<string>}`

Decision semantics:

- `approved` => resolves case if no active blocker policy remains.
- `rejected` => keeps blocker active by default.
- `needs_information` => case remains open/in_review, blocker remains active.
- `conditionally_approved` => blocker may be released only if conditions currently satisfied; otherwise active.
- `suspended` => blocker active.
- `corrective_action_required` => blocker active and corrective task mandatory.
- `corrective_action_closed` => eligible for blocker release with audit.

## Phase 3: Integrate Current Flows

### 3.1 Product Review Migration

Current `islamic_compliance_reviews` should become compatibility layer:

- keep existing table for short migration window.
- create canonical case entries in `islamic_compliance_cases` for new review requests.
- mirror key decision outcomes to old table until full cutover.

Update:

- `app/Application/IslamicFinance/IslamicProductWorkflow.php`

Changes:

- `storeComplianceReview` opens case with subject `islamic_product` and adds `product_activation` blocker.
- `reviewCompliance` writes case decision + manages blocker release/retention according to decision semantics.

### 3.2 Activation/Use Block Checks

Integrate blocker checks into:

- `IslamicApprovalWorkflowService::isUsableForNewActions` (subject-level gate)
- `IslamicFinancingWorkflow::storeFinancing` (contract-use path)

Behavior:

- if unresolved hard blocker exists for required blocker type, reject action with `422`.
- include blocker case public ids and reasons in error payload.

Proof by contradiction:

- Assume flagged product has unresolved hard blocker and financing still originates. Impossible because blocker gate fails before write.
- Assume expired conditional approval continues allowing use. Impossible because condition evaluator checks effective window and flips blocker back active/denied.

## Phase 4: Reporting Surface

Create endpoints:

- `GET /api/v1/islamic-compliance-cases`
- `GET /api/v1/islamic-compliance-cases/{casePublicId}`
- `GET /api/v1/islamic-compliance-cases/{casePublicId}/timeline`
- `GET /api/v1/islamic-compliance-cases/report/summary`

Filters:

- subject type/id
- risk level
- status
- decision
- overdue
- blocker active/inactive

Response includes:

- case core fields
- latest decision
- active blocker status
- due/overdue indicators
- linked evidence and timeline events

No internal numeric IDs exposed.

## Phase 5: Audit Events

Record:

- `islamic.compliance_case.opened`
- `islamic.compliance_case.assigned`
- `islamic.compliance_case.decision_recorded`
- `islamic.compliance_case.blocker_activated`
- `islamic.compliance_case.blocker_released`
- `islamic.compliance_case.corrective_action.required`
- `islamic.compliance_case.corrective_action.closed`
- `islamic.compliance_case.use_blocked`

## Phase 6: Tests

Add tests in:

- `tests/Feature/Api/IslamicFinanceTest.php`
- optional focused file: `tests/Feature/Api/IslamicComplianceCaseTest.php`

Minimum tests:

1. Open case captures subject type, reason, risk, checklist version, assignee, due date, evidence.
2. `needs_information` keeps blocker active.
3. unresolved hard blocker prevents activation/use.
4. `conditionally_approved` with valid active condition allows action.
5. `conditionally_approved` after expiry blocks action.
6. `corrective_action_required` blocks until `corrective_action_closed`.
7. closure of corrective action emits audit event and can release blocker.
8. report endpoint exposes active blockers and overdue cases.
9. invalid decision transition is rejected.
10. timeline includes immutable decision history.

Proof-by-contradiction alignment tests:

- `test_unresolved_blocking_review_prevents_activation`
- `test_conditional_approval_expires_and_blocks_future_action`
- `test_corrective_action_closure_is_audited`

## Phase 7: Adversarial Review (Round 1)

Findings:

1. Risk: cases become informational only if blockers are optional.
2. Risk: existing use-paths may forget to call blocker gate.
3. Risk: conditional approvals are accepted once and never reevaluated.

Fixes:

1. hard blocker model is explicit and default for activation-critical cases.
2. blocker checks are integrated into centralized `isUsableForNewActions` and financing use path.
3. condition evaluator runs at decision-use time, not only at decision-write time.

## Phase 8: Adversarial Review (Round 2)

Findings:

1. Ambiguity: one subject can have multiple active cases with conflicting decisions.
2. Ambiguity: soft blocker semantics could accidentally bypass hard blockers.
3. Risk: migration window introduces divergence between old review table and new case system.

Fixes:

1. hard blocker precedence rule: any active hard blocker denies action regardless of soft outcomes.
2. soft blockers can only annotate risk and require explicit policy opt-in to block.
3. mirror strategy + reconciliation command:
   - `php artisan islamic:compliance-cases:reconcile`
   - reports and fixes drift between `islamic_compliance_reviews` and new case records during cutover.

## Phase 9: Adversarial Review (Round 3)

Findings:

1. IF-012 requires broad subject coverage; product-only implementation would violate scope.
2. blocker type list may be incomplete for future modules.

Fixes:

1. case schema is subject-generic and does not encode product-only assumptions.
2. blocker type enum is centralized in service constant with explicit extension process and tests for unknown blocker rejection.

## Phase 10: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Can cases be opened for all required subject classes with required metadata? Yes.
- Are all required decision outcomes supported? Yes.
- Can unresolved hard blockers stop activation/use deterministically? Yes.
- Can conditional approvals expire and re-block future use? Yes.
- Can corrective action lifecycle be tracked and audited through closure? Yes.
- Are blockers explicitly linked to workflows and targets? Yes.
- Is reportable case/blocker status available through API + timeline? Yes.
- Is migration from existing review table controlled with drift detection? Yes.

## Test Execution Instructions

Use these commands during IF-012 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for compliance-case integration
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused case-management tests (if extracted)
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicComplianceCaseTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Proof-by-contradiction adversarial review findings and fixes:

1. Contradiction: IF-012 requires case assignment auditability (`islamic.compliance_case.assigned`) but case creation only emitted `opened`.
   - Fix: `IslamicComplianceCaseService::openCase` now emits `islamic.compliance_case.assigned` whenever `assigned_reviewer_user_id` is provided, including due date context.

2. Contradiction: IF-012 requires blocked-use audit trail at case layer (`islamic.compliance_case.use_blocked`) but financing gate only emitted generic approval-blocked events.
   - Fix: `IslamicFinancingWorkflow::storeFinancing` now emits `islamic.compliance_case.use_blocked` whenever hard active compliance blockers deny use (both pre-transaction check and race-window recheck path).

3. Contradiction: IF-012 requires decision evidence support but review API could not submit evidence document references into case decisions.
   - Fix: `IslamicProductWorkflow::reviewCompliance` now accepts `evidence_document_public_id` and resolves it into `evidence_document_id` persisted through `IslamicComplianceCaseService::recordDecision`.

Proof-by-contradiction tests added/updated:

- `test_unresolved_blocking_review_prevents_activation`
  - now asserts `islamic.compliance_case.use_blocked` is audited.
- `test_compliance_case_assignment_and_decision_evidence_are_persisted_and_audited`
  - proves reviewer assignment emits audit event and decision evidence is persisted.

Verification commands and results:

```bash
php artisan test --parallel --recreate-databases --filter "(unresolved_blocking_review_prevents_activation|compliance_case_assignment_and_decision_evidence_are_persisted_and_audited|conditional_approval_expires_and_blocks_future_action|corrective_action_closure_is_audited)"
```

- Result: `OK (4 tests, 104 assertions)`

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
```

- Result: `OK (80 tests, 2104 assertions)`

```bash
composer test
```

- Result: `OK (567 tests, 8605 assertions)`
