# IF-081 Implementation Plan: Salam Contract, Payment, And Delivery

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-081
Proof-method: proof by contradiction

## IF-081 Source Requirement

Goal: manage approval, upfront payment, goods delivery, and settlement.

Proof-by-contradiction invariant: assume upfront payment posts before contract approval. Posting must fail.

Acceptance criteria:

- Capture goods specification, quantity, quality, delivery date, delivery place, counterparty, price, and evidence.
- Post upfront payment only after approval.
- Manage full delivery, partial delivery, substitution, rejection, non-delivery, and dispute.
- Link delivery to inventory or settlement accounting.

Tests:

- Approval rejects vague goods.
- Payment before approval rejected.
- Partial delivery opens settlement state.

## Architecture Context

Current state:

- `IslamicFinancingWorkflow` remains Murabaha-centric (`contract_type` allowlist, pricing assumptions, approval postings).
- No first-class Salam contract entity/workflow currently captures goods specification quality and delivery obligations.
- No dedicated upfront-payment gate for Salam that enforces approval-before-posting.
- No delivery lifecycle endpoints/state machine for full/partial/substitution/rejection/non-delivery/dispute handling.

Current contradiction gaps:

- Salam contract approval cannot currently enforce goods-specific precision requirements.
- Upfront payment can only be reasoned via generic posting logic; Salam-specific pre-approval hard gate is absent.
- Delivery outcomes are not linked to inventory/settlement accounting flows under explicit Salam governance.

## Completion Definition For This Plan

IF-081 is sound only if all are true:

- Salam contracts capture complete goods/delivery obligations and evidence.
- Upfront payment is impossible before contract approval.
- Delivery lifecycle events are explicit and auditable.
- Partial delivery creates settlement state and accounting linkage.

## Phase 1: Salam Contract Workflow Surface

Add dedicated Salam contract path (`contract_type = salam`) with structured payload:

- goods specification (taxonomy + textual clauses)
- quantity and quality criteria
- delivery date/place terms
- counterparty identity
- contract price and currency
- evidence document references

Proof by contradiction:

- Assume Salam contract approved with vague goods fields. Contradiction: IF-081 requires explicit goods specification/quantity/quality.

## Phase 2: Approval Gate For Goods Precision

Implement goods-precision validator at approval time:

- reject missing/ambiguous specification
- require measurable quantity + quality constraints
- validate delivery/place/counterparty completeness

Failure produces gate-keyed readiness errors.

## Phase 3: Upfront Payment Control

Create Salam upfront-payment service:

- posts only after approval state check under row lock
- rejects any payment request while status != approved
- enforces approved Salam mapping profiles and non-interest routes

Proof by contradiction:

- Assume upfront payment posts before approval. Impossible once status gate is enforced in-transaction.

## Phase 4: Delivery Lifecycle State Machine

Define delivery lifecycle states/events:

- `awaiting_delivery`
- `partially_delivered`
- `fully_delivered`
- `substitution_pending`
- `rejected`
- `non_delivered`
- `in_dispute`
- `settled`

Each event persists evidence and decision metadata.

## Phase 5: Partial Delivery And Settlement State

When partial delivery is recorded:

- open settlement state for outstanding quantity/value
- compute delivered vs outstanding obligations
- block final closure until settlement resolution

## Phase 6: Substitution, Rejection, Non-Delivery, Dispute

Governed workflows:

- substitution request/approval with spec-equivalence checks
- rejection path with reason and remedy route
- non-delivery path with escalation/remedy accounting
- dispute case integration with blocker controls

## Phase 7: Inventory/Settlement Accounting Linkage

On delivery events:

- post inventory-recognition entries where applicable
- post settlement adjustments for partial/non-delivery outcomes
- enforce approved mapping and effective-window checks

## Phase 8: Audit Trail

Record events:

