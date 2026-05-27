# IF-042 Implementation Plan: Istisna'a Project Registry

Date: 2026-05-24
Status: implemented and verified
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-042
Proof-method: proof by contradiction

## IF-042 Source Requirement

Goal: track construction or manufacturing obligations.

Proof-by-contradiction invariant: assume a milestone payment is released without inspection evidence. Payment must be rejected.

Acceptance criteria:

- Store project specification, contractor, customer, site, milestones, payment plan, inspection rules, variation orders, acceptance criteria, and delivery evidence.
- Support parallel supplier contract reference where approved.
- Variation order creates versioned before/after values.

Tests:

- Payment requires approved milestone evidence.
- Variation cannot change already posted payment facts.
- Acceptance closes project obligation only after evidence.

## Architecture Context

Current state:

- Screening/compliance already supports `islamic_project` subject and `project_approval` blocker context.
- No first-class Istisna project registry, milestone model, or variation-order versioning exists.
- No payment-release gate currently binds milestone evidence to posting authorization.

Primary contradiction gap:

- Project obligations can progress without a canonical milestone/evidence ledger proving inspection approval before payment.

## Completion Definition For This Plan

IF-042 is sound only if all are true:

- Istisna project records capture all required specification and governance fields.
- Milestone payments cannot post without approved inspection evidence.
- Variation orders are versioned with immutable before/after snapshots.
- Project acceptance only closes obligations when evidence criteria are satisfied.

## Phase 1: Istisna Project Data Model

Create migration:

- `create_islamic_istisna_project_tables`

Tables:

- `islamic_istisna_projects`
- `islamic_istisna_milestones`
- `islamic_istisna_variation_orders`
- optional `islamic_istisna_project_events`

`islamic_istisna_projects` fields:

- public id
- financing/contract reference
- project specification
- contractor reference
- customer reference
- site/location
- inspection rules
- acceptance criteria
- status
- metadata

`islamic_istisna_milestones` fields:

- milestone code/title
- planned amount
- due date
- inspection requirement
- evidence status
- payment status
- paid journal reference (nullable)

## Phase 2: Milestone Payment Gate

Introduce `assertMilestonePayable` gate before any milestone payment posting:

- milestone exists and is active
- inspection evidence approved
- milestone not already fully paid
- payment amount within remaining approved milestone amount

Proof by contradiction:

- Assume payment posts without inspection evidence. Impossible because gate rejects before posting.

## Phase 3: Inspection Evidence Workflow

Model inspection evidence records:

- evidence document references
- inspector identity
- inspection decision (`approved`, `rejected`, `needs_rework`)
- decision timestamp/comments

Payment release rule:

- only `approved` evidence can unlock milestone payment.

## Phase 4: Variation Order Versioning

Variation order behavior:

- each variation stores immutable `before_snapshot` and `after_snapshot`.
- affects only unpaid/unposted milestone and project terms allowed by policy.
- posted payment facts are immutable and cannot be retroactively changed.

Proof by contradiction:

- Assume variation reduces already-posted milestone amount. Impossible because posted milestone fields are write-protected and variation validator rejects changes.

## Phase 5: Parallel Supplier Contract Reference

Support approved parallel supplier links:

- project can reference supplier contract(s) with approval status.
- if parallel link required by policy, project activation/approval blocks until approved supplier reference exists.

## Phase 6: Acceptance Closure Gate

Project obligation closure requires:

- all required milestones completed or settled per approved variation.
- acceptance evidence attached and approved.
- no active blocking compliance case for `project_approval` context.

Only then status transitions to `accepted/closed`.

## Phase 7: Screening And Compliance Integration

At project approval and milestone acceptance boundaries:

- run screening in `project_approval` context.
- `fail` blocks approval/payment release.
- `manual_review` opens compliance case and blocker.

## Phase 8: API Surface

Endpoints:

- `POST /api/v1/islamic-istisna-projects`
- `GET /api/v1/islamic-istisna-projects/{projectPublicId}`
- `POST /api/v1/islamic-istisna-projects/{projectPublicId}/milestones`
- `POST /api/v1/islamic-istisna-milestones/{milestonePublicId}/inspection`
- `POST /api/v1/islamic-istisna-milestones/{milestonePublicId}/payments`
- `POST /api/v1/islamic-istisna-projects/{projectPublicId}/variations`
- `POST /api/v1/islamic-istisna-projects/{projectPublicId}/accept`
- `GET /api/v1/islamic-istisna-projects/{projectPublicId}/timeline`

## Phase 9: Audit Trail

Record events:

- `islamic.istisna_project.created`
- `islamic.istisna_milestone.inspection_recorded`
- `islamic.istisna_milestone.payment_blocked`
- `islamic.istisna_milestone.payment_released`
- `islamic.istisna_variation.approved`
- `islamic.istisna_project.accepted`

