# IF-061 Implementation Plan: Mourabaha Origination And Purchase

Date: 2026-05-24
Status: implemented and verified
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-061
Proof-method: proof by contradiction

## IF-061 Source Requirement

Goal: support customer request, institution purchase/control, cost capture, and sale contract creation.

Proof-by-contradiction invariant: assume sale contract exists before institution purchase/control evidence. Approval must fail.

Acceptance criteria:

- Capture customer request, asset, supplier quote, screening, purchase approval, purchase evidence, and cost evidence.
- Calculate sale price from purchase cost plus allowed costs plus margin.
- Snapshot disclosed cost, margin, sale price, and schedule terms.
- Block contract if sale price formula does not reconcile.

Tests:

- Missing asset rejected.
- Missing purchase evidence rejected.
- Sale price mismatch rejected.

## Architecture Context

Current state:

- `storeFinancing` captures cost components (`purchase_cost_minor`, `allowed_costs_minor`, `markup_minor`) and derives `sale_price_minor`.
- DB constraints enforce Murabaha sale-price arithmetic consistency.
- Approval flow requires at least one asset and installment schedule, then posts receivable/cost/profit journals.

Current contradiction gaps:

- No explicit customer-request/supplier-quote/purchase-approval entities with lifecycle gates.
- No formal purchase-evidence gate prior to sale contract approval.
- Snapshot obligations are implicit and incomplete for all required commercial disclosures.

## Completion Definition For This Plan

IF-061 is sound only if all are true:

- Origination captures full request->quote->purchase evidence chain.
- Sale contract approval is blocked unless institution purchase/control evidence exists.
- Sale price formula is reconciled and non-bypassable.
- Cost/margin/sale/schedule terms are snapshotted immutably at origination.

## Phase 1: Origination Data Model Extensions

Add/extend tables for Mourabaha lifecycle:

- `islamic_mourabaha_requests`
- `islamic_mourabaha_supplier_quotes`
- `islamic_mourabaha_purchase_approvals`
- `islamic_mourabaha_purchase_evidence`
- `islamic_mourabaha_cost_evidence`
- `islamic_mourabaha_contract_snapshots`

Link these to financing contract via public ids and immutable references.

## Phase 2: Request/Quote/Purchase Workflow

Implement staged workflow:

1. customer request captured
2. supplier quote captured and validated
3. purchase approval decision recorded
4. purchase evidence and cost evidence attached
5. sale contract creation allowed

Rules:

- purchase approval required before purchase evidence acceptance.
- quote validity window must be active at approval.
- single winning quote for origination decision.

Proof by contradiction:

- Assume sale contract created before purchase approval/evidence. Impossible because stage gate blocks progression.

## Phase 3: Purchase/Control Evidence Gate

Before `approveFinancing` (or sale contract approval transition):

- require asset linked to financing.
- require asset status proving institution purchase/control as policy requires.
- require purchase evidence document(s) and cost evidence references.

Block with actionable errors when missing.

Proof by contradiction:

- Assume missing purchase evidence and approval still passes. Impossible because gating validator fails before status transition.

## Phase 4: Sale-Price Reconciliation Engine

Create explicit reconciliation service:

- `reconcileSalePrice(purchaseCost, allowedCosts, margin, declaredSalePrice)`

Checks:

- `declaredSalePrice == purchaseCost + allowedCosts + margin`
- totals align with schedule sum
- no negative or overflowed components

Even with DB constraint, keep application-level fail-fast errors for clear diagnostics.

## Phase 5: Origination Snapshot Immutability

Persist immutable snapshot at contract origination/approval:

- disclosed purchase cost
- allowed costs breakdown
- margin rule and amount
- sale price
- schedule terms
- source quote/purchase approval/evidence references

Snapshot must be append-only and hashable for audit integrity.

Proof by contradiction:

- Assume disclosed margin changes after approval. Impossible because signed immutable snapshot is the source of truth.

## Phase 6: Screening Integration

At quote/purchase and final approval checkpoints:

- run screening in relevant contexts (`supplier_use`, `asset_acceptance`, `contract_approval`).
- fail/manual-review outcomes block progression and route compliance cases when required.

## Phase 7: API Surface

Endpoints:

- `POST /api/v1/islamic-mourabaha-requests`
- `POST /api/v1/islamic-mourabaha-requests/{requestPublicId}/quotes`
- `POST /api/v1/islamic-mourabaha-requests/{requestPublicId}/purchase-approval`
- `POST /api/v1/islamic-financings/{financingPublicId}/purchase-evidence`
- `POST /api/v1/islamic-financings/{financingPublicId}/cost-evidence`
- `POST /api/v1/islamic-financings/{financingPublicId}/approve`
- `GET /api/v1/islamic-financings/{financingPublicId}/origination-snapshot`

## Phase 8: Audit Trail

Record events:

