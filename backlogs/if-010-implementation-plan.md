# IF-010 Implementation Plan: Sharia Authority Model

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-010
Proof-method: proof by contradiction

## IF-010 Source Requirement

Goal: model the Sharia Board or compliance committee requested by stakeholders.

Proof-by-contradiction invariant: assume a staff user without Sharia mandate approves a product. Approval must be rejected by role and mandate checks.

Acceptance criteria:

- Add committee/authority records with mandate, members, role, scope, effective dates, and evidence.
- Support chair, reviewer, approver, observer, and administrator roles.
- Enforce no self-approval for material decisions.
- Audit membership changes and mandate changes.

Tests:

- Unauthorized staff cannot approve.
- Expired mandate cannot approve.
- Self-approval is rejected.

## Architecture Context

Current Islamic approval flow is implemented in:

- `app/Application/IslamicFinance/IslamicProductWorkflow.php::reviewCompliance`

Current enforcement includes:

- platform-admin gate,
- pending-review status checks,
- requester cannot self-review,
- readiness gate checks.

IF-010 adds explicit Sharia authority governance to this approval path and reusable authority checks for later Islamic modules.

## Completion Definition For This Plan

IF-010 is sound only if all are true:

- Sharia authorities and mandates are explicit records with evidence and validity windows.
- Membership role (`chair`, `reviewer`, `approver`, `observer`, `administrator`) is explicit and scoped.
- Approval permission for material Islamic decisions is granted only through active Sharia mandate.
- Self-approval is blocked even if actor has authority role.
- Expired/suspended/revoked mandates cannot approve.
- Membership and mandate lifecycle changes are auditable.

## Phase 1: Data Model

Create migration:

- `database/migrations/YYYY_MM_DD_HHMMSS_create_islamic_sharia_authority_tables.php`

### 1.1 `islamic_sharia_authorities`

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `name` (`string(191)`) e.g. "Habis Sharia Board"
- `authority_type` (`string(32)`) values: `board`, `committee`, `advisor_panel`
- `jurisdiction` (`string(64)`) values include `cameroon`, `cemac`, `institution`
- `mandate_scope` (`json`) structured scope selectors
- `mandate_summary` (`text`)
- `effective_date` (`date`)
- `expiry_date` (`date()->nullable()`)
- `status` (`string(32)->default('draft')`) values: `draft`, `active`, `suspended`, `revoked`, `retired`
- `document_id` (`foreignId()->constrained('documents')->restrictOnDelete()`) mandate evidence
- `created_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `activated_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `activated_at` (`timestamp()->nullable()`)
- `retired_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `retired_at` (`timestamp()->nullable()`)
- `retirement_reason` (`text()->nullable()`)
- `metadata` (`json()->nullable()`)
- timestamps

Constraints:

- enum checks (`authority_type`, `status`)
- `expiry_date IS NULL OR expiry_date > effective_date`
- active requires activation actor/time
- retired requires retired fields/reason

### 1.2 `islamic_sharia_authority_members`

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `islamic_sharia_authority_id` (`foreignId()->constrained('islamic_sharia_authorities')->cascadeOnDelete()`)
- `user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `member_role` (`string(32)`) values: `chair`, `reviewer`, `approver`, `observer`, `administrator`
- `scope` (`json()->nullable()`) optional fine-grained scope
- `starts_on` (`date`)
- `ends_on` (`date()->nullable()`)
- `status` (`string(32)->default('active')`) values: `active`, `suspended`, `revoked`, `expired`
- `evidence_document_id` (`foreignId()->nullable()->constrained('documents')->nullOnDelete()`)
- `created_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `updated_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- `metadata` (`json()->nullable()`)
- timestamps

Constraints:

- enum checks (`member_role`, `status`)
- `ends_on IS NULL OR ends_on > starts_on`
- unique active role key to prevent duplicate active same-role entries:
  - (`islamic_sharia_authority_id`, `user_id`, `member_role`, `starts_on`)

Proof by contradiction:

- Assume authority can be active with no mandate evidence: impossible (`document_id` required).
- Assume member has role text typo (`approvr`) and still approves: impossible (enum check).
- Assume membership validity window is inverted: impossible (`ends_on > starts_on`).

## Phase 2: Workflow, Routes, Authorization

### 2.1 New Workflow

Create:

- `app/Application/IslamicFinance/IslamicShariaAuthorityWorkflow.php`

Endpoints:

