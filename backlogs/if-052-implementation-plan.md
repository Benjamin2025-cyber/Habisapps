# IF-052 Implementation Plan: Zakat And Charity/Non-Compliant Income Accounts

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-052
Proof-method: proof by contradiction

## IF-052 Source Requirement

Goal: support stakeholder-requested Zakat accounting and governed treatment of non-compliant income where configured.

Proof-by-contradiction invariant: assume late-payment penalty income is recognized as ordinary profit. Posting must fail unless the active policy permits the exact treatment.

Acceptance criteria:

- Configure Zakat-related accounts and charity/non-compliant income accounts where institution policy requires them.
- Link late-payment, non-compliant income, and purification events to approved treatment.
- Reports show balances and source transactions.

Tests:

- Missing charity treatment blocks configured late-fee event.
- Zakat account mapping required when product policy enables Zakat posting.
- Report reconciles to posted journals.

## Architecture Context

Current state:

- Late-payment treatment guard exists (`IslamicInterestGuardPolicy`) and already rejects forbidden modes.
- No end-to-end governed Zakat/charity/non-compliant income accounting workflow exists yet.
- No dedicated report surface proving reconciliation between treatment events and posted journals.

Primary contradiction gap:

- Treatment constraints can be partially validated at input time without guaranteeing mapped ledger posting discipline and auditable reconciliation.

## Completion Definition For This Plan

IF-052 is sound only if all are true:

- Zakat and charity/non-compliant income accounts are explicitly configurable and approval-governed.
- Treatment events (late-payment, non-compliant income, purification) can post only through approved mappings.
- Missing required treatment configuration blocks event posting.
- Reports reconcile treatment source events to journal postings.

## Phase 1: Canonical Treatment Policy Model

Create policy model/table(s):

- `islamic_treatment_policies`
- optional `islamic_treatment_policy_scopes`

Policy fields:

- policy code/version
- scope (institution/agency/product family/product)
- zakat_enabled (bool)
- charity_treatment_enabled (bool)
- non_compliant_income_treatment_enabled (bool)
- purification_mode
- required operation-code classes
- required mapping references
- approval status (accounting + Sharia)
- effective dates

## Phase 2: Account And Mapping Configuration

Add required account-class configuration for IF-052 domains:

- zakat payable/expense accounts (as policy dictates)
- charity/non-compliant income holding accounts
- purification transfer accounts

Enforce with IF-051 mapping workflow:

- treatment postings require approved active mappings for corresponding operation classes.

Proof by contradiction:

- Assume policy enables Zakat posting but no Zakat mapping exists. Impossible because posting validator blocks operation.

## Phase 3: Treatment Event Routing Rules

Define routing service:

- `IslamicTreatmentRoutingService::resolve(eventType, context)`

Event types:

- `late_payment_fee`
- `non_compliant_income_detected`
- `purification_transfer`

Routing checks:

- active approved policy exists
- event permitted by policy
- required operation code + mapping available

Output:

- posting route (operation code + mapping)
- treatment bucket classification

## Phase 4: Posting Enforcement

Before posting treatment events:

- validate routing through policy service.
- reject if treatment unresolved/missing/forbidden.

Hard block examples:

- late fee event with missing charity treatment route.
- non-compliant income event routed to ordinary profit account.

Proof by contradiction:

- Assume late-payment penalty posts as ordinary profit despite policy. Impossible because routing validator rejects non-approved route.

## Phase 5: Purification And Transfer Workflow

Implement controlled purification flow:

- detect non-compliant amounts
- hold/segregate amounts in designated account
- transfer to charity/purification destination per policy
- preserve source-to-transfer linkage

Each step auditable with immutable references.

## Phase 6: Report Reconciliation Surface

Add reporting endpoint/service:

- treatment event summaries by type/policy/period
- ledger balances for zakat/charity/non-compliant accounts
- source event -> journal line linkage
- reconciliation status flags

Reconciliation rule:

- total treatment source amounts == net posted ledger movements for configured treatment classes (allowing defined timing deltas).

## Phase 7: API Surface

Endpoints:

- `POST /api/v1/islamic-treatment-policies`
- `GET /api/v1/islamic-treatment-policies`
- `POST /api/v1/islamic-treatment-policies/{policyPublicId}/approve`
- `POST /api/v1/islamic-treatment-events`
- `POST /api/v1/islamic-treatment-events/{eventPublicId}/post`
- `GET /api/v1/islamic-treatment-reports/reconciliation`

## Phase 8: Audit Trail

Record events:

