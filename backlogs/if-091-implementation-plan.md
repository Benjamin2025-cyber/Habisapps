# IF-091 Implementation Plan: Istisna'a Project Workflow

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-091
Proof-method: proof by contradiction

## IF-091 Source Requirement

Goal: originate, fund, inspect, vary, deliver, and close manufacturing/construction contracts.

Proof-by-contradiction invariant: assume contractor payment occurs without approved milestone. Posting must fail.

Acceptance criteria:

- Capture project specs, parties, milestones, payment plan, inspections, contract value, and delivery criteria.
- Approve milestone before payment.
- Approve variation before changing future obligations.
- Close only after delivery and acceptance evidence.

Tests:

- Payment without approved milestone rejected.
- Variation after posted milestone cannot rewrite original journal.
- Delivery without acceptance rejected.

## Architecture Context

Current state:

- Islamic financing/product flows are still centered around Murabaha and do not expose an Istisna'a project lifecycle.
- No dedicated project entities for Istisna'a milestones, inspections, variations, and delivery acceptance.
- No milestone-approval gate presently controls contractor payment posting.
- No variation workflow currently prevents retroactive rewrite of posted accounting outcomes.

Current contradiction gaps:

- Payment can’t be reliably constrained by milestone approval because milestone workflow is absent.
- Variation governance is not modeled; future obligations vs historical postings are not protected.
- Delivery closure/acceptance evidence gating is missing for project closure.

## Completion Definition For This Plan

IF-091 is sound only if all are true:

- Istisna'a projects capture full project and party data with governed milestones.
- Contractor payment cannot post before milestone approval.
- Variations are approved before future-obligation changes and cannot rewrite posted journals.
- Closure requires delivery plus acceptance evidence.

## Phase 1: Istisna'a Project Domain Model

Introduce project entities:

- `islamic_istisnaa_projects`
- `islamic_istisnaa_milestones`
- `islamic_istisnaa_inspections`
- `islamic_istisnaa_variations`
- `islamic_istisnaa_delivery_events`

Project captures:

- project specs
- parties/counterparties
- payment plan
- contract value/currency
- delivery criteria
- evidence references

## Phase 2: Workflow State Machine

Define lifecycle states:

- `draft`
- `approved`
- `in_execution`
- `delivery_pending_acceptance`
- `completed`
- `closed`
- `disputed` (as needed)

Milestone states:

- `defined`
- `inspection_pending`
- `inspection_passed`
- `approved_for_payment`
- `paid`

## Phase 3: Milestone-Gated Payment Engine

Implement payment posting service bound to milestones:

- require milestone in `approved_for_payment`
- block posting otherwise
- enforce mapping lifecycle/effective-window checks
- record journal references per milestone payment

Proof by contradiction:

- Assume payment posts without approved milestone. Impossible once posting guard verifies milestone approval under row lock.

## Phase 4: Inspection Workflow

Inspection operations:

- create inspection criteria and checklist
- submit inspection evidence
- decide pass/fail/conditional pass
- only pass/approved outcome permits payment progression

## Phase 5: Variation Workflow And Forward-Only Mutation

Variation handling:

- variation request with reason, scope, impact
- approval required before changing future milestones/payment obligations
- posted journals remain immutable; variations create new forward adjustments only

Proof by contradiction:

- Assume approved variation rewrites posted milestone journal. Contradiction with immutable posting invariant.

## Phase 6: Delivery, Acceptance, And Closure Gates

Delivery process:

- record delivery event and evidence
- record acceptance decision/evidence
- closure blocked until delivery + acceptance evidence complete

Reject closure without acceptance artifacts.

## Phase 7: Accounting Linkage

Posting flows:

- milestone payment postings through approved Istisna'a mappings
- variation-related adjustment postings as separate entries
- final settlement/close postings with source linkage

Interest-class operation codes rejected.

## Phase 8: API Surface

Endpoints (illustrative):

- `POST /api/v1/islamic-istisnaa-projects`
- `POST /api/v1/islamic-istisnaa-projects/{projectPublicId}/approve`
- `POST /api/v1/islamic-istisnaa-projects/{projectPublicId}/milestones`
- `POST /api/v1/islamic-istisnaa-milestones/{milestonePublicId}/inspections`
- `POST /api/v1/islamic-istisnaa-milestones/{milestonePublicId}/approve-payment`
- `POST /api/v1/islamic-istisnaa-milestones/{milestonePublicId}/post-payment`
- `POST /api/v1/islamic-istisnaa-projects/{projectPublicId}/variations`
- `POST /api/v1/islamic-istisnaa-projects/{projectPublicId}/deliveries`
- `POST /api/v1/islamic-istisnaa-projects/{projectPublicId}/acceptance`
- `POST /api/v1/islamic-istisnaa-projects/{projectPublicId}/close`