- `GET /api/v1/islamic-sharia-authorities`
- `POST /api/v1/islamic-sharia-authorities` (draft authority)
- `GET /api/v1/islamic-sharia-authorities/{authorityPublicId}`
- `PUT /api/v1/islamic-sharia-authorities/{authorityPublicId}` (draft only)
- `POST /api/v1/islamic-sharia-authorities/{authorityPublicId}/activate`
- `POST /api/v1/islamic-sharia-authorities/{authorityPublicId}/suspend`
- `POST /api/v1/islamic-sharia-authorities/{authorityPublicId}/revoke`
- `POST /api/v1/islamic-sharia-authorities/{authorityPublicId}/retire`
- `POST /api/v1/islamic-sharia-authorities/{authorityPublicId}/members`
- `PUT /api/v1/islamic-sharia-authorities/{authorityPublicId}/members/{memberPublicId}`
- `POST /api/v1/islamic-sharia-authorities/{authorityPublicId}/members/{memberPublicId}/suspend`
- `POST /api/v1/islamic-sharia-authorities/{authorityPublicId}/members/{memberPublicId}/revoke`

### 2.2 Validation Rules

Authority creation/update:

- required: name, authority_type, mandate_scope, mandate_summary, effective_date, evidence document
- optional: expiry_date

Authority activation:

- status must be `draft`
- evidence document must be active
- must have at least one active `approver` member and one active `chair` or `administrator` member

Member create/update:

- required: `user_public_id`, `member_role`, `starts_on`
- optional: `ends_on`, `scope`, `evidence_document_public_id`
- membership must fall within authority mandate period
- same user cannot hold conflicting active roles if policy forbids (at minimum cannot be both `observer` and `approver` simultaneously)

Self-approval policy:

- any material decision endpoint using authority checks must reject if actor equals requester/originator of reviewed subject.
- for current seam (`reviewCompliance`), keep existing requester self-check and additionally enforce authority self-check by subject decision context.

Proof by contradiction:

- Assume a non-mandated user approves by being platform-admin only: blocked by Sharia authority gate.
- Assume expired member approves: blocked by membership period and status checks.
- Assume requester approves own material decision with approver role: blocked by self-approval guard.

### 2.3 Authorization

Phase-1 admin surface:

- authority management remains platform-admin-gated for setup operations.

Decision authorization surface:

- compliance decision (`approve`) requires both:
  - existing endpoint authorization, and
  - active Sharia member role in `{chair, approver, administrator}` within valid mandate scope/date.

Declare permissions for migration:

- `islamic.sharia_authorities.view`
- `islamic.sharia_authorities.create`
- `islamic.sharia_authorities.update`
- `islamic.sharia_authorities.activate`
- `islamic.sharia_authorities.retire`
- `islamic.sharia_authorities.members.manage`
- `islamic.sharia.decisions.review`
- `islamic.sharia.decisions.approve`

## Phase 3: Reusable Authority Service

Create:

- `app/Application/IslamicFinance/IslamicShariaAuthorityService.php`

Core methods:

- `canReviewDecision(User $actor, string $decisionType, array $scope, CarbonInterface $asOf, ?int $requesterUserId = null): array{ok: bool, reason: string|null}`
- `assertCanApproveDecision(...)` helper raising domain exception on failure
- `activeMandateFailures(...)` for diagnostics/audit

Decision type examples:

- `islamic_product_compliance_approval`
- future use: `contract_template_activation`, `screening_policy_activation`, `mapping_activation`

Check logic:

- authority status active
- authority effective window valid
- member exists, role allowed, member status active
- member effective window valid
- scope match (institution/global or specific agency/product family where defined)
- no self-approval (`requesterUserId` mismatch)

Proof by contradiction:

- Assume authority is active but member mandate expired yesterday: service denies.
- Assume actor is reviewer-only and tries approval: service denies by role.

## Phase 4: Integrate With Current Approval Path

Update:

- `app/Application/IslamicFinance/IslamicProductWorkflow.php::reviewCompliance`

When `decision=approve`:

1. Keep existing readiness-gate checks.
2. Resolve requester user id from review row.
3. Call `IslamicShariaAuthorityService::assertCanApproveDecision` with decision type and scope derived from product attributes.
4. On denial, return `422` with structured error key `islamic_sharia_authority`.
5. Audit blocked approval attempt with reason.

This makes unauthorized/expired/self-approval impossible at the current material decision seam.

## Phase 5: Audit Events

Record:

- `islamic.sharia_authority.created`
- `islamic.sharia_authority.updated`
- `islamic.sharia_authority.activated`
- `islamic.sharia_authority.suspended`
- `islamic.sharia_authority.revoked`
- `islamic.sharia_authority.retired`
- `islamic.sharia_authority.member_added`
- `islamic.sharia_authority.member_updated`
- `islamic.sharia_authority.member_suspended`
- `islamic.sharia_authority.member_revoked`
- `islamic.sharia_authority.decision_blocked`

