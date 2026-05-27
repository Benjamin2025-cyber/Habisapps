# IF-030 Implementation Plan: Islamic Product Family Catalog

Date: 2026-05-24
Status: implemented and verified (2026-05-25)
Based-on: `backlogs/islamic-finance-complete-implementation-backlog.md`, IF-030
Proof-method: proof by contradiction

## IF-030 Source Requirement

Goal: define all stakeholder-requested Islamic product families as first-class product families.

Proof-by-contradiction invariant: assume a new Islamic contract is created under an unknown or generic family. Creation must fail because product family is not approved.

Acceptance criteria:

- Product families include Mourabaha, Ijara, Ijara wa Iqtina, Salam, Istisna'a, Moudaraba, Moucharaka, Islamic current account, Islamic savings account, and Islamic investment account.
- Each family defines required fields, workflow states, evidence rules, accounting events, screening rules, reporting category, and readiness checklist.
- Add translated display names where the API needs user-facing labels.

Tests:

- Unknown family rejected.
- Required fields differ by family.
- Product family metadata is exposed through read API.

## Architecture Context

Current code state:

- Baseline services already enumerate several Islamic families (`IslamicStandardsBaselineService`, readiness mapping helpers).
- Product and financing write paths still restrict contract type to `murabaha` (`IslamicProductWorkflow`, `IslamicFinancingWorkflow`).
- Readiness and screening already support product-family scoped evaluation.

Primary contradiction gap:

- Family catalog concept exists partially in standards/readiness helpers but is not first-class and not consistently enforced as source-of-truth across create/read/workflow paths.

## Completion Definition For This Plan

IF-030 is sound only if all are true:

- A canonical product-family registry exists and is queryable.
- Only approved family codes can be used to create Islamic products/contracts/accounts.
- Unknown/generic family values are rejected consistently in every write path.
- Family metadata fully describes required fields, workflows, evidence, accounting events, screening rules, reporting category, and readiness checklist hooks.
- API read surface exposes family metadata and translated display labels.

## Phase 1: Canonical Family Registry Model

Create `islamic_product_families` table (or equivalent immutable registry source with persistence):

Columns:

- `id`, `public_id`
- `code` (unique, canonical machine key)
- `family_kind` (`financing` | `account`)
- `status` (`active` | `retired`)
- `display_name` (default language)
- `display_name_translations` (json)
- `required_fields_schema` (json)
- `workflow_states` (json)
- `evidence_rules` (json)
- `accounting_events` (json)
- `screening_rules` (json)
- `reporting_category` (string)
- `readiness_checklist_template` (json)
- timestamps

Seed required families:

- `mourabaha`
- `ijara`
- `ijara_wa_iqtina`
- `salam`
- `istisnaa`
- `moudaraba`
- `moucharaka`
- `islamic_current_account`
- `islamic_savings_account`
- `islamic_investment_account`

Proof by contradiction:

- Assume unknown family is accepted because enum was not updated. Impossible when writes validate against registry table, not hardcoded partial enum.

## Phase 2: Replace Hardcoded Family Constraints In Write Paths

Update create/update flows to validate through registry service:

- `IslamicProductWorkflow::storeProduct`
- `IslamicFinancingWorkflow::storeFinancing`
- any other Islamic contract/account creation entrypoint

Replace current `Rule::in(['murabaha'])` with dynamic validator:

- `assertSupportedFamily(code, context)`
- optional `assertFamilyKindAllowed(code, contextKind)`

Proof by contradiction:

- Assume IF-030 families exist in registry but cannot be created. Impossible because write validators use the same registry and permit active families by kind.

## Phase 3: Family-Specific Requirement Profiles

Implement family profile resolver:

- `IslamicProductFamilyCatalogService::profile(code)`

Profile must drive:

- required request fields per family
- permitted workflow states/transitions (or required workflow overlays)
- required evidence documents
- required accounting event mappings
- required screening context/rules
- reporting category tagging
- readiness checklist defaults for IF-031 integration

Proof by contradiction:

- Assume all families share one field set. Impossible because validator and readiness input are generated from family-specific profile.

## Phase 4: Read API For Family Catalog

Add endpoints:

- `GET /api/v1/islamic-product-families`
- `GET /api/v1/islamic-product-families/{familyCode}`

Response includes:

- code, status, family_kind
- display names + translations
- required fields summary
- workflow/evidence/accounting/screening/reporting/readiness metadata summaries

Behavior:

- no internal numeric ids exposed
- deterministic ordering by `family_kind`, then `code`

## Phase 5: Integration With Existing Standards/Approval/Screening

Use canonical registry as source for:

- standards link validation (`product_family` linkable code checks)
- sharia authority scope validation for family codes
- regulatory signoff link validation
- screening scope validation (`scope_type=product_family` values)
- readiness mapping in `IslamicProductReadinessService`

Proof by contradiction:

- Assume `ijara` is valid in one service and invalid in another. Impossible when all services call shared registry validator.

## Phase 6: Translation And Label Governance

Define translation policy:

- required base language label
- optional locale-specific translations (`fr`, `ar`, `en`, etc.)
- fallback order for missing translation

Validation:

- translation values non-empty strings
- prevent duplicate locale keys with conflicting empty values

Proof by contradiction:

- Assume API needs user-facing label but only raw code exists. Impossible because registry requires display name and translation schema.

