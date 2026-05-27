# IF-071 Implementation Plan: Ijara Contract And Rental Schedule

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-071
Proof-method: proof by contradiction

## IF-071 Source Requirement

Goal: activate lease contracts backed by owned or controlled assets.

Proof-by-contradiction invariant: assume lease activates without institution asset ownership/control. Activation must fail.

Acceptance criteria:

- Capture asset, condition report, lease term, rental schedule, customer obligations, institution obligations, and evidence.
- Post rental receivable and rental income per approved mapping.
- Handle asset damage, rental suspension, early termination, and transfer.

Tests:

- No owned asset blocks activation.
- Rental schedule excludes sale-price interest logic.
- Damage event creates approved workflow.

## Architecture Context

Current state:

- `IslamicFinancingWorkflow::storeFinancing` still restricts financing `contract_type` to `murabaha` and requires Murabaha pricing fields.
- `approveFinancing` enforces Murabaha-only preconditions/messages, postings (`murabaha_receivable`, `murabaha_payable`, `murabaha_profit`), and ownership status transitions.
- Installment generation enforces total = `sale_price_minor`, which is Murabaha logic and unsuitable for Ijara rental schedules.
- `islamic_financed_assets` exists with `ownership_status`, but no dedicated Ijara lease activation lifecycle captures condition reports, lease obligations, or rental-specific evidence.

Current contradiction gaps:

- Ijara contract activation path is not first-class in financing workflow.
- Ownership/control gating for lease activation is not explicit for Ijara semantics.
- Rental receivable/income posting flow is missing and currently conflated with Murabaha sale-price accounting.
- No explicit damage/suspension/early-termination/transfer event workflow for leased assets.

## Completion Definition For This Plan

IF-071 is sound only if all are true:

- Ijara contract activation exists and requires owned/controlled lease asset evidence.
- Rental schedule modeling is distinct from Murabaha sale-price installments.
- Rental receivable and rental income postings use approved Ijara mappings.
- Damage/suspension/early-termination/transfer lifecycle events are governed and auditable.

## Phase 1: Ijara Financing Contract Path

Extend financing creation/approval to support `contract_type = ijara` with dedicated payload contract:

- lease term (`starts_on`, `ends_on`, derived period checks)
- customer obligations and institution obligations (structured terms)
- lease evidence references (document/media IDs)
- optional transfer intent metadata only when product variant permits

Proof by contradiction:

- Assume IF-071 complete while financing API still rejects `ijara`. Impossible because no Ijara contract can be created/activated.

## Phase 2: Asset Control And Condition Capture

Before lease activation, require:

- linked financed asset in `owned_by_institution` or approved `controlled_by_institution` state
- condition report snapshot (structured fields + evidence links)
- asset eligibility checks against product policy/category

Activation must fail when asset ownership/control proof is absent.

## Phase 3: Rental Schedule Model (Non-Murabaha)

Introduce Ijara rental schedule semantics separate from Murabaha sale-price rules:

- schedule lines (`due_on`, `rental_amount_minor`, status)
- optional non-rental residual transfer amount excluded from rental sum
- explicit prohibition of Murabaha sale-price formula coupling

Invariant:

- Ijara rental schedule validation never depends on `sale_price_minor = purchase_cost + costs + markup` formula.

## Phase 4: Ijara Accounting Posting Engine

At activation and rental accrual/collection stages:

- post rental receivable and rental income via approved Ijara operation mappings
- enforce mapping status/effective-window checks
- reject interest-labeled operation codes via Islamic interest guard

Required mapping families (example naming aligned to IF-050/IF-051 policy):

- `ijara_rental_receivable`
- `ijara_rental_income`
- additional approved codes for suspension/termination/transfer adjustments

## Phase 5: Damage, Suspension, Early Termination, Transfer Workflows

Add governed lease-event workflows:

- asset damage event: captures incident, evidence, financial treatment route, approvals
- rental suspension event: pauses rental accrual/collections under approved rules
- early termination event: computes settlement adjustments and postings
- transfer event hook: routes into IF-072 transfer workflow only

Proof by contradiction:

- Assume damage can be recorded without workflow/evidence. Then auditability/governance fails, contradicting IF-071 acceptance.

## Phase 6: State Machine And Guardrails

Define explicit Ijara lifecycle states (example):

- `draft -> ready_for_activation -> active -> suspended -> terminated -> transferred/closed`

Guards:

- activation requires ownership/control + condition report + rental schedule
- transfer route blocked unless transfer-capable variant/approved event
- direct field mutation for ownership transfer forbidden (reserved for IF-072 workflow)