Audit properties include authority/member public ids, role, status transition, scope, decision type, and denial reason.

## Phase 6: API Contracts

Authority list/show returns:

- `public_id`, `name`, `authority_type`, `jurisdiction`, `status`, `effective_date`, `expiry_date`
- mandate summary and scope
- evidence document public id
- active member snapshot

Member payload returns:

- `public_id`, `user_public_id`, `member_role`, `status`, `starts_on`, `ends_on`, `scope`

No internal numeric IDs exposed.

## Phase 7: Tests

Add tests in:

- `tests/Feature/Api/IslamicFinanceTest.php`
- optionally new file: `tests/Feature/Api/IslamicShariaAuthorityTest.php`

Minimum tests:

1. Unauthorized user cannot approve Islamic product compliance decision.
2. User with active approver mandate can approve.
3. Expired mandate cannot approve.
4. Suspended/revoked mandate cannot approve.
5. Requester cannot self-approve even with approver mandate.
6. Authority activation fails without evidence doc or without required approver/chair membership.
7. Membership role validation rejects unknown role.
8. Membership date validation rejects invalid ranges.
9. Membership and mandate lifecycle changes create audit records.

Proof-by-contradiction alignment tests:

- `test_unauthorized_staff_cannot_approve`
- `test_expired_mandate_cannot_approve`
- `test_self_approval_is_rejected`

## Phase 8: Adversarial Review (Round 1)

Findings:

1. Risk: system could rely only on platform-admin role and bypass authority.
2. Risk: scope matching is underspecified and can permit over-broad approvals.
3. Risk: self-approval check could miss cases where requester id is null.

Fixes:

1. Approval path explicitly requires authority service check in addition to existing auth.
2. Service API includes explicit scope argument and decision type; default behavior denies if scope cannot be resolved.
3. If requester id is null for material decisions, service treats as deny unless explicit policy allows (default deny for IF-010).

## Phase 9: Adversarial Review (Round 2)

Findings:

1. Ambiguity: multiple active authorities with contradictory member decisions.
2. Ambiguity: whether one approver is enough for approval.
3. Risk: chair and approver separation may be required for critical decisions.

Fixes:

1. Deterministic rule: authorization passes if at least one active eligible authority-member tuple grants approval and none of policy-level deny conditions apply.
2. IF-010 baseline uses single qualified approver as minimum; quorum/dual-control is deferred to IF-011 workflow policy extension.
3. Add explicit extension hook in service options for `required_approver_count` and `require_chair_countersign`.

## Phase 10: Adversarial Review (Round 3)

Findings:

1. IF-010 scope includes "mandate changes" audit; plan initially emphasized member changes more than authority mandate diffs.
2. No explicit contradiction test for mandate-scope mismatch.

Fixes:

1. Authority update path now explicitly audits mandate diff fields (`mandate_scope`, `mandate_summary`, dates, evidence).
2. Added mandatory test: scope-mismatched authority member cannot approve out-of-scope decision.

## Phase 11: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Can authority records exist with explicit mandate, scope, dates, and evidence? Yes.
- Can member roles be only one of chair/reviewer/approver/observer/administrator? Yes.
- Can unauthorized staff approve material Islamic decisions? No.
- Can expired/suspended/revoked mandate approve? No.
- Can requester self-approve material decision? No.
- Are membership changes and mandate changes audited? Yes.
- Is enforcement wired into current material decision seam (`reviewCompliance` approve path)? Yes.
- Is the authority gate reusable for later IF-011/IF-020/IF-051 decision points? Yes.

## Test Execution Instructions

Use these commands during IF-010 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Islamic finance feature work
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused authority checks (if separated into dedicated file)
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicShariaAuthorityTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Evidence (2026-05-25)

Contradiction audit outcome:

1. Non-mandated platform-admin approval attempts are blocked by Sharia authority gate.
2. Expired/suspended/revoked mandates, reviewer-only roles, scope mismatches, and self-approval attempts are all rejected.
3. Authority/member lifecycle and decision-block events are auditable in `activity_log`.
4. No additional implementation gap was found during this IF-010 adversarial review pass.

Verification runs:

```bash
php artisan test --parallel --recreate-databases --filter IslamicShariaAuthorityTest
```

Result:
- `OK (16 tests, 191 assertions)` in ~8.5s.

```bash
composer test
```

Result:
- `OK (565 tests, 8548 assertions)` in ~41.7s.
