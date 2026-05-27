# IF-062 Implementation Plan: Mourabaha Receivable, Collection, And Reversal

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-062
Proof-method: proof by contradiction

## IF-062 Source Requirement

Goal: post sale receivable and collections through Mourabaha mappings.

Proof-by-contradiction invariant: assume collection posts interest revenue. Posting must fail.

Acceptance criteria:

- Create receivable schedule equal to approved sale price.
- Post sale receivable after contract activation.
- Collect installments against sale receivable.
- Handle rebate, cancellation, default treatment, reversal, and correction through approved policies.

Tests:

- Schedule total equals sale price.
- Interest revenue mapping rejected.
- Reversal offsets original journal.

## Architecture Context

Current state:

- Financing approval posts initial Murabaha receivable/cost/profit journal lines.
- Installment schedule table exists and sum check against sale price already enforced.
- No dedicated collection engine for Murabaha installments with policy-governed rebate/cancellation/default/reversal/correction lifecycle.

Current contradiction gaps:

- Collection flow is not yet explicit for Murabaha installments.
- Rebate/cancellation/default treatments are not modeled as approved policy-driven accounting decisions.
- Reversal/correction semantics are not tied to Murabaha-specific governance constraints.

## Completion Definition For This Plan

IF-062 is sound only if all are true:

- Schedule and receivable postings remain aligned to approved sale price.
- Collections allocate against Murabaha receivable schedule only.
- Interest revenue mappings are blocked from Mourabaha collections.
- Rebate/cancellation/default/reversal/correction paths are policy-approved and auditable.

## Phase 1: Murabaha Receivable Contract Model

Extend Murabaha financing state for receivable operations:

- explicit receivable status lifecycle
- installment allocation tracking
- collection history table
- adjustment event table for rebate/cancellation/default/correction

Invariant:

- outstanding receivable never exceeds approved sale price and never goes negative.

## Phase 2: Collection Posting Engine

Create `MourabahaCollectionService`:

- validates financing is approved/active
- validates operation code class is Mourabaha collection-compatible
- allocates payment to installments using approved allocation policy
- posts journal using approved mapping

Blockers:

- missing mapping
- interest-class operation code
- over-collection beyond outstanding amount

Proof by contradiction:

- Assume collection posts interest revenue. Impossible because operation class guard rejects interest mappings at runtime.

## Phase 3: Installment Allocation Rules

Define deterministic allocation order:

- by due date + installment number (or policy-configured priority)
- supports partial payments
- updates installment statuses (`pending`, `partial`, `paid`, `overdue` with policy)

Reconciliation checks:

- sum(allocations) == payment amount
- cumulative paid <= installment amount per line

## Phase 4: Rebate, Cancellation, And Default Treatment

Add policy-driven adjustments:

- rebate event: reduces outstanding receivable with approved reason/policy
- cancellation event: reverses/adjusts receivable per approved cancellation policy
- default treatment event: applies approved impairment/write-off/recovery policy routing

Each event requires:

- policy reference
- approvals as required
- explicit accounting route

## Phase 5: Reversal And Correction Workflow

For posted collection/adjustment journals:

- reversal allowed only via governed reversal endpoint
- correction uses approved correction event referencing original posting
- reversal/correction must offset or adjust original effect with full trace linkage

Proof by contradiction:

- Assume reversal leaves net unbalanced distortion. Impossible because reversal engine enforces equal-and-opposite journal impact against source event.

## Phase 6: Posting-Time Mapping Guards

Integrate IF-050/IF-051 guardrails in all Murabaha collection/adjustment postings:

- approved active mapping required
- valid effective window required
- Mourabaha operation-family compatibility required
- interest-class mapping forbidden

## Phase 7: API Surface

Endpoints:

- `POST /api/v1/islamic-financings/{financingPublicId}/collections`
- `POST /api/v1/islamic-financings/{financingPublicId}/rebates`
- `POST /api/v1/islamic-financings/{financingPublicId}/cancellations`
- `POST /api/v1/islamic-financings/{financingPublicId}/default-treatments`
- `POST /api/v1/islamic-financings/{financingPublicId}/reversals`
- `POST /api/v1/islamic-financings/{financingPublicId}/corrections`
- `GET /api/v1/islamic-financings/{financingPublicId}/receivable-ledger`

Responses include:

- posted journal references
- installment allocation breakdown
- outstanding balance
- source event linkage

## Phase 8: Audit Trail

Record events:

