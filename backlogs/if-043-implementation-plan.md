# IF-043 Implementation Plan: Partnership Registry

Date: 2026-05-24
Status: implemented and verified
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-043
Proof-method: proof by contradiction

## IF-043 Source Requirement

Goal: track Moudaraba and Moucharaka investments.

Proof-by-contradiction invariant: assume partnership profit is distributed without contribution evidence. Distribution must fail.

Acceptance criteria:

- Store partners, capital contributions, contribution evidence, profit ratios, loss rules, governance rights, reporting cadence, and exit terms.
- Track reports, profit declarations, losses, misconduct findings, valuations, buyouts, and liquidation.
- Separate Moudaraba and Moucharaka rule sets.

Tests:

- Missing contribution evidence blocks activation.
- Profit declaration requires report evidence.
- Buyout requires approved valuation.

## Architecture Context

Current state:

- Product-family taxonomy already includes `moudaraba` and `moucharaka`.
- No first-class partnership registry/lifecycle exists.
- Compliance/screening infrastructure can be reused for future partnership checkpoints.

Primary contradiction gap:

- Profit, loss, and exit actions can occur without a canonical partnership ledger proving contributions, report evidence, and valuation governance.

## Completion Definition For This Plan

IF-043 is sound only if all are true:

- Partnership registry stores complete governance and financial participation structure.
- Activation/use is blocked when contribution evidence is incomplete.
- Profit declarations cannot proceed without report evidence.
- Buyout/liquidation actions require approved valuation evidence.
- Moudaraba and Moucharaka constraints are enforced distinctly.

## Phase 1: Partnership Data Model

Create migration:

- `create_islamic_partnership_registry_tables`

Tables:

- `islamic_partnerships`
- `islamic_partnership_partners`
- `islamic_partnership_contributions`
- `islamic_partnership_reports`
- `islamic_partnership_profit_declarations`
- `islamic_partnership_valuations`
- `islamic_partnership_buyouts`
- `islamic_partnership_losses`
- optional `islamic_partnership_events`

`islamic_partnerships` core fields:

- public id
- partnership type (`moudaraba`|`moucharaka`)
- governance rights model
- reporting cadence
- loss rules
- exit terms
- status
- metadata

## Phase 2: Moudaraba vs Moucharaka Rule Separation

Implement policy resolver:

- `PartnershipRuleProfile::forType(type)`

Examples:

- Moudaraba: capital provider/entrepreneur role constraints, loss-allocation rules per policy.
- Moucharaka: joint contribution and governance participation constraints.

Validation points:

- partner role combinations
- contribution expectations
- profit/loss allocation rule compatibility

Proof by contradiction:

- Assume a Moucharaka partnership is configured with Moudaraba-only role semantics. Impossible because type-specific profile validation rejects it.

## Phase 3: Contribution Evidence Gate

Activation/use gate:

- all required initial contributions recorded
- each required contribution has valid evidence document(s)
- contribution totals reconcile to partnership terms

If missing evidence or mismatch:

- activation blocked with explicit reasons.

Proof by contradiction:

- Assume partnership activates with undocumented capital contribution. Impossible because evidence gate blocks activation.

## Phase 4: Reporting And Profit Declaration Workflow

Track periodic reports:

- report period
- financial summary
- evidence attachments
- reviewer/approval status

Profit declaration rule:

- declaration requires approved report evidence for same period.
- declaration cannot exceed distributable amount per declared results and policy.

## Phase 5: Loss And Misconduct Tracking

Add lifecycle for:

- loss events
- misconduct findings
- remediation/corrective actions

Enforcement:

- unresolved critical misconduct/loss blockers can suspend distributions until resolved per policy.

## Phase 6: Valuation, Buyout, Liquidation Controls

Valuation model:

- valuation record with method, inputs, valuer, valuation date, approval status, evidence.

Buyout gate:

- buyout requires latest approved valuation within policy validity window.
- buyout amounts constrained by approved valuation and partner holdings.

Liquidation flow:

- requires final valuation + settlement plan + evidence of approvals.

Proof by contradiction:

- Assume buyout executes without approved valuation. Impossible because buyout gate rejects operation.

## Phase 7: API Surface

Endpoints:

- `POST /api/v1/islamic-partnerships`
- `GET /api/v1/islamic-partnerships/{partnershipPublicId}`
- `POST /api/v1/islamic-partnerships/{partnershipPublicId}/partners`
- `POST /api/v1/islamic-partnerships/{partnershipPublicId}/contributions`
- `POST /api/v1/islamic-partnerships/{partnershipPublicId}/reports`
- `POST /api/v1/islamic-partnerships/{partnershipPublicId}/profit-declarations`
- `POST /api/v1/islamic-partnerships/{partnershipPublicId}/valuations`
- `POST /api/v1/islamic-partnerships/{partnershipPublicId}/buyouts`
- `POST /api/v1/islamic-partnerships/{partnershipPublicId}/liquidate`
- `GET /api/v1/islamic-partnerships/{partnershipPublicId}/timeline`

