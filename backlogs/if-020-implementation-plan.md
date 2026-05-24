# IF-020 Implementation Plan: Screening Policy Configuration

Date: 2026-05-24
Status: implementation plan
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-020
Proof-method: proof by contradiction

## IF-020 Source Requirement

Goal: configure licit and prohibited activity screening for all Islamic products.

Proof-by-contradiction invariant: assume a prohibited activity passes because the rule is only informational. Contract approval must be blocked by active screening policy.

Acceptance criteria:

- Configure prohibited sectors, restricted sectors, prohibited goods, restricted goods, supplier flags, customer business flags, source/use-of-funds flags, and escalation rules.
- Version policies and require Sharia approval before activation.
- Preserve policy snapshot on each screening result.
- Support manual override only through approved exception workflow.

Tests:

- Prohibited sector blocks product approval.
- Restricted sector creates compliance review.
- Policy version is snapshotted on result.

## Architecture Context

Current code has:

- reusable IF-011 approval workflow (`IslamicApprovalWorkflowService` + subject type `islamic_screening_policy`),
- IF-012 compliance case service with blockers/escalation decisions,
- Islamic product readiness approval path in `IslamicProductWorkflow::reviewCompliance`.

IF-020 should add screening-policy registry and evaluation rules now, then integrate product approval gate first; IF-021 will extend execution breadth to more subject types.

## Completion Definition For This Plan

IF-020 is sound only if all are true:

- Screening policy is a versioned, explicit record with structured rule sets.
- Active policy requires Sharia approval workflow state `approved` and effective validity.
- Prohibited matches deterministically block approval/use.
- Restricted matches deterministically escalate to compliance case.
- Every screening result stores immutable policy snapshot and outcome rationale.
- Manual override cannot bypass without approved exception workflow linkage.

## Phase 1: Policy Data Model

Create migration:

- `database/migrations/YYYY_MM_DD_HHMMSS_create_islamic_screening_policy_tables.php`

### 1.1 `islamic_screening_policies`

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `code` (`string(64)`)
- `name` (`string(191)`)
- `version` (`unsignedInteger()->default(1)`)
- `scope_type` (`string(32)`) values: `institution`, `agency`, `product_family`
- `scope_value` (`string(128)->nullable()`)
- `status` (`string(32)->default('draft')`) values: `draft`, `active`, `suspended`, `revoked`, `expired`, `archived`
- `effective_from` (`date()->nullable()`)
- `effective_to` (`date()->nullable()`)
- `description` (`text()->nullable()`)
- `document_id` (`foreignId()->nullable()->constrained('documents')->nullOnDelete()`)
- `created_by_user_id` (`foreignId()->constrained('users')->restrictOnDelete()`)
- `metadata` (`json()->nullable()`)
- timestamps

Constraints:

- unique (`code`, `version`)
- valid status enum
- valid scope_type enum
- `effective_to IS NULL OR effective_to > effective_from`

### 1.2 `islamic_screening_policy_rules`

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `policy_id` (`foreignId()->constrained('islamic_screening_policies')->cascadeOnDelete()`)
- `rule_type` (`string(64)`) values:
  - `prohibited_sector`
  - `restricted_sector`
  - `prohibited_goods`
  - `restricted_goods`
  - `supplier_flag`
  - `customer_business_flag`
  - `source_of_funds_flag`
  - `use_of_funds_flag`
  - `escalation_rule`
- `match_key` (`string(128)`) canonical code/flag key
- `match_operator` (`string(32)->default('equals')`) values: `equals`, `contains`, `starts_with`, `regex`
- `risk_level` (`string(16)->nullable()`) `low|medium|high|critical`
- `action` (`string(32)`) values: `block`, `manual_review`, `allow_with_note`
- `priority` (`unsignedInteger()->default(100)`)
- `is_active` (`boolean()->default(true)`)
- `metadata` (`json()->nullable()`)
- timestamps

Constraints:

- valid `rule_type` enum
- valid `action` enum
- unique (`policy_id`, `rule_type`, `match_key`, `priority`)

### 1.3 `islamic_screening_results`

Columns:

