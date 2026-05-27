# IF-111 Implementation Plan: Moucharaka Partnership Workflow

Date: 2026-05-24
Status: implementation plan
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-111
Proof-method: proof by contradiction

## IF-111 Source Requirement

Goal: originate, operate, distribute, impair, and exit partnerships.

Proof-by-contradiction invariant: assume partnership activates without both parties' contribution evidence. Activation must fail.

Acceptance criteria:

- Capture partners, contributions, contribution evidence, capital ratios, profit ratios, loss rules, governance, reporting, and exit terms.
- Distribute profit from approved reporting.
- Allocate loss by approved capital rule.
- Process additional capital, impairment, buyout, and exit through governed workflows.

Tests:

- Missing contribution evidence blocks activation.
- Profit distribution uses contract ratio.
- Loss distribution uses capital ratio unless approved exception exists.
- Buyout requires valuation approval.

## Architecture Context

Current state:

- Moucharaka family is mapped in baseline/readiness, but no first-class partnership workflow implementation is present.
- No domain entities currently model partners, contribution evidence, ratio snapshots, additional capital events, impairment, buyout, and exit.
- No dedicated distribution engine currently enforces approved reporting preconditions and contract-ratio snapshot usage for Moucharaka.
- No explicit buyout valuation-approval gate exists in Islamic workflow seams.

Current contradiction gaps:

- Partnership activation cannot currently enforce both-party contribution evidence.
- Profit/loss distribution ratio enforcement and exception controls are absent.
- Additional capital, impairment, buyout, and exit are not represented as governed transitions.

## Completion Definition For This Plan

IF-111 is sound only if all are true:

- Activation requires complete partner contributions with verified evidence.
- Profit distribution is gated by approved reporting and uses contract ratio snapshot.
- Loss distribution follows capital ratio unless approved exception exists.
- Additional capital, impairment, buyout, and exit operate via auditable governed workflows.

## Phase 1: Partnership Domain Model

Introduce Moucharaka entities:

- `islamic_moucharaka_partnerships`
- `islamic_moucharaka_partners`
- `islamic_moucharaka_contributions`
- `islamic_moucharaka_reports`
- `islamic_moucharaka_distributions`
- `islamic_moucharaka_impairments`
- `islamic_moucharaka_buyouts`
- `islamic_moucharaka_exits`

Capture:

- partner identities
- contribution amounts/types
- evidence references per contribution
- capital/profit/loss ratio policy snapshot
- governance/reporting/exit terms

## Phase 2: Activation Gate With Contribution Evidence

Activation preconditions:

- both (or all required) partners have recorded contributions
- required contribution evidence is present and valid per policy
- ratio constraints validated against contract terms

Proof by contradiction:

- Assume activation succeeds without both-party evidence. Impossible once activation service hard-gates evidence completeness under lock.

## Phase 3: Approved Reporting Gate For Profit Distribution

Profit distribution requires:

- approved periodic report for target period
- approved distribution decision workflow state
- immutable contract ratio snapshot reference

Distribution postings must use approved Moucharaka mappings.

## Phase 4: Loss Allocation Engine

Loss posting rules:

- default: allocate loss by capital ratio
- exception: allow alternate loss rule only with explicit approved exception reference

Proof by contradiction:

- Assume loss distribution by non-capital rule without exception. Contradiction with IF-111 invariant.

## Phase 5: Additional Capital Workflow

Additional capital flow:

- capital injection request
- evidence and governance approvals
- ratio-impact decision and future-period effect
- accounting posting with source traceability

No retroactive rewrite of already posted distributions.

## Phase 6: Impairment Workflow

Impairment operations:

- impairment trigger registration
- valuation/evidence capture
- approval decision
- impairment posting through approved mappings

## Phase 7: Buyout Workflow With Valuation Approval

Buyout requires:

- approved valuation artifact/version
- approved buyout decision
- transfer/settlement posting
- ownership-share/state updates

Reject buyout without valuation approval.

## Phase 8: Exit Workflow

Exit process:

- exit trigger and settlement plan
- unresolved obligations checks
- final distribution/loss recognition
- closure with full audit and reconciliation

## Phase 9: API Surface

Endpoints (illustrative):

