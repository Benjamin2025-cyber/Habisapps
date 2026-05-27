# IF-050 Implementation Plan: Islamic Operation Codes

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-050
Proof-method: proof by contradiction

## IF-050 Source Requirement

Goal: define operation codes for every product family and account event.

Proof-by-contradiction invariant: assume an Islamic event posts through a conventional loan-interest code. Posting must fail.

Acceptance criteria:

- Add operation-code families for Mourabaha, Ijara, Salam, Istisna'a, Moudaraba, Moucharaka, Islamic accounts, profit pools, Zakat-related accounting, charity/non-compliant income treatment, reversals, impairments, and corrections.
- Operation codes declare product family, event type, debit/credit expectations, allowed states, and reversal behavior.
- Conventional interest operation codes cannot be assigned to Islamic products.

Tests:

- Islamic product rejects conventional operation code.
- Missing operation code blocks posting.
- Reversal uses configured reversal code.

## Architecture Context

Current state:

- Islamic posting paths use hardcoded operation-code lookups (e.g., `murabaha_receivable`, `murabaha_payable`, `murabaha_profit`).
- Mapping validation exists at posting time but without a full Islamic operation-code taxonomy registry.
- Interest-guard work is planned (IF-022), but operation-code family governance is not yet complete.

Primary contradiction gap:

- Islamic product families/events are not yet comprehensively represented in a governed operation-code model with explicit reversal/allowed-state semantics.

## Completion Definition For This Plan

IF-050 is sound only if all are true:

- Islamic operation-code catalog covers all required families/events.
- Every Islamic posting path resolves through approved Islamic operation-code definitions.
- Conventional interest codes are structurally blocked from Islamic linkage/use.
- Reversal behavior is explicit per operation code and enforced in posting logic.

## Phase 1: Islamic Operation-Code Taxonomy

Define canonical taxonomy table/metadata extension:

- `islamic_operation_code_profiles` (or enriched `operation_codes` for module `islamic_finance`)

Required metadata per code:

- product family
- event type
- expected debit role
- expected credit role
- allowed contract/workflow states
- reversal mode/code
- classification tags (e.g., `profit_pool`, `zakat`, `charity_treatment`, `impairment`, `correction`)

Coverage must include:

- Mourabaha, Ijara, Salam, Istisna'a, Moudaraba, Moucharaka, Islamic accounts, profit pools, Zakat, charity/non-compliant income, reversals, impairments, corrections.

## Phase 2: Interest-Code Exclusion Guard

Enforce deny rules:

- codes tagged as conventional-interest (`interest_revenue`, `interest_receivable`, loan-interest classes) cannot be linked to Islamic products/events.
- Islamic operation profiles must pass compatibility guard before activation.

Proof by contradiction:

- Assume Islamic event posts with conventional interest code. Impossible because compatibility guard rejects linkage and posting use.

## Phase 3: Product-Family/Event Binding Rules

Create `IslamicOperationCodePolicyService`:

- `assertOperationAllowed(family, eventType, operationCode, contextState)`

Rules:

- operation code must belong to family/event or approved shared class.
- code allowed only in permitted workflow/contract states.
- code must have active approved mapping (ties into IF-051).

## Phase 4: Posting-Path Enforcement

Apply policy in Islamic posting flows (starting with financing workflows):

- replace ad-hoc operation code assumptions with policy-validated resolution.
- missing/invalid code => posting blocked with deterministic error.

Proof by contradiction:

- Assume missing operation code still posts journal. Impossible because posting gate requires successful operation resolution.

## Phase 5: Reversal Semantics

For each Islamic operation code:

- explicit reversal behavior (`auto_reverse`, `manual_reverse`, `forbidden`, `requires_reason`).
- optional linked reversal operation code.

Posting/reversal engine must:

- enforce reversal policy.
- use configured reversal operation code where required.

Proof by contradiction:

- Assume reversal uses arbitrary code. Impossible because configured reversal mapping is mandatory for reversible codes.

## Phase 6: API Surface And Governance

Add operation-code management endpoints (or extend existing operations module):

- create/update Islamic operation profile
- activate/suspend/revoke profile
- list by family/event/state/classification

Governance fields:

- owner
- approval status
- effective window
- audit metadata

## Phase 7: Integration Hooks For IF-051/IF-052

Prepare for mapping and treatment workflows:

- IF-051: mapping approval consumes operation profile metadata.
- IF-052: Zakat/charity/non-compliant treatments use dedicated operation code classes.

## Phase 8: Audit Trail

Record:

- `islamic.operation_code.profile_created`
- `islamic.operation_code.profile_updated`
- `islamic.operation_code.use_blocked`
- `islamic.operation_code.posting_validated`
- `islamic.operation_code.reversal_validated`

