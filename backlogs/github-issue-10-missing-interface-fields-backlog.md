# GitHub Issue 10 Backlog: Missing Interface Fields

Source issue: GitHub `Benjamin2025-cyber/Habisapps#10`.

Issue title: "Missing fields - `interfaces.pdf` <-> app coverage audit".

Investigation date: 2026-06-06.

## Legitimacy Finding

Issue #10 is partially legitimate.

The client, guarantor, proxy, and batch-procedure gaps are legitimate backend model/API gaps. The loan-product penalty gap is not fully legitimate in the current backend because `operation_type` and `constant_value` already exist on `loan_products`, are accepted by loan-product create/update requests, and are exposed by `LoanProductResource`.

This backlog therefore tracks only the verified missing backend fields and contracts.

## Evidence

- `database/migrations/2026_04_28_045229_create_clients_table.php` has no `civility` or equivalent title/salutation column.
- `app/Http/Requests/Api/V1/StoreClientRequest.php` and `UpdateClientRequest.php` do not accept `civility`.
- `database/migrations/2026_04_28_045230_create_client_guarantors_table.php` models guarantors as a slim record: full name, phone, relationship, status, dates, and KYC document references.
- `app/Http/Requests/Api/V1/StoreClientGuarantorRequest.php` and `ClientGuarantorResource.php` do not include separate first name, birth, parent, profession, address, or identity issuance fields.
- `database/migrations/2026_04_28_045231_create_client_proxies_table.php` models proxies as a slim mandate record with full name, contact, ID type/number, mandate, dates, status, and documents.
- `app/Http/Requests/Api/V1/StoreClientProxyRequest.php` and `ClientProxyResource.php` do not include separate first name, birth, parent, address, or identity issuance fields.
- `database/migrations/2026_04_28_045228_create_batch_procedures_table.php` has no first-class priority column and no attached operation-code relationship.
- `database/seeders/BatchProcedureSeeder.php` stores `execution_priority` inside `schedule_metadata`, but `BatchProcedureController` only validates `schedule_metadata` as a generic array.
- `app/Models/LoanProduct.php`, `StoreLoanProductRequest.php`, `UpdateLoanProductRequest.php`, and `LoanProductResource.php` already include `operation_type` and `constant_value`, so the PDF penalty-row controls are already represented in backend contracts.

## Scope

Add first-class backend support for the missing interface fields needed to match the PDF screens while preserving existing PII controls, agency scoping, lifecycle behavior, and backward compatibility for existing records.

## GHI-010A: Client Civility

Add a nullable client civility/title field.

Recommended field:

- `clients.civility`, nullable string, max 32.

Accepted values should be catalog-controlled:

- `m`
- `mme`
- `mlle`
- `dr`

API contract updates:

- Accept `civility` in `StoreClientRequest`.
- Accept `civility` in `UpdateClientRequest`.
- Expose `civility` in `ClientResource`.
- Include `civility` in client search only if product wants title-based search.

Acceptance criteria:

- Creating a client with `civility` persists and returns it.
- Updating `civility` persists and returns it.
- Invalid civility values return validation errors.
- Existing clients with null civility continue to serialize successfully.
- PII redaction behavior remains unchanged; civility may remain visible because it is not sensitive on its own.

## GHI-010B: Non-Client Guarantor Identity Fields

Extend `ClientGuarantor` for non-client guarantors that do not have a linked client fiche.

Recommended nullable fields:

- `guarantor_civility`
- `guarantor_first_name`
- `guarantor_last_name`
- `guarantor_middle_name`
- `guarantor_date_of_birth`
- `guarantor_place_of_birth`
- `guarantor_identity_document_number`
- `guarantor_identity_issued_on`
- `guarantor_identity_issued_at`
- `guarantor_father_name`
- `guarantor_mother_name`
- `guarantor_profession`
- `guarantor_address_line_1`
- `guarantor_address_line_2`
- `guarantor_business_address_line_1`
- `guarantor_business_address_line_2`

API contract updates:

- Accept the fields in guarantor create/update requests.
- Expose the fields in `ClientGuarantorResource`.
- Apply existing guarantor PII permission masking to personal identity fields.
- Keep `guarantor_full_name` backward-compatible.

Compatibility rule:

- If `guarantor_client_public_id` is present, the linked client remains authoritative for full identity.
- If `guarantor_client_public_id` is absent, the new guarantor identity fields are the standalone source of truth.
- `guarantor_full_name` may be generated from first/middle/last name when structured names are provided, but existing callers using `guarantor_full_name` must still work.

Acceptance criteria:

- Non-client guarantor create/update persists and returns every new field.
- Linked-client guarantors can still be created without duplicating full identity.
- PII-limited actors receive masked/null personal fields according to existing guarantor PII rules.
- Existing guarantor list/search behavior remains compatible.
- Loan guarantee snapshots continue to include the stable guarantor display name and should include structured identity only if needed for legal snapshotting.