## Phase 10: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicIstisnaProjectRegistryTest.php`

Minimum tests:

1. `test_milestone_payment_requires_approved_inspection_evidence`
2. `test_milestone_payment_blocked_when_inspection_rejected`
3. `test_variation_order_creates_before_after_versioned_snapshot`
4. `test_variation_cannot_change_already_posted_payment_facts`
5. `test_project_acceptance_requires_approved_delivery_evidence`
6. `test_parallel_supplier_contract_reference_required_when_policy_enabled`
7. `test_project_approval_screening_fail_blocks_progress`
8. `test_project_approval_manual_review_opens_compliance_case`
9. `test_milestone_payment_cannot_exceed_remaining_approved_amount`
10. `test_project_timeline_is_append_only`

Proof-by-contradiction acceptance alignment tests:

- `test_payment_requires_approved_milestone_evidence`
- `test_variation_cannot_change_posted_payment_facts`
- `test_acceptance_closes_only_after_evidence`

## Phase 11: Adversarial Review (Round 1)

Findings:

1. Risk: milestone evidence captured but not bound to payment authorization.
2. Risk: variation orders applied directly to mutable rows with no immutable snapshot.
3. Risk: acceptance status can be toggled manually without closure criteria.

Fixes:

1. enforce payment gate requiring approved inspection decision.
2. immutable before/after snapshots per variation.
3. acceptance transition guarded by closure rule engine.

## Phase 12: Adversarial Review (Round 2)

Findings:

1. Ambiguity: partial milestone completion may allow overpayment if quantity/progress tracking is absent.
2. Risk: screening applied only at project creation and skipped at approval/payment boundaries.
3. Risk: concurrent payment requests can double-post same milestone.

Fixes:

1. track milestone remaining payable and enforce monotonic decrement.
2. run screening at approval-critical transitions.
3. lock milestone rows and enforce idempotency key on payment release operations.

## Phase 13: Adversarial Review (Round 3)

Findings:

1. IF-042 requires parallel supplier contract support "where approved"; missing approval linkage weakens control.
2. Posted journal reversals might be abused to rewrite historical milestone facts.
3. CI may pass CRUD tests without proving payment-gate invariants.

Fixes:

1. require explicit supplier-link approval state before activation when policy requires it.
2. preserve posted-fact immutability; corrections only through reversal/correction events with audit chain.
3. add end-to-end tests from project setup to payment release and acceptance closure.

## Phase 14: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are all required Istisna project/milestone/inspection fields captured? Yes.
- Is milestone payment impossible without approved inspection evidence? Yes.
- Are variation orders versioned with immutable before/after snapshots? Yes.
- Are posted payment facts protected from retroactive change? Yes.
- Does project acceptance close only after evidence-backed completion criteria? Yes.

## Test Execution Instructions

Use these commands during IF-042 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Istisna project registry and milestone-gate changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicIstisnaProjectRegistryTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume Istisnaa financing approval still depends on Murabaha-specific posting chain.
   - Contradiction: non-Mourabaha branch now approves without Murabaha installment/origination/mapping/journal requirements.
2. Assume milestone payments can pass without `project_approval` screening gate.
   - Contradiction: payment release now executes project-approval screening and blocks on `fail`/`manual_review`.
3. Assume project acceptance can close without `project_approval` screening gate.
   - Contradiction: acceptance flow now executes the same gate and blocks on `fail`/`manual_review`.
4. Assume financing activation for Istisnaa ignores project-approval screening.
   - Contradiction: readiness assertion now evaluates `project_approval` screening before financing approval.
5. Assume IF-042 timeline query surface exists as planned.
   - Contradiction resolved: `GET /api/v1/islamic-istisnaa-projects/{projectPublicId}/timeline` is now implemented and covered by feature tests.
6. Assume financing approval validates only one linked Istisnaa project and can ignore non-compliant sibling projects.
   - Contradiction: readiness gate now validates *all* linked projects; any missing milestones, unapproved parallel supplier reference, or screening blocker aborts approval.
7. Assume new Istisnaa projects can still be linked to non-draft financings and mutate already-approved financings.
   - Contradiction: project creation now rejects `islamic_financing_public_id` when financing status is not `draft`.

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
php artisan test --parallel --recreate-databases --filter 'test_if042_financing_approval_validates_all_linked_projects_not_just_first|test_if042_project_linking_to_non_draft_financing_is_rejected'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

- Passed: `OK (34 tests, 712 assertions)` for `test_if040_|test_if041_|test_if042_`.
- Passed: `OK (3 tests, 102 assertions)` for the new IF-041/042 contradiction hardening tests.
- Passed: `OK (139 tests, 3417 assertions)` for `IslamicFinanceTest`.
- Passed: `OK (626 tests, 9918 assertions)` for `composer test`.