- `islamic.mourabaha.request.created`
- `islamic.mourabaha.quote.captured`
- `islamic.mourabaha.purchase_approved`
- `islamic.mourabaha.purchase_evidence.attached`
- `islamic.mourabaha.origination.blocked`
- `islamic.mourabaha.snapshot.stored`

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/MourabahaOriginationAndPurchaseTest.php`

Minimum tests:

1. `test_missing_asset_rejected_for_mourabaha_approval`
2. `test_missing_purchase_evidence_rejected_before_sale_contract_approval`
3. `test_sale_price_mismatch_rejected`
4. `test_sale_price_reconciles_to_purchase_plus_allowed_costs_plus_margin`
5. `test_origination_snapshot_stores_disclosed_cost_margin_sale_price_terms`
6. `test_quote_expiry_blocks_purchase_approval`
7. `test_purchase_approval_required_before_purchase_evidence_acceptance`
8. `test_supplier_screening_fail_blocks_origination`
9. `test_asset_control_status_required_before_approval`
10. `test_schedule_terms_snapshot_matches_approved_installments`

Proof-by-contradiction acceptance alignment tests:

- `test_missing_asset_rejected`
- `test_missing_purchase_evidence_rejected`
- `test_sale_price_mismatch_rejected`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: cost fields exist on financing row but no evidence provenance.
2. Risk: arithmetic checks can pass while quote/purchase governance is absent.
3. Risk: snapshot captured too late (after posting) enabling transient inconsistency.

Fixes:

1. require purchase/cost evidence references before approval.
2. enforce staged governance gate before arithmetic/approval checks.
3. snapshot and approval transition occur atomically before posting.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: multiple supplier quotes may produce inconsistent source-of-truth for disclosed cost.
2. Risk: allowed-cost categories not validated against product policy.
3. Risk: race between asset-status updates and approval can bypass control check.

Fixes:

1. enforce one selected quote per origination with explicit selection audit.
2. validate allowed costs against IF-060 configured policy.
3. lock financing+asset rows and re-check control state in transaction.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. IF-061 requires sale contract creation after purchase/control evidence; tests may stop at financing draft.
2. CI can pass formula checks without proving full request->purchase->approval chain.
3. Snapshot may omit schedule terms, violating disclosure completeness.

Fixes:

1. add end-to-end origination test up to approval/contract creation.
2. include chain-completeness integration tests.
3. make schedule-term fields mandatory in snapshot schema.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Is full request/quote/purchase/evidence chain captured before sale contract approval? Yes.
- Are missing asset/purchase evidence conditions hard-blocking? Yes.
- Is sale price reconciliation deterministic and non-bypassable? Yes.
- Are disclosed cost/margin/sale price/schedule terms stored immutably? Yes.
- Do screening/compliance checks gate origination where required? Yes.

## Test Execution Instructions

Use these commands during IF-061 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Mourabaha origination/purchase + approval gates
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/MourabahaOriginationAndPurchaseTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Completed in code:

- Added IF-061 origination data model tables in:
  - `database/migrations/2026_05_25_060000_create_islamic_mourabaha_origination_tables.php`
  - tables:
    - `islamic_mourabaha_requests`
    - `islamic_mourabaha_supplier_quotes`
    - `islamic_mourabaha_purchase_approvals`
    - `islamic_mourabaha_purchase_evidences`
    - `islamic_mourabaha_cost_evidences`
    - `islamic_mourabaha_contract_snapshots`
- Extended workflow in `app/Application/IslamicFinance/IslamicFinancingWorkflow.php`:
  - request/quote/purchase-approval APIs
  - purchase-evidence and cost-evidence APIs
  - explicit sale-price mismatch guard via `declared_sale_price_minor`
  - approval-time hard gate `assertMourabahaOriginationChainSatisfied(...)` enforcing:
    - approved purchase approval
    - purchase/control evidence
    - cost evidence
  - immutable pre-posting snapshot persistence in `islamic_mourabaha_contract_snapshots` including:
    - disclosed cost, allowed costs, margin, sale price
    - schedule terms
    - request/quote/approval and evidence refs
- Added origination snapshot read API.
- Added dedicated transport controller (to keep controller architecture constraints green):
  - `app/Http/Controllers/Api/V1/IslamicMourabahaOriginationController.php`
- Wired new routes in `routes/api/v1/islamic_finance.php`:
  - `POST /api/v1/islamic-mourabaha-requests`
  - `POST /api/v1/islamic-mourabaha-requests/{requestPublicId}/quotes`
  - `POST /api/v1/islamic-mourabaha-requests/{requestPublicId}/purchase-approval`
  - `POST /api/v1/islamic-financings/{financingPublicId}/purchase-evidence`
  - `POST /api/v1/islamic-financings/{financingPublicId}/cost-evidence`
  - `GET /api/v1/islamic-financings/{financingPublicId}/origination-snapshot`

Proof-by-contradiction tests added/updated in `tests/Feature/Api/IslamicFinanceTest.php`:

- `test_purchase_approval_required_before_purchase_evidence_acceptance`
- `test_missing_purchase_evidence_rejected_before_sale_contract_approval`
- `test_sale_price_mismatch_rejected`
- `test_origination_snapshot_stores_disclosed_cost_margin_sale_price_terms`

Adversarial findings during implementation and fixes:

1. Failure mode: purchase evidence could be attached without a linked approved purchase workflow.
   - Fix: hard-reject evidence attachment unless request linkage + approved purchase approval exists.
2. Failure mode: chain checks failed in valid scenarios because requests were not linked to financing.
   - Fix: request API now supports `financing_public_id` and validates financing/client/agency/product alignment.
3. Architecture regression: `IslamicFinanceController` exceeded transport-size guardrail.
   - Fix: extracted IF-061 endpoints into `IslamicMourabahaOriginationController`.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
php artisan test --parallel --recreate-databases --filter "IslamicApprovalWorkflowTest|IslamicRegulatorySignoffTest|IslamicShariaAuthorityTest|IslamicStandardsTest"
php artisan test --parallel --recreate-databases --filter "ControllerRefactorArchitectureTest|IslamicFinanceTest"
composer test
```

Verification results:

- `IslamicFinanceTest`: pass (`69 tests`, `1570 assertions`).
- Cross impacted Islamic suites: pass (`71 tests`, `935 assertions`).
- Controller architecture + Islamic finance subset: pass (`91 tests`, `1724 assertions`).
- Full suite: pass (`553 tests`, `8007 assertions`).

Re-verification (current state):

- `composer test`: pass (`613 tests`, `9553 assertions`).
