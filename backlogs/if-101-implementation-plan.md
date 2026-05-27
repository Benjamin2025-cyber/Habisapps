# IF-101 Implementation Plan: Moudaraba Contract And Capital Deployment

Date: 2026-05-24
Status: implementation plan
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-101
Proof-method: proof by contradiction

## IF-101 Source Requirement

Goal: activate and manage Moudaraba investments.

Proof-by-contradiction invariant: assume capital is disbursed without approved contract and screening. Disbursement must fail.

Acceptance criteria:

- Capture entrepreneur, business plan, capital amount, eligible use, profit ratio, reporting obligations, evidence, and screening.
- Disburse capital through approved mapping.
- Require periodic reports.
- Block profit distribution until report and profit declaration are approved.

Tests:

- Disbursement before approval rejected.
- Missing report blocks profit distribution.
- Profit ratio snapshot used for distribution.

## Architecture Context

Current state:

- Financing workflow remains Murabaha-focused for origination/approval/posting and does not provide Moudaraba investment contract lifecycle.
- Screening infrastructure exists and can enforce contract-approval pass for actions, but there is no Moudaraba-specific disbursement service binding approval + screening + mapping + investment-state semantics.
- No first-class reporting/profit-declaration workflow currently gates profit distribution.
- Existing Islamic tests are concentrated on Murabaha approval and screening behavior.

Current contradiction gaps:

- Capital disbursement path is not explicitly blocked by Moudaraba contract + screening preconditions.
- Profit distribution lacks required dependency on approved report and approved profit declaration.
- Profit-ratio snapshoting at distribution time is not guaranteed.

## Completion Definition For This Plan

IF-101 is sound only if all are true:

- Moudaraba contracts capture full entrepreneur/business/capital/use/profit/reporting evidence.
- Disbursement fails unless contract is approved and screening result is pass.
- Profit distribution is blocked until approved report + approved profit declaration exist.
- Distribution uses immutable contract ratio snapshot.

## Phase 1: Moudaraba Contract Domain Workflow

Introduce Moudaraba contract entities and states:

- contract data: entrepreneur, business plan, capital amount, eligible use, profit ratio, reporting obligations, evidence references
- states: `draft`, `approved`, `active`, `report_pending`, `distribution_ready`, `closed`

## Phase 2: Approval + Screening Gate For Disbursement

Disbursement service must enforce, in-transaction:

- contract state is approved/active for disbursement
- latest screening result for contract/disbursement context is `pass`
- no active compliance blocker prevents action

Proof by contradiction:

- Assume disbursement posts without approval/screening pass. Impossible once disbursement service hard-gates both checks under lock.

## Phase 3: Capital Disbursement Posting

Implement Moudaraba capital disbursement posting:

- use approved Moudaraba mapping codes/profile
- reject interest-class mappings via Islamic guard
- persist journal reference and idempotency key

## Phase 4: Periodic Reporting Workflow

Add report cadence and submission flow:

- required cadence derived from product/contract policy
- report submission captures period, results, evidence
- report approval decision required before distribution eligibility

Missing/overdue report should keep distribution blocked.

## Phase 5: Profit Declaration And Distribution Gate

Distribution prerequisites:

- approved report for target period
- approved profit declaration for target period
- no unresolved dispute/compliance blocker for period

Distribution uses contract ratio snapshot captured at contract approval (or explicit versioned amendment rules).

## Phase 6: Ratio Snapshot Integrity

Persist immutable ratio snapshot for each distribution event:

- store contract ratio version hash/reference
- prevent post-hoc ratio mutation from rewriting already distributed periods

Proof by contradiction:

- Assume distribution can change to a newer ratio after posting. Contradiction with "profit ratio snapshot used for distribution."

## Phase 7: API Surface

Endpoints (illustrative):

- `POST /api/v1/islamic-moudaraba-contracts`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/approve`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/disburse`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/reports`
- `POST /api/v1/islamic-moudaraba-reports/{reportPublicId}/approve`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/profit-declarations`
- `POST /api/v1/islamic-moudaraba-profit-declarations/{declarationPublicId}/approve`
- `POST /api/v1/islamic-moudaraba-contracts/{contractPublicId}/distribute-profit`

## Phase 8: Audit Trail

Record events:

- `islamic.moudaraba.contract_created`
- `islamic.moudaraba.contract_approved`
- `islamic.moudaraba.disbursement_blocked_missing_approval`
- `islamic.moudaraba.disbursement_blocked_screening`
- `islamic.moudaraba.capital_disbursed`
- `islamic.moudaraba.report_submitted`
- `islamic.moudaraba.report_approved`
- `islamic.moudaraba.distribution_blocked_missing_report`
- `islamic.moudaraba.profit_distributed`

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/MoudarabaContractCapitalDeploymentTest.php`

Minimum tests:

1. `test_moudaraba_contract_captures_required_business_and_ratio_fields`
2. `test_moudaraba_disbursement_before_approval_is_rejected`
3. `test_moudaraba_disbursement_requires_pass_screening`
4. `test_moudaraba_disbursement_posts_with_approved_mappings`
5. `test_missing_report_blocks_profit_distribution`
6. `test_missing_profit_declaration_blocks_profit_distribution`
7. `test_profit_distribution_uses_contract_ratio_snapshot`
8. `test_ratio_change_after_distribution_does_not_rewrite_posted_distribution`

Proof-by-contradiction acceptance alignment tests:

- `test_disbursement_before_approval_rejected`
- `test_missing_report_blocks_profit_distribution`
- `test_profit_ratio_snapshot_used_for_distribution`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: generic disbursement endpoint may bypass Moudaraba approval/screening gates.
2. Risk: report submission without approval might accidentally unlock distribution.
3. Risk: ratio retrieved live from contract at distribution time can drift.

Fixes:

1. centralize all Moudaraba disbursement through dedicated gated service.
2. require explicit approved report + approved declaration state checks.
3. snapshot ratio at distribution event and store immutable reference.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: reporting cadence enforcement may ignore overdue periods.
2. Risk: concurrent distribution attempts can duplicate postings.
3. Risk: mapping validity may pass pre-check but expire before commit.

Fixes:

1. derive overdue status from cadence and block distribution when unmet.
2. idempotency keys + unique period distribution constraint.
3. re-validate mapping status/effective window inside posting transaction.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. Risk: amendments to contract could retroactively alter period eligibility decisions.
2. Risk: audit chain may not link report/declaration/ratio/journal deterministically.
3. Risk: tests may check HTTP status only, not source linkage integrity.

Fixes:

1. version contract terms and lock period decisions to specific version snapshot.
2. persist report->declaration->distribution->journal references.
3. assert linkage and immutable-snapshot fields in feature tests.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Is disbursement blocked unless contract is approved and screening pass exists? Yes.
- Are capital disbursements posted only through approved Moudaraba mappings? Yes.
- Does missing approved report block profit distribution? Yes.
- Does missing approved profit declaration block distribution? Yes.
- Is profit distribution tied to immutable ratio snapshot evidence? Yes.

## Test Execution Instructions

Use these commands during IF-101 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Moudaraba contract/deployment changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/MoudarabaContractCapitalDeploymentTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
