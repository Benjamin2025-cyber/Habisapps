# Module 2 Completion Backlog: CRM, KYC, And Mandate Finalization

This backlog complements `backlogs/module-2-crm-kyc-backlog.md`. The existing implementation covers the safe CRM/KYC API. This backlog covers the remaining production decisions and cross-module integrations needed for complete stakeholder Module 2.

## Epic 1: Production KYC Policy Decisions

- [x] DEV-0101: Finalize KYC status vocabulary.
  - [x] Align API states with stakeholder operations language.
  - [x] Add migration or mapping if existing states need renaming.
  - [x] Update resources, docs, and tests.

- [x] DEV-0102: Decide and implement identity document encryption policy.
  - [x] Decide whether document numbers, issue place, and sensitive identity fields require application-level encryption.
  - [x] Add searchable normalized hashes where uniqueness/search is needed.
  - [x] Add migration/backfill strategy for existing data.
  - [x] Tests cover masking, search, uniqueness, and encrypted storage behavior.

- [x] DEV-0103: Implement configurable maker-checker KYC segregation.
  - [x] Prevent self-verification when enabled.
  - [x] Permit explicit override only through protected permission.
  - [x] Audit override usage.
  - [x] Tests cover both enabled and disabled policy modes.

- [x] DEV-0104: Complete client profile photo and business-activity fields.
  - [x] Add or expose profile photo linkage using the existing documents/media foundation.
  - [x] Decide whether photo is a `documents` category, a client-owned media collection, or a dedicated profile field.
  - [x] Cover business start/activity date, business address, home phone, and parent names where required by stakeholder UI.
  - [x] Tests cover upload/link authorization, no raw file paths, agency scope, and PII masking.

## Epic 2: Guarantor And Third-Party Model Completion

- [x] DEV-0201: Decide standalone guarantor strategy.
  - [x] If standalone guarantors are required, add model/API for guarantors independent of clients.
  - [x] Preserve current client-linked guarantor records.
  - [x] Provide migration path or compatibility layer.
  - [x] Tests cover standalone and client-linked guarantor usage.
  - Decision: keep standalone guarantor identities in `client_guarantors` instead of introducing a second guarantor catalog now. The existing API already supports either a same-agency `guarantor_client_id` or standalone guarantor contact fields, and this avoids duplicating KYC identity records before Module 4 loan obligations are active.

- [x] DEV-0202: Prepare loan-specific guarantee obligations.
  - [x] Distinguish guarantor identity from loan guarantee obligation.
  - [x] Link future Module 4 loan guarantor obligations to existing identity records.
  - [x] Tests cover historical identity update not rewriting loan obligation history.

## Epic 3: Account-Specific Proxy Mandates

- [x] DEV-0301: Extend proxies into account-specific mandates.
  - [x] Link proxy authority to customer accounts once Module 3 account products are implemented.
  - [x] Support mandate limits, operation types, start/end dates, and documents.
  - [x] Prevent expired or inactive proxies from transacting.
  - [x] Tests cover account-level authorization and expiry.

## Epic 4: Field Collection Integration

- [x] DEV-0401: Connect collection metadata to future collection workflows.
  - [x] Keep collection settings descriptive until Module 5 cash and Module 4 repayment are ready.
  - [x] Define collection assignment handoff to teller/loan recovery workflows.
  - [x] Tests prove CRM metadata alone does not create expected cash or repayments.

## Completion Gate

- [x] KYC production decisions are documented and implemented.
- [x] Guarantor and proxy models are ready for Module 4/5 integrations.
- [x] Sensitive identity storage policy is verified with tests.
- [x] `vendor/bin/phpstan analyse --memory-limit=1G` passes.
- [x] `vendor/bin/pint --test` passes.
- [x] `php artisan scramble:export` passes and exports `api.json`.
- [x] Focused post-format verification passes: `php artisan test tests/Feature/Api/Module2CrmKycTest.php --filter='identity|collection_metadata'` passes with 5 tests / 92 assertions.
- [x] Full Module 2 feature verification passes: `php artisan test tests/Feature/Api/Module2CrmKycTest.php` passes with 18 tests / 313 assertions.
- [x] KYC-status unit verification passes: `php artisan test tests/Unit/Application/Crm/UpdateClientKycStatusTest.php` passes as part of a 7-test unit slice.
- [ ] `php artisan test` passes.
  - Status: not rerun to completion on 2026-05-16 because the full suite takes too long and was cancelled by operator request. Targeted Module 2 and PHPStan checks passed after fixing collection-target amount formatting.