- `islamic.salam.contract_created`
- `islamic.salam.contract_approved`
- `islamic.salam.upfront_payment_rejected_pre_approval`
- `islamic.salam.upfront_payment_posted`
- `islamic.salam.delivery_recorded`
- `islamic.salam.partial_delivery_settlement_opened`
- `islamic.salam.substitution_requested`
- `islamic.salam.non_delivery_reported`
- `islamic.salam.dispute_opened`

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/SalamContractPaymentDeliveryTest.php`

Minimum tests:

1. `test_salam_approval_rejects_vague_goods_specification`
2. `test_salam_upfront_payment_before_approval_is_rejected`
3. `test_salam_upfront_payment_after_approval_posts_successfully`
4. `test_salam_full_delivery_closes_delivery_obligation`
5. `test_salam_partial_delivery_opens_settlement_state`
6. `test_salam_substitution_requires_approved_workflow`
7. `test_salam_non_delivery_routes_to_remedy_or_dispute_state`
8. `test_salam_delivery_posts_inventory_or_settlement_accounting`

Proof-by-contradiction acceptance alignment tests:

- `test_approval_rejects_vague_goods`
- `test_payment_before_approval_rejected`
- `test_partial_delivery_opens_settlement_state`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: adding Salam contract endpoint without strict goods schema allows ambiguous contracts.
2. Risk: pre-approval payment may still slip through via generic posting endpoint.
3. Risk: partial delivery might be recorded as informational only with no settlement opening.

Fixes:

1. enforce strict payload and approval-time validators.
2. centralize payment posting through Salam gate service.
3. make partial-delivery event create mandatory settlement state row.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: substitution flow can bypass spec-equivalence checks.
2. Risk: concurrent delivery updates can overstate delivered quantity.
3. Risk: mapping validity can change between validation and posting.

Fixes:

1. require substitution approval with explicit equivalence evidence.
2. lock contract/delivery rows and enforce quantity invariants.
3. re-check mapping status/effective window in posting transaction.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. Risk: dispute state may not block further settlement postings.
2. Risk: audit chain may miss linkage between delivery event and journal entry.
3. Risk: tests may only verify HTTP status without state-machine transitions.

Fixes:

1. hard-block conflicting postings while dispute is active unless approved override.
2. persist event->journal references and assert them in tests.
3. include transition assertions for each delivery lifecycle state.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Does approval reject vague or incomplete goods specifications? Yes.
- Is upfront payment blocked before approval under transaction-safe checks? Yes.
- Do partial deliveries open settlement state explicitly? Yes.
- Are delivery outcomes linked to inventory/settlement accounting via approved mappings? Yes.
- Are substitution/rejection/non-delivery/dispute flows governed and auditable? Yes.

## Test Execution Instructions

Use these commands during IF-081 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Salam contract/payment/delivery changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/SalamContractPaymentDeliveryTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume Salam upfront payment can post before approval.
   - Contradiction: `/salam-upfront-payments` now rejects non-`approved` financing and records `islamic.salam.upfront_payment_rejected_pre_approval`.
2. Assume approved Salam payment can bypass mapping governance.
   - Contradiction: posting resolves debit/credit mappings via approved/effective workflow-validated mapping paths before journal posting.
3. Assume partial delivery does not open settlement state.
   - Contradiction: partial delivery now creates/updates `islamic_salam_settlement_states` with `status=open` and outstanding balance.
4. Assume vague goods specification can still pass approval.
   - Contradiction: financing approval now blocks with IF-081 goods precision gate on vague quality specifications.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if081_'
php artisan test --parallel --recreate-databases --filter 'test_if040_|test_if041_|test_if042_|test_if080_|test_if081_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `test_if081_` subset passed within targeted bundle.
- `test_if040_|test_if041_|test_if042_|test_if080_|test_if081_` passed: `OK (41 tests, 852 assertions)`.
- `IslamicFinanceTest` passed: `OK (146 tests, 3557 assertions)`.
- `composer test` passed: `OK (633 tests, 10058 assertions)`.