## Phase 9: Audit Trail

Record events:

- `islamic.istisnaa.project_created`
- `islamic.istisnaa.milestone_defined`
- `islamic.istisnaa.payment_blocked_missing_approved_milestone`
- `islamic.istisnaa.milestone_payment_posted`
- `islamic.istisnaa.variation_requested`
- `islamic.istisnaa.variation_approved`
- `islamic.istisnaa.delivery_recorded`
- `islamic.istisnaa.acceptance_recorded`
- `islamic.istisnaa.closure_blocked_missing_acceptance`
- `islamic.istisnaa.project_closed`

## Phase 10: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IstisnaaProjectWorkflowTest.php`

Minimum tests:

1. `test_istisnaa_project_captures_specs_parties_milestones_and_delivery_criteria`
2. `test_payment_without_approved_milestone_is_rejected`
3. `test_payment_after_milestone_approval_posts_successfully`
4. `test_variation_requires_approval_before_future_obligation_change`
5. `test_variation_cannot_rewrite_posted_milestone_journal`
6. `test_delivery_without_acceptance_blocks_closure`
7. `test_delivery_with_acceptance_allows_closure`
8. `test_inspection_failure_blocks_milestone_payment`

Proof-by-contradiction acceptance alignment tests:

- `test_payment_without_approved_milestone_rejected`
- `test_variation_after_posted_milestone_cannot_rewrite_original_journal`
- `test_delivery_without_acceptance_rejected`

## Phase 11: Adversarial Review (Round 1)

Findings:

1. Risk: milestone approval and payment posting split across endpoints may allow race-based bypass.
2. Risk: variation updates may accidentally mutate already-paid milestone amounts.
3. Risk: acceptance evidence might be optional due to nullable payload handling.

Fixes:

1. lock milestone row and re-check approval state in payment transaction.
2. enforce immutability constraints on paid/posting-linked milestones.
3. require non-null acceptance evidence schema for closure.

## Phase 12: Adversarial Review (Round 2)

Findings:

1. Ambiguity: inspection pass criteria may be too loose for payment gating.
2. Risk: multiple payments on same milestone without idempotency controls.
3. Risk: mapping validity can expire between pre-check and posting commit.

Fixes:

1. define explicit quantitative/qualitative inspection pass thresholds.
2. idempotency key + unique milestone-payment source references.
3. re-validate mapping status/effective window in-transaction.

## Phase 13: Adversarial Review (Round 3)

Findings:

1. Risk: closure may ignore unresolved disputes/defects and still pass.
2. Risk: audit logs may not link milestone decisions to journals strongly enough.
3. Risk: tests may assert HTTP status only, missing state transition assertions.

Fixes:

1. block closure when open disputes/critical defects exist unless approved exception.
2. persist milestone->inspection->approval->journal chain references.
3. assert state transitions and linkage integrity in feature tests.

## Phase 14: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are milestone approvals mandatory before contractor payments post? Yes.
- Can variation adjust future obligations only, without rewriting posted journals? Yes.
- Is closure blocked until delivery and acceptance evidence are complete? Yes.
- Are inspection outcomes, payments, variations, and closures auditable end-to-end? Yes.

## Test Execution Instructions

Use these commands during IF-091 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Istisna'a project workflow changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IstisnaaProjectWorkflowTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume contractor payment can post without approved milestone/inspection gate.
   - Contradiction: milestone payment posting rejects non-approved inspection state before posting.
2. Assume variation can change future obligations without approval evidence.
   - Contradiction: variation endpoint now requires `approval_evidence_document_public_id`; missing evidence blocks request validation.
3. Assume project closure can proceed without acceptance evidence.
   - Contradiction: acceptance/closure endpoint requires `acceptance_evidence_document_public_id` and rejects missing evidence.
4. Assume posted milestone obligations can be rewritten by later variation.
   - Contradiction: variation flow still blocks changes when paid facts already exist.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if091_'
php artisan test --parallel --recreate-databases --filter 'test_if040_|test_if041_|test_if042_|test_if080_|test_if081_|test_if090_|test_if091_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `test_if091_` subset passed within targeted bundle.
- `test_if040_|test_if041_|test_if042_|test_if080_|test_if081_|test_if090_|test_if091_` passed: `OK (47 tests, 919 assertions)`.
- `IslamicFinanceTest` passed: `OK (152 tests, 3624 assertions)`.
- `composer test` passed: `OK (639 tests, 10125 assertions)`.