## Phase 9: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional focused file:

- `tests/Feature/Api/IslamicOperationCodesTest.php`

Minimum tests:

1. `test_islamic_product_rejects_conventional_interest_operation_code`
2. `test_missing_operation_code_blocks_islamic_posting`
3. `test_reversal_uses_configured_reversal_operation_code`
4. `test_operation_code_family_mismatch_blocks_posting`
5. `test_operation_code_state_restriction_blocks_invalid_state_posting`
6. `test_zakat_classified_operation_code_only_usable_when_policy_enabled`
7. `test_charity_noncompliant_income_operation_class_enforced`
8. `test_suspended_operation_code_blocks_new_postings`
9. `test_profile_activation_requires_required_metadata`
10. `test_operation_code_audit_event_emitted_on_blocked_use`

Proof-by-contradiction acceptance alignment tests:

- `test_islamic_product_rejects_conventional_operation_code`
- `test_missing_operation_code_blocks_posting`
- `test_reversal_uses_configured_reversal_code`

## Phase 10: Adversarial Review (Round 1)

Findings:

1. Risk: taxonomy exists but posting code paths still use hardcoded direct lookups.
2. Risk: interest exclusion enforced at config time only, bypassed at runtime.
3. Risk: reversal policy declared but not checked in reversal execution paths.

Fixes:

1. centralize all Islamic operation resolution through policy service.
2. enforce guard both at linkage/config and at posting runtime.
3. add reversal-policy checks in reversal handlers.

## Phase 11: Adversarial Review (Round 2)

Findings:

1. Ambiguity: shared codes across families can accidentally leak cross-family usage.
2. Risk: state restrictions ignored in batch/system postings.
3. Risk: legacy mappings with missing metadata remain silently usable.

Fixes:

1. explicit shared-class whitelist with scoped constraints.
2. enforce state checks in all posting entrypoints including jobs/batches.
3. fail-closed when required profile metadata missing.

## Phase 12: Adversarial Review (Round 3)

Findings:

1. IF-050 requires broad family coverage; partial code seeding leaves hidden gaps.
2. CI can pass unit tests without end-to-end posting + reversal verification.
3. Reversal code loops/misconfigurations can create invalid cycles.

Fixes:

1. coverage test verifies required family/event matrices are seeded/active.
2. add integration tests through posting and reversal paths.
3. validate reversal graph to prevent loops/invalid targets.

## Phase 13: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are Islamic operation-code families complete across required domains? Yes.
- Are conventional interest codes blocked from Islamic linkage/use? Yes.
- Do posting paths fail when operation codes are missing/invalid? Yes.
- Is reversal behavior configured and enforced per operation code? Yes.
- Are operation-code decisions auditable end-to-end? Yes.

## Test Execution Instructions

Use these commands during IF-050 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for operation-code and posting validation changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicOperationCodesTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Proof-by-contradiction adversarial review findings and fixes:

1. Contradiction: IF-050 requires explicit reversal behavior per operation code, but reversal path reused source event operation code unconditionally.
   - Prior behavior in `storeReversal` inserted reversal event using source code directly.
   - This violated configured reversal-code semantics.

2. Fix:
   - Added operation-code reversal policy resolution in `IslamicFinancingWorkflow`:
     - `reversal_mode` (`auto_reverse|manual_reverse|forbidden|requires_reason`)
     - optional `reversal_operation_code`
   - Enforced runtime reversal policy:
     - blocks `forbidden`.
     - requires `reason` when mode is `requires_reason`.
     - requires configured reversal code when mode is `auto_reverse`.
   - Reversal event now uses resolved reversal operation code.
   - Added runtime checks:
     - Islamic interest guard on resolved reversal code.
     - mapping usability check for resolved reversal code.

3. Audit trail enhancements:
   - Added `islamic.operation_code.reversal_validated` when reversal policy validation passes.

4. Added contradiction proof test:
   - `test_reversal_uses_configured_reversal_operation_code`
   - Configures source operation-code metadata with `auto_reverse` + `reversal_operation_code`, then proves reversal event uses configured code.

Existing IF-050 contradiction tests retained and passing:

- `test_interest_revenue_mapping_rejected_for_collection`
- `test_mapping_use_blocked_event_is_audited`
- `test_reversal_offsets_original_journal_effect`

Verification commands and results:

```bash
php artisan test --parallel --recreate-databases --filter "(reversal_offsets_original_journal_effect|reversal_uses_configured_reversal_operation_code|interest_revenue_mapping_rejected_for_collection|mapping_use_blocked_event_is_audited)"
```

- Result: `OK (4 tests, 191 assertions)`

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
```

- Result: `OK (85 tests, 2281 assertions)`

```bash
composer test
```

- Result: `OK (572 tests, 8782 assertions)`