- `id` (PK)
- `public_id` (`ulid()->unique()`)
- `subject_type` (`string(64)`) values aligned with IF-021 target domains
- `subject_public_id` (`string(64)`)
- `context_type` (`string(64)`) e.g. `product_approval`, `contract_approval`
- `policy_public_id` (`string(64)`)
- `policy_version` (`unsignedInteger`)
- `policy_snapshot` (`json`) immutable policy+rule snapshot at evaluation time
- `result` (`string(32)`) values: `pass`, `fail`, `manual_review`, `expired`, `not_applicable`
- `matched_rules` (`json()->nullable()`)
- `block_reason` (`text()->nullable()`)
- `review_case_public_id` (`string(64)->nullable()`)
- `evaluated_at` (`timestamp()`)
- `evaluated_by_user_id` (`foreignId()->nullable()->constrained('users')->nullOnDelete()`)
- timestamps

Constraints:

- valid `result` enum
- `policy_snapshot` required and immutable after insert

Proof by contradiction:

- Assume policy is active without version identity. Impossible (`code+version` unique with recorded version in result snapshot).
- Assume prohibited rule can only annotate and not block. Impossible (`action=block` enforced by evaluation gate).
- Assume result can be recomputed later with changed rules and lose historical context. Impossible (`policy_snapshot` stored immutably).

## Phase 2: Policy Workflow and Sharia Approval Link

Create:

- `app/Application/IslamicFinance/IslamicScreeningPolicyWorkflow.php`

Endpoints:

- `GET /api/v1/islamic-screening-policies`
- `POST /api/v1/islamic-screening-policies`
- `GET /api/v1/islamic-screening-policies/{policyPublicId}`
- `PUT /api/v1/islamic-screening-policies/{policyPublicId}` (draft only)
- `POST /api/v1/islamic-screening-policies/{policyPublicId}/rules`
- `PUT /api/v1/islamic-screening-policies/{policyPublicId}/rules/{rulePublicId}`
- `DELETE /api/v1/islamic-screening-policies/{policyPublicId}/rules/{rulePublicId}`
- `POST /api/v1/islamic-screening-policies/{policyPublicId}/activate`
- `POST /api/v1/islamic-screening-policies/{policyPublicId}/suspend`
- `POST /api/v1/islamic-screening-policies/{policyPublicId}/revoke`
- `POST /api/v1/islamic-screening-policies/{policyPublicId}/archive`

Activation requirements:

- policy state draft/submitted eligible by workflow rules.
- at least one active rule exists.
- approval workflow subject `islamic_screening_policy` is `approved`.
- effective window valid.

Proof by contradiction:

- Assume unapproved policy becomes active. Impossible because activation checks IF-011 workflow state.
- Assume empty policy becomes active. Impossible due to minimum one-rule activation check.

## Phase 3: Screening Evaluation Service

Create:

- `app/Application/IslamicFinance/IslamicScreeningPolicyService.php`

Core methods:

- `resolveActivePolicy(scopeType, scopeValue, asOf): ?object`
- `evaluate(subjectType, subjectPublicId, contextType, facts, actor): array`
- `persistResult(...)`

Evaluation precedence:

1. prohibited/block matches => `fail` + blocking reason
2. restricted/manual_review matches => `manual_review`
3. explicit allow_with_note or no matches => `pass`
4. no active policy in scope => `not_applicable` (policy-level behavior; caller may still require mandatory policy and convert to fail)

Result persistence:

- always write `islamic_screening_results` with `policy_snapshot`.
- include matched rules, rationale, and references.

## Phase 4: Escalation to Compliance Cases (IF-012 Integration)

When result is `manual_review`:

- open compliance case (`islamic_compliance_cases`) with reason `screening_restricted_match`.
- assign blocker type according to context, e.g.:
  - `product_activation` for product approval context,
  - `contract_activation` for contract approval context.

When result is `fail`:

- block action immediately.
- optionally open informational case for audit/remediation, based on policy flag.

Manual override:

- cannot directly flip screening fail/manual_review to pass.
- only approved exception workflow (subject `islamic_exception`) may allow override, and override reason/evidence must be referenced in final result metadata.

Proof by contradiction:

- Assume restricted result does not create review case. Impossible because evaluator routes `manual_review` through compliance case creation.
- Assume manual override bypasses exception workflow. Impossible: override path validates approved exception subject before allowing.

## Phase 5: Product Approval Gate Integration

Update:

- `app/Application/IslamicFinance/IslamicProductReadinessService.php`
- `app/Application/IslamicFinance/IslamicProductWorkflow.php::reviewCompliance`