## GHI-010C: Client Proxy Personal Identity Fields

Extend `ClientProxy` with the missing personal identity fields from the PDF mandate screen.

Recommended nullable fields:

- `proxy_first_name`
- `proxy_last_name`
- `proxy_middle_name`
- `proxy_date_of_birth`
- `proxy_place_of_birth`
- `proxy_identity_issued_on`
- `proxy_identity_issued_at`
- `proxy_father_name`
- `proxy_mother_name`
- `proxy_address_line_1`
- `proxy_address_line_2`
- `proxy_business_address_line_1`
- `proxy_business_address_line_2`

API contract updates:

- Accept the fields in proxy create/update requests.
- Expose the fields in `ClientProxyResource`.
- Apply existing proxy PII masking to personal identity fields.
- Keep `proxy_full_name` backward-compatible.

Compatibility rule:

- `proxy_full_name` remains required or auto-derived until frontend migration is complete.
- `proxy_id_document_number` stays encrypted as it is today.
- Identity issuance fields should be treated as PII-adjacent and hidden from actors without `crm.pii.view`.

Acceptance criteria:

- Proxy create/update persists and returns each new personal identity field.
- Existing proxy mandate fields still work: operation types, amount limits, status, verification, and recto/verso documents.
- PII-limited actors do not receive unmasked birth, parent, address, or identity issuance fields.
- Verified proxy edits that change personal identity fields move the record back to pending review, matching current behavior for key identity fields.

## GHI-010D: Batch Procedure Execution Priority

Promote execution priority from generic metadata into a first-class, validated API contract.

Recommended field:

- `batch_procedures.execution_priority`, nullable unsigned small integer.

API contract updates:

- Accept `execution_priority` in batch procedure create/update.
- Expose `execution_priority` in `BatchProcedureResource`.
- Preserve existing `schedule_metadata.execution_priority` as a migration/backward-compatibility source.
- Sort accounting-day close-control procedure execution by `execution_priority` when present, then by deterministic fallback.

Acceptance criteria:

- Creating/updating a batch procedure with `execution_priority` persists and returns it.
- Existing seeded procedures migrate their current metadata priority into the first-class column.
- Accounting-day close-control execution order uses the validated priority.
- Invalid priorities return validation errors.
- Existing records with only `schedule_metadata.execution_priority` continue to behave until migration/backfill completes.

## GHI-010E: Batch Procedure Attached Operations

Model the PDF dual-list attachment of operation codes to batch procedures.

Recommended table:

- `batch_procedure_operation_codes`

Recommended columns:

- `id`
- `batch_procedure_id`
- `operation_code_id`
- `agency_id`, nullable if attachments are global
- `status`
- `created_at`
- `updated_at`

API contract updates:

- Accept operation-code attachments in batch procedure create/update, or add dedicated attach/detach endpoints.
- Expose attached operations in `BatchProcedureResource`.
- Validate operation codes against active `operation_codes`.
- Keep operation-code attachments separate from executable batch-procedure codes; they describe accounting/operations linkage, not executor dispatch capability.

Acceptance criteria:

- Platform admins can attach and detach active operation codes to a batch procedure.
- Attached operation codes are returned with public ID, code, label/name, module, operation type, and status.
- Inactive or unknown operation codes are rejected.
- Existing batch procedure execution still works when no operations are attached.
- Tests cover create/update, detach, inactive operation rejection, and serialization.

## Non-Gap: Loan Product Penalty Operation And Constant

Do not create a new backlog item for loan-product penalty `operation` and `constance` unless the frontend confirms a different semantic requirement.

Current backend already has:

- `operation_type`
- `constant_value`

These fields are present on:

- `app/Models/LoanProduct.php`
- `app/Http/Requests/StoreLoanProductRequest.php`
- `app/Http/Requests/UpdateLoanProductRequest.php`
- `app/Http/Resources/LoanProductResource.php`
- `database/migrations/2026_05_11_000000_finalize_stakeholder_complete_schema.php`

Follow-up only if needed:

- Rename display labels in frontend to map PDF `Opération` to `operation_type`.
- Map PDF `Constance` to `constant_value`.
- Add stricter enum validation for `operation_type` if product confirms allowed values.

## Suggested Test Targets

Run focused CRM and administration tests after implementation:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/Module2CrmKycTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/Module1AdministrationTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/StaffUserManagementTest.php
```

Run loan-product tests only if `operation_type` semantics change:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/Module4CreditLoansTest.php --filter loan_product
```

Test-running notes:

- Put `--parallel` before any path argument.
- Do not run multiple `php artisan test --parallel --recreate-databases ...` commands concurrently; database recreation can collide.