## Phase 7: API Surface

Endpoints (new or specialized existing routes):

- `POST /api/v1/islamic-financings` (`contract_type=ijara` path)
- `POST /api/v1/islamic-financings/{financingPublicId}/lease-condition-report`
- `POST /api/v1/islamic-financings/{financingPublicId}/rental-schedules`
- `POST /api/v1/islamic-financings/{financingPublicId}/activate-lease`
- `POST /api/v1/islamic-financings/{financingPublicId}/damage-events`
- `POST /api/v1/islamic-financings/{financingPublicId}/suspensions`
- `POST /api/v1/islamic-financings/{financingPublicId}/early-terminations`

Responses include evidence linkage, lifecycle status, and accounting references.

## Phase 8: Audit Trail

Record auditable events:

- `islamic.ijara.contract_created`
- `islamic.ijara.activation_blocked_no_owned_or_controlled_asset`
- `islamic.ijara.contract_activated`
- `islamic.ijara.rental_schedule_created`
- `islamic.ijara.damage_event_reported`
- `islamic.ijara.rental_suspended`
- `islamic.ijara.early_termination_processed`
- `islamic.ijara.transfer_routed`

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IjaraContractRentalScheduleTest.php`

Minimum tests:

1. `test_ijara_activation_requires_owned_or_controlled_asset`
2. `test_ijara_activation_requires_condition_report_evidence`
3. `test_ijara_rental_schedule_uses_rental_lines_not_sale_price_formula`
4. `test_ijara_rental_receivable_and_income_post_with_approved_mappings`
5. `test_ijara_interest_mapped_operation_is_rejected`
6. `test_ijara_damage_event_creates_approved_workflow`
7. `test_ijara_suspension_pauses_rental_progression`
8. `test_ijara_early_termination_posts_approved_adjustment`

Proof-by-contradiction acceptance alignment tests:

- `test_no_owned_asset_blocks_activation`
- `test_rental_schedule_excludes_sale_price_interest_logic`
- `test_damage_event_creates_approved_workflow`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: implementing Ijara on top of Murabaha installment table can silently preserve sale-price assumptions.
2. Risk: ownership status may be updated in bulk without proving control evidence at activation boundary.
3. Risk: damage event endpoint could become a passive log with no financial governance.

Fixes:

1. isolate Ijara rental schedule validation and accounting path from Murabaha formula checks.
2. require explicit ownership/control evidence verification under row lock before activation.
3. bind damage events to decisioned workflow states and posting routes.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: "controlled" assets can be interpreted loosely, enabling weak activation evidence.
2. Risk: concurrent schedule updates during activation can produce mismatched posting totals.
3. Risk: mapping prechecks may pass but expire before posting commit.

Fixes:

1. define controlled-asset evidence schema (custody/contract reference + validity window).
2. freeze rental schedule (version lock) at activation and reference locked version in postings.
3. re-validate active/effective mappings inside the posting transaction.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. Risk: early termination can bypass suspension/damage unresolved states.
2. Risk: transfer handling may be accidentally implemented as direct status update before IF-072 controls.
3. Risk: tests may cover endpoint success but miss journal and audit linkage integrity.

Fixes:

1. enforce state-machine prerequisites and unresolved-event checks before termination.
2. route all transfer intents through explicit transfer workflow stub that forbids direct ownership mutation.
3. assert event -> approval decision -> journal posting chain in feature tests.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Does lease activation fail without owned/controlled asset evidence? Yes.
- Is rental schedule logic independent from Murabaha sale-price/interest formula assumptions? Yes.
- Are rental receivable/income postings tied to approved Ijara mappings and guarded from interest logic? Yes.
- Are damage, suspension, termination, and transfer handled through approved workflows with audit evidence? Yes.

## Test Execution Instructions

Use these commands during IF-071 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Ijara contract/rental changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IjaraContractRentalScheduleTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume lease activation succeeds without institution-owned/controlled asset evidence.
   - Contradiction: activation gate blocks unless an owned/controlled (or controlled lifecycle) asset exists.
2. Assume Ijara rental schedule remains coupled to Murabaha sale-price formulas.
   - Contradiction: dedicated rental-schedule endpoint persists rental lines independently of sale-price formula checks.
3. Assume damage/suspension/termination can occur without governed workflow records and audit trace.
   - Contradiction: Ijara lifecycle events are persisted with workflow state and audited security events.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if070_|test_if071_|test_if072_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `composer test` passed: `OK (623 tests, 9816 assertions)`.
