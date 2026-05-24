# IF-022 Implementation Plan: Interest Control Guardrails

Date: 2026-05-24
Status: implementation plan
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-022
Proof-method: proof by contradiction

## IF-022 Source Requirement

Goal: prevent Islamic products and accounts from using conventional interest behavior.

Proof-by-contradiction invariant: assume an Islamic account accrues conventional interest. The system must reject product configuration and any posting event using interest mappings.

Acceptance criteria:

- Islamic products cannot bind conventional interest formulas.
- Islamic products cannot post to interest revenue or interest receivable mappings.
- Islamic account statements must label distributions as profit, fees, rent, sale receivable, or approved product-specific terms.
- Late-payment handling must route to approved fee, charity, cost recovery, or corrective treatment only.

Tests:

- Interest formula on Islamic account product is rejected.
- Interest operation code cannot be linked to Islamic product.
- Statement generation rejects forbidden terminology configuration.

## Architecture Context

Current implementation already has:

- Islamic product + financing workflows (`IslamicProductWorkflow`, `IslamicFinancingWorkflow`).
- Accounting mapping linkage through `operation_account_mappings` and Islamic standards links.
- Murabaha-specific mapping use (`murabaha_receivable`, `murabaha_payable`, `murabaha_profit`) in financing posting.

Current contradiction gaps:

- No explicit deny-list/guard that blocks conventional interest formula binding for Islamic products.
- No centralized guard that prevents Islamic posting paths from using interest operation mappings if those mappings become linked.
- No Islamic-specific statement terminology validator to reject forbidden interest labels.
- No explicit late-payment treatment policy gate for Islamic contexts.

## Completion Definition For This Plan

IF-022 is sound only if all are true:

- Islamic product configuration cannot store or activate conventional-interest formula references.
- Islamic posting can only use approved Islamic operation mappings and ledger semantics.
- Islamic statement labels are controlled by approved terminology set and reject forbidden labels.
- Late-payment configuration/execution is restricted to approved Islamic treatments.
- Violations fail fast at configuration time and at runtime posting time.

## Phase 1: Canonical Forbidden/Allowed Taxonomy

Create `IslamicInterestGuardPolicy` constants (service or value object):

Forbidden formula/mapping semantics:

- forbidden formula keys/patterns: `interest`, `apr`, `compound_interest`, `late_interest`, `capitalized_interest`.
- forbidden operation/mapping labels: `interest_revenue`, `interest_receivable`, `loan_repayment_interest`, and equivalents resolved by metadata tags.

Allowed Islamic distribution labels:

- `profit`
- `fees`
- `rent`
- `sale_receivable`
- product-family approved aliases from controlled allowlist

Allowed late-payment treatments:

- `approved_fee`
- `charity`
- `cost_recovery`
- `corrective_treatment`

Proof by contradiction:

- Assume a new interest synonym bypasses checks. Impossible if checks rely on canonical semantic tags/keys, not only display names.

## Phase 2: Product Configuration Guard

Integrate guard in `IslamicProductWorkflow` create/update and compliance-approval readiness checks:

- reject rules/metadata containing forbidden formula engine keys or interest-based policy keys.
- reject product activation if unresolved formula metadata is missing semantic classification.

Implementation notes:

- use strict validation function: `assertNoConventionalInterestBinding(productRules, formulaMetadata)`.
- include concrete error key `islamic_interest_guardrails` for deterministic client behavior.

Proof by contradiction:

- Assume Islamic product stores `flat_interest_v1` and still activates. Impossible with config validation + readiness gate.

## Phase 3: Posting And Mapping Guard

Add runtime enforcement in `IslamicFinancingWorkflow` before journal creation:

- validate resolved operation mappings against forbidden semantics.
- enforce that Islamic postings resolve only to approved Islamic mapping codes (murabaha/ijara/etc. family-specific allowlist).

Add a reusable gate:

- `assertIslamicMappingAllowed(mappingCode, debitLedger, creditLedger, context)`.

Guard both:

- configuration-time linkage (when linking accounting mappings to Islamic standards/products), and
- transaction-time posting (defense in depth).

Proof by contradiction:

- Assume an interest receivable mapping is linked after product approval. Posting must still fail because runtime mapping guard blocks it.

## Phase 4: Statement Terminology Guard

Add Islamic statement terminology policy service used by Islamic account statement generation path:

- validate label set before rendering response/export.
- reject forbidden labels (`interest`, `interest receivable`, `interest income`, localized equivalents mapped to forbidden semantics).
- permit only canonical labels + approved aliases tied to product family.

If Islamic statements are generated through shared account statement endpoint, add an Islamic branch when account/product is Islamic.