- `POST /api/v1/islamic-moucharaka-partnerships`
- `POST /api/v1/islamic-moucharaka-partnerships/{partnershipPublicId}/activate`
- `POST /api/v1/islamic-moucharaka-partnerships/{partnershipPublicId}/reports`
- `POST /api/v1/islamic-moucharaka-reports/{reportPublicId}/approve`
- `POST /api/v1/islamic-moucharaka-partnerships/{partnershipPublicId}/distribute-profit`
- `POST /api/v1/islamic-moucharaka-partnerships/{partnershipPublicId}/allocate-loss`
- `POST /api/v1/islamic-moucharaka-partnerships/{partnershipPublicId}/additional-capital`
- `POST /api/v1/islamic-moucharaka-partnerships/{partnershipPublicId}/impairments`
- `POST /api/v1/islamic-moucharaka-partnerships/{partnershipPublicId}/buyouts`
- `POST /api/v1/islamic-moucharaka-partnerships/{partnershipPublicId}/exit`

## Phase 10: Audit Trail

Record events:

- `islamic.moucharaka.partnership_created`
- `islamic.moucharaka.activation_blocked_missing_contribution_evidence`
- `islamic.moucharaka.partnership_activated`
- `islamic.moucharaka.profit_distribution_posted`
- `islamic.moucharaka.loss_distribution_posted`
- `islamic.moucharaka.loss_distribution_blocked_missing_exception`
- `islamic.moucharaka.additional_capital_posted`
- `islamic.moucharaka.impairment_posted`
- `islamic.moucharaka.buyout_blocked_missing_valuation_approval`
- `islamic.moucharaka.buyout_posted`
- `islamic.moucharaka.exit_closed`

## Phase 11: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/MoucharakaPartnershipWorkflowTest.php`

Minimum tests:

1. `test_moucharaka_activation_requires_both_parties_contribution_evidence`
2. `test_profit_distribution_requires_approved_report`
3. `test_profit_distribution_uses_contract_ratio_snapshot`
4. `test_loss_distribution_uses_capital_ratio_without_exception`
5. `test_loss_distribution_non_capital_rule_requires_approved_exception`
6. `test_additional_capital_flow_is_governed_and_posted`
7. `test_impairment_flow_requires_approval_and_posts`
8. `test_buyout_requires_valuation_approval`
9. `test_exit_flow_reconciles_and_closes`

Proof-by-contradiction acceptance alignment tests:

- `test_missing_contribution_evidence_blocks_activation`
- `test_profit_distribution_uses_contract_ratio`
- `test_loss_distribution_uses_capital_ratio_unless_approved_exception`
- `test_buyout_requires_valuation_approval`

## Phase 12: Adversarial Review (Round 1)

Findings:

1. Risk: contribution evidence might be uploaded but unbound to specific partner contribution lines.
2. Risk: distribution service could query live ratios and drift from original contract intent.
3. Risk: buyout endpoint may proceed with stale/unapproved valuation snapshots.

Fixes:

1. require partner-contribution-level evidence linkage.
2. persist and use immutable ratio snapshot per distribution period.
3. require valuation approval id/version re-validation in transaction.

## Phase 13: Adversarial Review (Round 2)

Findings:

1. Ambiguity: additional capital can silently alter future ratio math without governance signoff.
2. Risk: concurrent loss/profit operations can double-apply period allocations.
3. Risk: mapping checks may pass preflight but expire before commit.

Fixes:

1. force approval workflow for additional capital and ratio-impact decisions.
2. enforce unique period allocation constraints with row locking.
3. re-check mapping active/effective status inside posting transaction.

## Phase 14: Adversarial Review (Round 3)

Findings:

1. Risk: exit closure may ignore unresolved impairment/buyout obligations.
2. Risk: audit chain may miss linkage among report, decision, ratio snapshot, and journals.
3. Risk: tests may confirm status codes without validating ratio-calculation integrity.

Fixes:

1. block exit closure until unresolved obligations are cleared or approved exception applied.
2. persist explicit linkage references and assert them in tests.
3. add deterministic ratio/allocation assertions in feature coverage.

## Phase 15: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Does activation fail without both-party contribution evidence? Yes.
- Is profit distribution gated by approved reporting and contract ratio snapshot? Yes.
- Does loss distribution follow capital ratio unless approved exception exists? Yes.
- Are additional capital, impairment, buyout, and exit processed through governed audited workflows? Yes.
- Is buyout blocked without valuation approval? Yes.

## Test Execution Instructions

Use these commands during IF-111 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Moucharaka partnership workflow changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/MoucharakaPartnershipWorkflowTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