## Phase 8: Screening/Compliance Integration

At activation, profit declaration, and buyout boundaries:

- run relevant screening/compliance checks where policy requires.
- `fail` blocks operation.
- `manual_review` routes to compliance case/blocker.

## Phase 9: Audit Trail

Record events:

- `islamic.partnership.created`
- `islamic.partnership.activation_blocked`
- `islamic.partnership.contribution.recorded`
- `islamic.partnership.profit_declared`
- `islamic.partnership.loss_recorded`
- `islamic.partnership.valuation.approved`
- `islamic.partnership.buyout.blocked`
- `islamic.partnership.buyout.executed`
- `islamic.partnership.liquidated`

## Phase 10: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicPartnershipRegistryTest.php`

Minimum tests:

1. `test_missing_contribution_evidence_blocks_partnership_activation`
2. `test_moudaraba_rules_reject_invalid_partner_role_configuration`
3. `test_moucharaka_rules_require_joint_contribution_structure`
4. `test_profit_declaration_requires_approved_report_evidence`
5. `test_profit_declaration_rejects_amount_above_distributable_profit`
6. `test_buyout_requires_approved_recent_valuation`
7. `test_buyout_rejected_when_valuation_expired_or_unapproved`
8. `test_loss_event_can_block_distribution_when_policy_requires`
9. `test_liquidation_requires_final_valuation_and_settlement_plan`
10. `test_partnership_timeline_is_append_only`

Proof-by-contradiction acceptance alignment tests:

- `test_missing_contribution_evidence_blocks_activation`
- `test_profit_declaration_requires_report_evidence`
- `test_buyout_requires_approved_valuation`

## Phase 11: Adversarial Review (Round 1)

Findings:

1. Risk: contribution rows exist but evidence links optional, allowing empty proofs.
2. Risk: report uploads not tied to profit declaration period.
3. Risk: valuation record exists but buyout path ignores approval status.

Fixes:

1. require evidence document references for required contributions.
2. enforce declaration-period to approved-report linkage.
3. buyout gate validates approval + recency + coverage.

## Phase 12: Adversarial Review (Round 2)

Findings:

1. Ambiguity: Moudaraba/Moucharaka loss allocation can be accidentally mixed.
2. Risk: parallel updates on partner holdings can miscompute buyout amounts.
3. Risk: liquidation path can bypass unresolved blockers.

Fixes:

1. strict type-specific allocation validators.
2. row-level locking + idempotency keys for buyout operations.
3. liquidation gate checks active blockers and unresolved compliance issues.

## Phase 13: Adversarial Review (Round 3)

Findings:

1. IF-043 requires tracking misconduct findings; omission weakens governance integrity.
2. CI may pass contribution tests without verifying downstream distribution controls.
3. Historical rewrite risk if timeline entries are mutable.

Fixes:

1. include misconduct findings entity and blocker integration.
2. add end-to-end tests from contribution to report to declaration to buyout.
3. append-only event timeline with immutable event payload snapshots.

## Phase 14: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are partnership entities complete for contributions, reports, declarations, valuations, exits, and liquidation? Yes.
- Are activation and distribution blocked when contribution/report evidence is missing? Yes.
- Are buyouts gated by approved valuation evidence? Yes.
- Are Moudaraba and Moucharaka rule sets enforced separately? Yes.
- Are governance and financial lifecycle events auditable and immutable? Yes.

## Test Execution Instructions

Use these commands during IF-043 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for partnership registry and distribution/buyout gates
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicPartnershipRegistryTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implemented And Verified (2026-05-25)

Proof by contradiction checks closed:

1. Assume activation can proceed without partner-level contribution evidence while total capital appears sufficient.
   - Contradiction: activation now enforces evidence-backed contributions for every partner with positive expected contribution.
2. Assume Moudaraba/Moucharaka role semantics can be mixed without activation failure.
   - Contradiction: activation now enforces type-specific role structure (`moudaraba`: provider+entrepreneur; `moucharaka`: at least two joint partners with positive expected contributions).
3. Assume buyout or liquidation can proceed with unapproved valuation evidence.
   - Contradiction: both paths now require approved valuation, with recency checks on latest approved valuation.
4. Assume liquidation lifecycle and timeline evidence are missing.
   - Contradiction: liquidation endpoint and timeline endpoint are implemented and verified by feature tests.

Verification commands executed:

```bash
php artisan test --parallel --recreate-databases --filter 'test_if043_'
php artisan test --parallel --recreate-databases --filter 'test_if040_|test_if041_|test_if042_|test_if043_'
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
composer test
```

Observed result:

- `composer test` passed: `OK (610 tests, 9506 assertions)`.