- `islamic.treatment_policy.created`
- `islamic.treatment_policy.approved`
- `islamic.treatment_event.blocked`
- `islamic.treatment_event.posted`
- `islamic.treatment_event.purified`
- `islamic.treatment_report.generated`

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicZakatAndCharityAccountsTest.php`

Minimum tests:

1. `test_missing_charity_treatment_blocks_late_fee_event`
2. `test_zakat_mapping_required_when_zakat_policy_enabled`
3. `test_non_compliant_income_cannot_post_to_ordinary_profit_mapping`
4. `test_purification_event_uses_approved_purification_route`
5. `test_unapproved_treatment_policy_blocks_event_posting`
6. `test_expired_treatment_policy_blocks_new_postings`
7. `test_treatment_posting_requires_approved_active_mapping`
8. `test_reconciliation_report_matches_source_events_to_posted_journals`
9. `test_policy_scope_precedence_applies_correct_treatment_route`
10. `test_treatment_blocked_event_is_audited_with_reason`

Proof-by-contradiction acceptance alignment tests:

- `test_missing_charity_treatment_blocks_late_fee_event`
- `test_zakat_mapping_required_when_policy_enabled`
- `test_reconciliation_report_reconciles_to_posted_journals`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: input guard blocks forbidden treatment names, but posting route still defaults to ordinary accounts.
2. Risk: Zakat enabled without enforcing required account/mapping completeness.
3. Risk: purification transfers lose source-event lineage.

Fixes:

1. central routing service with fail-closed account/mapping validation.
2. Zakat policy activation requires complete approved mapping set.
3. mandatory source-to-journal linkage fields for purification events.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: multiple overlapping policies can route same event differently.
2. Risk: backdated events may use now-inactive policies inconsistently.
3. Risk: reconciliation can appear green despite orphan journals if query scope is loose.

Fixes:

1. deterministic policy precedence and conflict rejection.
2. event routing uses event business date against policy effective window.
3. reconciliation requires bidirectional linkage checks (event->journal and journal->event).

## Phase 12: Adversarial Review (Round 3)

Findings:

1. IF-052 includes non-compliant income treatment, not only late-fee flows.
2. CI can pass policy tests without verifying ledger-balance reconciliation.
3. Emergency/manual journal postings may bypass treatment routing controls.

Fixes:

1. include explicit non-compliant income and purification event classes in coverage tests.
2. add integration reconciliation tests over posted journals.
3. enforce treatment tagging/routing validation on manual postings touching treatment account classes.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are Zakat and charity/non-compliant income treatments policy-configurable and approval-governed? Yes.
- Do missing required treatment mappings block configured events? Yes.
- Are late-payment/non-compliant/purification events routed only through approved treatment paths? Yes.
- Do reports reconcile source treatment events to posted journals and balances? Yes.
- Are treatment decisions and blocked postings auditable end-to-end? Yes.

## Test Execution Instructions

Use these commands during IF-052 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Zakat/charity treatment and reconciliation changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicZakatAndCharityAccountsTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Completed in code:

- Added IF-052 treatment policy/event persistence:
  - `islamic_treatment_policies`
  - `islamic_treatment_events`
- Added approval subject support for treatment policies in approval workflow DB constraints.
- Implemented `IslamicTreatmentRoutingService` with fail-closed routing:
  - policy-effectiveness + workflow-usability checks
  - event-type policy enablement checks
  - approved mapping resolution via IF-051 validator
  - ordinary-profit route rejection for non-compliant/late-fee treatment classes
- Implemented IF-052 APIs:
  - `POST /api/v1/islamic-treatment-policies`
  - `GET /api/v1/islamic-treatment-policies`
  - `POST /api/v1/islamic-treatment-policies/{policyPublicId}/approve`
  - `POST /api/v1/islamic-treatment-events`
  - `POST /api/v1/islamic-treatment-events/{eventPublicId}/post`
  - `GET /api/v1/islamic-treatment-reports/reconciliation`
- Added audit events:
  - `islamic.treatment_policy.created`
  - `islamic.treatment_policy.approved`
  - `islamic.treatment_event.blocked`
  - `islamic.treatment_event.posted`
  - `islamic.treatment_event.purified`
  - `islamic.treatment_report.generated`

Proof-by-contradiction tests added in `tests/Feature/Api/IslamicFinanceTest.php`:

- `test_missing_charity_treatment_blocks_late_fee_event`
- `test_zakat_mapping_required_when_zakat_policy_enabled`
- `test_non_compliant_income_cannot_post_to_ordinary_profit_mapping`
- `test_reconciliation_report_matches_source_events_to_posted_journals`

Verification run:

- `php artisan test --parallel --recreate-databases --filter IslamicFinanceTest`
- `php artisan test --parallel --recreate-databases --filter "Islamic(Standards|RegulatorySignoff|ShariaAuthority|Finance|ApprovalWorkflow)Test"`
- `composer test`

Latest result:

- `composer test` passed: `548 tests, 7767 assertions`.

Additional proof-by-contradiction adversarial review (2026-05-25):

1. Contradiction tested: an approved but expired treatment policy could still be used for new routing if effective-window checks were incomplete at event creation.
2. Added contradiction test:
   - `test_expired_treatment_policy_blocks_new_event_routing`
   - proves event creation is rejected when `occurred_on` is outside approved policy effective window.
3. Verification commands:

```bash
php artisan test --parallel --recreate-databases --filter "(missing_charity_treatment_blocks_late_fee_event|zakat_mapping_required_when_zakat_policy_enabled|non_compliant_income_cannot_post_to_ordinary_profit_mapping|expired_treatment_policy_blocks_new_event_routing|reconciliation_report_matches_source_events_to_posted_journals)"
```

- Result: `OK (5 tests, 54 assertions)`

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
```

- Result: `OK (87 tests, 2337 assertions)`

```bash
composer test
```

- Result: `OK (574 tests, 8838 assertions)`