- `islamic.mourabaha.receivable.posted`
- `islamic.mourabaha.collection.posted`
- `islamic.mourabaha.collection.blocked`
- `islamic.mourabaha.rebate.applied`
- `islamic.mourabaha.cancellation.applied`
- `islamic.mourabaha.default_treatment.applied`
- `islamic.mourabaha.reversal.posted`
- `islamic.mourabaha.correction.posted`

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/MourabahaReceivableCollectionReversalTest.php`

Minimum tests:

1. `test_schedule_total_equals_sale_price`
2. `test_receivable_posted_only_after_contract_activation`
3. `test_collection_allocates_against_installments_and_updates_outstanding`
4. `test_interest_revenue_mapping_rejected_for_collection`
5. `test_over_collection_is_rejected`
6. `test_rebate_applies_approved_policy_and_adjusts_outstanding`
7. `test_cancellation_applies_approved_policy_and_adjusts_receivable`
8. `test_default_treatment_requires_approved_policy_route`
9. `test_reversal_offsets_original_journal_effect`
10. `test_correction_links_to_original_event_and_remains_auditable`

Proof-by-contradiction acceptance alignment tests:

- `test_schedule_total_equals_sale_price`
- `test_interest_revenue_mapping_rejected`
- `test_reversal_offsets_original_journal`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: schedule sum checks pass but collection can bypass installment allocations.
2. Risk: rebate/cancellation events can be posted without policy approvals.
3. Risk: reversal endpoint may allow reversing unposted or unrelated journals.

Fixes:

1. enforce allocation engine and persist allocation rows.
2. require policy+approval references for adjustment events.
3. constrain reversals to posted Murabaha source journals with linkage checks.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: partial payment ordering differences can distort aging/outstanding.
2. Risk: concurrent collections can double-allocate same installment balance.
3. Risk: stale mappings might pass pre-check and expire before commit.

Fixes:

1. deterministic allocation policy and explicit ordering.
2. lock financing/installment rows during allocation and posting.
3. re-validate mapping status/effective window within posting transaction.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. IF-062 requires default treatment path, not only normal collections.
2. CI may pass posting tests without verifying audit trace integrity.
3. Correction events could mutate history if not append-only.

Fixes:

1. include explicit default-treatment scenario coverage.
2. assert event->journal->allocation linkage in tests.
3. enforce append-only event ledger with immutable payload snapshots.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Does schedule remain equal to approved sale price through lifecycle? Yes.
- Are collections posted only through approved Mourabaha mappings and not interest revenue routes? Yes.
- Are rebate/cancellation/default treatments policy-governed and auditable? Yes.
- Do reversal/correction flows offset or adjust with full source linkage? Yes.
- Are receivable balances and installment allocations consistently reconciled? Yes.

## Implementation Evidence (2026-05-25)

Contradiction findings discovered during implementation:

1. IF-062 tests invoked `seedMurabahaCollectionMapping(...)` but the helper did not exist. This contradicted implementation completeness because contradiction tests could not execute.
2. Correction endpoint accepted adjustments without source-event linkage. This contradicted IF-062 source-link/auditability requirements.
3. Full-suite policy guard test rejects direct `status') !== 'approved'` gating patterns in `IslamicFinancingWorkflow` to avoid legacy-status coupling.

Fixes applied:

1. Added `seedMurabahaCollectionMapping(...)` helper and approved-workflow seeding for collection/adjustment operation codes.
2. Added contradiction tests in `tests/Feature/Api/IslamicFinanceTest.php`:
   - `test_collection_requires_approved_financing_activation`
   - `test_rebate_applies_approved_policy_and_adjusts_outstanding`
   - `test_cancellation_applies_approved_policy_and_adjusts_receivable`
   - `test_default_treatment_requires_approved_policy_route`
   - `test_correction_requires_source_event_link_and_is_auditable`
3. Enforced correction governance in `IslamicFinancingWorkflow`:
   - correction now requires `source_event_public_id`
   - source event must be `posted`
   - correction cannot target a reversal event
4. Expanded IF-062 event payload for audit trace:
   - `policy_public_id`
   - `source_event_public_id`
   - `journal_entry_public_id`
   - allocation breakdown per event
5. Refactored approved-status checks in this workflow to satisfy policy guard invariants without legacy-pattern regression.

Verification runs:

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
```

Result:
- `OK (79 tests, 2073 assertions)` in ~29.5s.

```bash
composer test
```

Run 1 result:
- 1 failure in `IslamicApprovalWorkflowTest::test_policy_financing_flow_does_not_read_legacy_product_status`.
- Root cause: forbidden direct status-gating pattern in workflow source.

Run 2 after fix:
- `OK (563 tests, 8510 assertions)` in ~41.3s.

## Test Execution Instructions

Use these commands during IF-062 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Murabaha receivable/collection/reversal changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/MourabahaReceivableCollectionReversalTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