Proof by contradiction:

- Assume statement shows "interest income" for Islamic account. Impossible because terminology validator rejects payload before render.

## Phase 5: Late-Payment Treatment Guard

Introduce explicit Islamic late-payment policy field and validator in financing/repayment flows:

- accepted treatments only: approved fee, charity, cost recovery, corrective treatment.
- disallow interest-accrual penalties for Islamic products/accounts.
- route non-compliant treatment attempts to compliance case (reason `forbidden_late_payment_treatment`) and block operation.

Integration points:

- financing contract terms setup,
- repayment/arrears assessment workflows when Islamic product context is detected.

Proof by contradiction:

- Assume overdue Islamic contract applies compounding late interest. Impossible because late-payment validator blocks unsupported treatment codes.

## Phase 6: Data Model And Metadata Hardening

Add semantic metadata requirements where needed:

- mapping metadata includes `finance_semantic_type` (forbidden/allowed classification).
- formula metadata includes `sharia_compatibility` classification.
- statement label metadata includes normalized semantic token.

Constraint rule:

- Islamic-linked mapping/formula cannot be approved/activated without required metadata classification.

## Phase 7: Audit Events And Error Contract

Audit events:

- `islamic.interest_guard.product_binding_rejected`
- `islamic.interest_guard.mapping_rejected`
- `islamic.interest_guard.statement_label_rejected`
- `islamic.interest_guard.late_payment_treatment_rejected`
- `islamic.interest_guard.posting_blocked`

Error payload contract:

- top-level key `islamic_interest_guardrails`
- machine-readable reason codes per violation class.

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Add focused tests (or split to `IslamicInterestGuardrailsTest.php`):

1. `test_islamic_product_rejects_interest_formula_binding`
2. `test_islamic_product_activation_fails_when_formula_semantic_is_interest`
3. `test_interest_operation_mapping_cannot_be_linked_to_islamic_product`
4. `test_islamic_posting_blocks_interest_receivable_or_revenue_mapping`
5. `test_islamic_statement_generation_rejects_forbidden_interest_terminology`
6. `test_islamic_statement_generation_accepts_profit_fee_rent_sale_receivable_labels`
7. `test_islamic_late_payment_treatment_rejects_interest_penalty_mode`
8. `test_islamic_late_payment_treatment_allows_charity_and_cost_recovery`
9. `test_guardrail_violation_records_audit_event`
10. `test_guardrail_errors_are_returned_under_islamic_interest_guardrails_key`

Proof-by-contradiction acceptance alignment tests:

- `test_interest_formula_on_islamic_product_is_rejected`
- `test_interest_operation_code_cannot_link_to_islamic_product`
- `test_statement_generation_rejects_forbidden_terminology_configuration`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: validation only at product create/update; legacy products still post with forbidden mappings.
2. Risk: mapping check based on operation code name can be bypassed by renamed code.
3. Risk: statement label checks done after serialization could leak forbidden labels in exports.

Fixes:

1. enforce guard at runtime posting paths regardless of product creation date.
2. classify mappings by semantic metadata and enforce metadata presence.
3. validate terminology before any response/export formatter path.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: mixed portfolios may share generic statement endpoint; Islamic detection could be skipped.
2. Risk: late-payment restrictions enforced in one workflow but not arrears recalculation job.
3. Risk: partial localization support could miss forbidden terms in non-English labels.

Fixes:

1. derive Islamic context from product/account linkage in a central helper used by all statement paths.
2. place late-payment guard in shared domain service consumed by all repayment/arrears flows.
3. normalize labels to semantic tokens before language rendering; check tokens not raw text.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. IF-022 requires both configuration and posting-event blocking; single-layer checks are insufficient.
2. Product-family expansion (IF-030+) can introduce new label aliases that accidentally map to forbidden semantics.
3. CI may pass narrow tests while integration paths still allow forbidden mappings.

Fixes:

1. keep dual-layer guard: config-time + runtime posting-time enforcement.
2. require alias registration through controlled terminology registry with semantic validation.
3. add integration tests traversing full financing creation -> posting -> statement retrieval pipeline.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are conventional-interest formulas blocked from Islamic product configuration? Yes.
- Are interest revenue/receivable mappings blocked for Islamic postings? Yes.
- Are Islamic statement labels limited to approved terminology and aliases? Yes.
- Are late-payment treatments constrained to approved Islamic routes? Yes.
- Are both configuration and runtime gates enforced with auditable failure signals? Yes.

## Test Execution Instructions

Use these commands during IF-022 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Islamic finance guardrail changes
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicInterestGuardrailsTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