## Phase 7: Backward-Compatibility And Migration

Migration strategy:

- map existing `contract_type` values to family codes via deterministic normalization.
- reject/flag legacy rows with unknown family code for remediation.
- maintain compatibility alias map (`murabaha`/`mourabaha`) only at ingestion boundary, normalize to canonical storage.

Data quality checks:

- no active Islamic product row with null/unknown family code after migration.

## Phase 8: Tests

Primary file:

- `tests/Feature/Api/IslamicFinanceTest.php`

Optional dedicated file:

- `tests/Feature/Api/IslamicProductFamilyCatalogTest.php`

Minimum tests:

1. `test_unknown_product_family_is_rejected_on_product_creation`
2. `test_all_if030_families_are_seeded_and_active`
3. `test_product_family_required_fields_differ_by_family`
4. `test_family_metadata_is_exposed_via_catalog_read_api`
5. `test_financing_creation_rejects_family_kind_mismatch`
6. `test_standards_link_validation_uses_catalog_family_codes`
7. `test_screening_scope_rejects_unknown_product_family`
8. `test_family_translation_labels_are_returned_with_fallback`
9. `test_alias_input_normalizes_to_canonical_family_code`
10. `test_retired_family_cannot_create_new_products`

Proof-by-contradiction acceptance alignment tests:

- `test_unknown_family_rejected`
- `test_required_fields_differ_by_family`
- `test_product_family_metadata_exposed`

## Phase 9: Adversarial Review (Round 1)

Findings:

1. Risk: registry created, but write paths still use stale `Rule::in` lists.
2. Risk: account families included in catalog but financing flows accidentally accept them.
3. Risk: alias handling causes dual canonical values (`murabaha` vs `mourabaha`).

Fixes:

1. remove hardcoded family enums from Islamic workflows and route through shared validator.
2. enforce `family_kind` checks by context.
3. normalize aliases at boundary and store only one canonical code.

## Phase 10: Adversarial Review (Round 2)

Findings:

1. Ambiguity: readiness profile may drift from family metadata if duplicated.
2. Risk: standards/signoff services maintain separate allowed-family arrays.
3. Risk: migration leaves old products without translated labels in API responses.

Fixes:

1. readiness service consumes family catalog profile directly.
2. replace static arrays with catalog-backed validation everywhere.
3. return catalog-based display labels even for legacy products via normalized family code lookup.

## Phase 11: Adversarial Review (Round 3)

Findings:

1. IF-030 requires first-class account families too; implementing financing families only is insufficient.
2. IF-031/IF-032 dependencies can silently break if family checklist/template hooks are missing.
3. CI can pass with family seed present but without API exposure guarantees.

Fixes:

1. include account families in same canonical catalog with `family_kind=account`.
2. require readiness template and contract-template linkage metadata per applicable family.
3. add API assertions for both list and detail endpoints across all required families.

## Phase 12: Final Soundness Checklist

Plan is sound only if every answer is yes:

- Are all stakeholder-requested families present and first-class in a canonical registry? Yes.
- Are unknown/generic families rejected across all create/update contract/account paths? Yes.
- Are family-specific required fields and governance metadata defined and enforced? Yes.
- Are translated user-facing labels available through API? Yes.
- Are standards/screening/sharia/signoff/readiness validators unified on catalog source-of-truth? Yes.

## Test Execution Instructions

Use these commands during IF-030 implementation:

```bash
# Full suite (preferred, reliable on this repo)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Narrow loop for Islamic family-catalog and workflow validation
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest

# Focused file if extracted
php artisan test --parallel --recreate-databases tests/Feature/Api/IslamicProductFamilyCatalogTest.php
```

Command rules:

- Use `composer test` as default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.

## Implementation Status (2026-05-25)

Proof-by-contradiction adversarial review findings and fixes:

1. Contradiction: IF-030 requires canonical family-registry source-of-truth, but standards/signoff link validators still used static family arrays.
   - `IslamicStandardWorkflow::validateAndResolveLink` validated `product_family` and `account_type` against `IslamicStandardsBaselineService` constants.
   - `IslamicRegulatorySignoffWorkflow::link` used the same static constants.
   - This allowed taxonomy drift risk (registry says one thing, link validators another).

2. Fix:
   - Injected `IslamicProductFamilyRegistry` into both workflows.
   - Updated validators to enforce:
     - `product_family` links require `family_kind=financing`.
     - `account_type` links require `family_kind=account`.
   - This unifies family/account link validation with the canonical catalog.

3. Added contradiction test:
   - `test_family_registry_prevents_product_family_account_type_drift_in_links`
   - Proves:
     - account family code (`islamic_current_account`) is rejected for `product_family` links (standards + signoff).
     - same code is accepted for `account_type` links.

Verification commands and results:

```bash
php artisan test --parallel --recreate-databases --filter "(family_registry_prevents_product_family_account_type_drift_in_links|unknown_product_family_is_rejected_on_product_creation|product_family_metadata_is_exposed_via_catalog_api|required_fields_differ_by_family_metadata|financing_creation_rejects_account_family_kind)"
```

- Result: `OK (5 tests, 67 assertions)`

```bash
php artisan test --parallel --recreate-databases --filter IslamicFinanceTest
```

- Result: `OK (81 tests, 2120 assertions)`

```bash
composer test
```

- Result: `OK (568 tests, 8621 assertions)`