Behavior:

- before approving product compliance review, run screening evaluation for `context_type=product_approval`.
- `fail` => readiness failure key `islamic_screening_policy`.
- `manual_review` => readiness failure key `islamic_screening_policy` + compliance case reference.
- `pass` => continue existing gates.

This satisfies IF-020 test coverage before IF-021 broadens execution to other domains.

## Phase 6: API and Reporting Surface

Add endpoints:

- `POST /api/v1/islamic-screening/evaluate` (admin/internal use)
- `GET /api/v1/islamic-screening-results`
- `GET /api/v1/islamic-screening-results/{resultPublicId}`

Response fields:

- result public id
- subject/context identifiers
- result state
- matched rule summary
- policy public id/version
- policy snapshot checksum/hash
- review case reference (if escalated)

No internal numeric IDs exposed.

## Phase 7: Audit Events

Record:

- `islamic.screening_policy.created`
- `islamic.screening_policy.updated`
- `islamic.screening_policy.activated`
- `islamic.screening_policy.suspended`
- `islamic.screening_policy.revoked`
- `islamic.screening_policy.archived`
- `islamic.screening.evaluated`
- `islamic.screening.blocked`
- `islamic.screening.manual_review_routed`
- `islamic.screening.override_denied`
- `islamic.screening.override_approved`

## Phase 8: Tests

Add tests in:

- `tests/Feature/Api/IslamicFinanceTest.php`
- optional focused file: `tests/Feature/Api/IslamicScreeningPolicyTest.php`

Minimum tests:

1. prohibited sector rule blocks product approval.
2. restricted sector rule creates compliance case and blocks approval until resolved.
3. policy result stores immutable snapshot with policy version.
4. policy activation fails unless approval workflow state is approved.
5. policy with no rules cannot activate.
6. manual override without approved exception workflow is rejected.
7. approved exception workflow allows override with audit trail.
8. expired policy does not evaluate as active.
9. scoped policy resolution picks correct version/scope precedence.
10. unknown rule type/action payload rejected.

Proof-by-contradiction alignment tests:

- `test_prohibited_sector_blocks_product_approval`
- `test_restricted_sector_creates_compliance_review`
- `test_policy_version_is_snapshotted_on_result`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: policies become advisory if not plugged into approval gate.
2. Risk: snapshot may accidentally store only policy id/version, not full rules.
3. Risk: restricted matches may be silently treated as pass by callers.

Fixes:

1. explicit integration into product approval readiness gate.
2. require full policy+rule snapshot payload in result record.
3. evaluator returns typed outcomes and service enforces `manual_review` escalation path.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: multiple active policies may overlap scope and conflict.
2. Ambiguity: no-policy state could create accidental permissive behavior.
3. Risk: regex/contains operators may introduce unsafe or inconsistent matching.

Fixes:

1. deterministic policy resolution precedence:
   - `product_family` scope > `agency` scope > `institution` scope,
   - newest approved version wins within same scope.
2. caller-defined strict mode for mandatory-policy contexts (product approval strict mode: no active policy => fail).
3. constrain operator support and add validation/performance guardrails for regex patterns.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. IF-020 requires customer/supplier/source-use flags configuration, but initial integration seam is product approval.
2. Risk of partial implementation where data model lacks those rule types even if runtime use is phased.

Fixes:

1. rule taxonomy includes all required flag categories from day one.
2. IF-020 plan enforces configuration/storage completeness now; IF-021 handles broader execution coverage sequencing.
3. tests include payload validation for all required rule-type families even where runtime context is deferred.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are all required screening rule categories configurable? Yes.
- Is policy versioning explicit and tied to Sharia approval before activation? Yes.
- Can prohibited matches deterministically block approval/use? Yes.
- Can restricted matches deterministically route to compliance review cases? Yes.
- Is policy snapshot persisted immutably on every screening result? Yes.
- Is manual override impossible without approved exception workflow linkage? Yes.
- Is product approval currently gated by screening result (IF-020 seam) while broader execution waits for IF-021? Yes.
- Are policy lifecycle and screening decisions auditable end-to-end? Yes.

## Test Execution Instructions

Use these commands during IF-020 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for screening-policy + product-approval gate work
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused screening tests (if extracted)
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicScreeningPolicyTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
