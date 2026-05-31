# Frontend Integration Backend Issues Backlog

Source feedback: `docs/frontendIntegrationfeedabcks/back-issues.md`

Investigation date: 2026-05-31

Method: proof by contradiction. For each frontend issue, assume the current backend already satisfies the expected contract. Then inspect routes, controllers, requests, resources, policies, migrations, and tests for evidence that makes that assumption impossible. Acceptance criteria are written to make the contradiction unrepresentable after the fix.

## Current State Summary

- All 23 reported items have been mapped to current backend code.
- Fix implementation is in progress and several frontend-blocking issues are now regression-covered by integration tests.
- Roles/permissions instability reported by frontend was replicated and is currently green on backend integration suite (`FBI-005/FBI-021` scenario).
- `GET /roles` now reflects persisted role permissions and permission-policy metadata; teller permission grant/revoke flow is read-after-write consistent in integration.
- `GET /roles`, `GET /formula-policies`, `GET /reference/identity-document-types`, `GET /fx-register`, and insurance report/export list endpoints now expose server-side `search` plus `page`/`per_page` pagination metadata where applicable.
- Search + pagination coverage has been expanded across list endpoints used by frontend modules, including sectors, sub-sectors, tills, teller sessions, insurance reporting/export surfaces, the Islamic finance timeline / ledger read surfaces, dashboards, health, and catalog/report summary GETs.

## Integration Replication (2026-05-31)

Objective: validate frontend-reported behavior by hitting backend the same way frontend does, not only older backend feature tests.

Executed integration suites:

- `npm run -s test:feedback` in `habis-finance-api-test/suite`
- `npm run -s test:modules` in `habis-finance-api-test/suite`

Observed:

- `test:feedback`: `9/9` passed, including:
  - `FBI-005/FBI-021 teller matrix: explicit replace, no silent revoke, read-after-write consistency`
  - `FBI-002/FBI-021 role assignment reflects immediately and does not crash staff GET`
- `test:modules`: `347/347` passed after the latest search/pagination changes, including the Islamic timeline/ledger GET contract updates and the OpenAPI walk over all authenticated GET endpoints.
- Direct feature verification also passed for `tests/Feature/Api/IslamicFinanceTest.php`, `tests/Feature/Api/DashboardsTest.php`, and `tests/Feature/Api/HealthEndpointTest.php`.
- No integration regression reproduced for the current roles/permissions flows covered by frontend repro tests.
- Follow-up targeted feature checks also passed for `GET /roles`, `GET /formula-policies`, `GET /fx-register`, `GET /insurance-reports/active-subscriptions`, and `GET /insurance-exports/subscriptions`.

## FBI-001: Add Search To Agency Index

Source issue: `#1 Referentiel > Agence: how do we search agencies?`

Status: Investigated, fix pending.

Contradiction proof:

- Assume `GET /api/v1/agencies` supports searching agencies.
- `routes/api/v1/auth.php:31` exposes only the index route, and `app/Application/Agencies/AgencyWorkflow.php:28` builds a paginated query scoped by platform-admin/current agency.
- The index method applies no `search`, `q`, `filter[...]`, code, name, city, region, or branch-name predicate before pagination.
- Therefore the current backend contradicts the expected searchable referential list.

Fix backlog:

- Add a server-side `search` query parameter to `AgencyWorkflow::index`.
- Search at minimum `code`, `name`, `city`, `region`, `branch_name`, email, and phone where appropriate.
- Preserve the current agency-scope behavior for non-platform users.

Acceptance criteria:

- Feature test proves `GET /api/v1/agencies?search=AKW` returns matching agencies by code and name.
- Feature test proves search never leaks agencies outside a non-platform user's current agency.
- Feature test proves blank search is ignored and pagination metadata remains stable.
- API docs/OpenAPI expose the search parameter and searched fields.

## FBI-002: Staff Creation Plus Role Assignment Causes GET Crash Until Backend Restart

Source issue: `#2 When we create a user and assign a role, the get endpoints crashed...`

Status: Fix implemented in worktree, targeted regression passing.

Contradiction proof:

- Assume staff creation, role assignment, and subsequent GET endpoints are covered as a stable end-to-end flow.
- Before this fix, `tests/Feature/Api/StaffUserManagementTest.php` covered staff creation and platform-admin role update separately, but did not assert `POST /staff-users -> PUT /staff-users/{id}/roles -> GET /staff-users` in one request sequence.
- Before this fix, `app/Application/Staff/StaffAccessControlWorkflow.php:80` called `$staffUser->syncRoles($roles)` without explicitly clearing Spatie's permission cache after role changes, unlike `app/Application/Authorization/SyncRolePermissions.php:17`.
- `app/Http/Resources/StaffUserResource.php:59` calls `getAllPermissions()` while serializing staff users, which depends on Spatie role/permission state.
- Therefore the prior test and cache handling were too weak to disprove the transient crash report.

Fix backlog:

- Added a feature test for the exact create-role-read sequence.
- Clear `PermissionRegistrar` cache after staff role sync.
- Ensure staff user serialization tolerates newly assigned roles without restart.

Acceptance criteria:

- Regression test executes `POST /api/v1/staff-users`, `PUT /api/v1/staff-users/{id}/roles`, `GET /api/v1/staff-users`, and `GET /api/v1/staff-users/{id}` in one process without 500s.
- Test asserts the assigned roles and effective permissions are reflected immediately without application restart.
- If a stale cache is the root cause, code explicitly clears permission cache after `syncRoles`.
- Any exception path returns structured JSON without debug details in production.

## FBI-003: Identity Document Type Catalog

Source issue: `#3 API accepts pure string; expose accepted identity document types`.

Status: Investigated, fix pending.

Contradiction proof:

- Assume the backend has a discoverable catalog of accepted identity document types.
- `routes/api/v1/auth.php:62-66` exposes nested identity-document CRUD/status routes only.
- `app/Http/Requests/Api/V1/StoreClientIdentityDocumentRequest.php` and `UpdateClientIdentityDocumentRequest.php` validate `document_type` as a free string with max length, not an enum.
- Repository search found no route or config catalog for document types.
- Therefore the backend accepts arbitrary strings and cannot drive a frontend type select.

Fix backlog:

- Add a versioned catalog endpoint, for example `GET /api/v1/reference/identity-document-types`.
- Define stable keys, labels, expiry requirements, and face requirements.
- Use the same catalog in validation.

Acceptance criteria:

- `GET /api/v1/reference/identity-document-types` returns stable machine keys such as `national_id` and `passport`, localized/display labels, and `required_faces`.
- Store/update identity document requests reject unknown document types with 422.
- Tests prove existing valid fixture values pass and typo values fail.
- API docs include the catalog endpoint and request validation enum.

## FBI-004: Client Proxy ID Number Encryption Overflows Column

Source issue: `#4 POST /clients/{id}/proxies returns 500, encrypted column too small`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume any valid non-null `proxy_id_document_number` can be stored.
- `app/Models/ClientProxy.php:116` casts `proxy_id_document_number` as `encrypted`.
- `database/migrations/2026_04_28_045231_create_client_proxies_table.php:22` creates `proxy_id_document_number` as `varchar(128)`.
- Laravel encrypted payloads are larger than the source string and can exceed 128 characters.
- Therefore a valid request can overflow the column, contradicting the expected create-proxy contract.

Fix backlog:

- Add a migration changing `client_proxies.proxy_id_document_number` to `text` or a sufficiently large encrypted payload column.
- Keep request input max length based on plaintext business rules.
- Add regression coverage for non-null proxy ID document numbers.

Acceptance criteria:

- Feature test creates a proxy with a typical document number and receives 201, not 500.
- Database assertion proves the encrypted stored value length can exceed 128 without truncation.
- Response still masks `proxy_id_document_number` unless the actor has the required PII permission.
- Existing null document-number behavior continues to pass.

## FBI-005: Protected Permission Assignment Blocks Admin Control

Source issue: `#5 Protected permissions can only be granted to platform administrators`.

Status: Confirmed, product decision needed, fix pending.

Contradiction proof:

- Assume an admin can grant any permission to any role.
- `app/Http/Controllers/Api/V1/RoleController.php:41-48` rejects protected permissions for every role except `platform-admin`.
- `RoleController::protectedPermissions()` includes institution scope, PII, KYC verification, archive, and cash-management permissions.
- Therefore current backend intentionally forbids the requested admin-controlled permission model.

Fix backlog:

- Decide whether protected permissions remain platform-admin-only or can be delegated by platform-admin to non-platform roles.
- If delegation is allowed, replace hardcoded target-role restriction with actor authority, audit logging, and possibly a risk acknowledgement flag.
- Keep minimum platform-admin protections intact.

Acceptance criteria:

- Tests prove platform-admin can grant an approved protected permission to a non-platform role when policy allows it.
- Tests prove non-platform actors still cannot grant protected permissions.
- Tests prove platform-admin cannot remove required minimum platform-admin permissions.
- Role update response and `GET /roles` show the same persisted permission set.

## FBI-006: Super Admin KYC Approval Returns 403

Source issue: `#6 Super admin has permission to approve KYC documents but approve returns 403`.

Status: Investigated, likely maker-checker/self-verification path, fix pending.

Contradiction proof:

- Assume platform-admin KYC approval always succeeds when the role has the permission.
- `app/Application/Crm/ClientKycWorkflow.php:54-60` forbids verifying a client when maker-checker says the verifier is the submitter unless `allow_self_verify` and override permission are present.
- `app/Http/Controllers/Api/V1/ClientIdentityDocumentController.php:294-302` similarly returns 403 or 422 for self-verification without explicit override.
- Platform-admin has broad permissions, but the endpoint can still return 403 because of maker-checker override semantics.
- Therefore the observed 403 can occur even when the role appears to have approval permission.

Fix backlog:

- Clarify API contract for KYC verification: normal checker approval vs self-verification override.
- Return field-level 422 for missing override flag instead of ambiguous 403 where the actor is otherwise authorized.
- Document frontend behavior for `allow_self_verify` and required reason fields if override is used.

Acceptance criteria:

- Test proves platform-admin can verify a client KYC submitted by another user.
- Test proves platform-admin self-verification without `allow_self_verify` returns a structured error explaining the override requirement.
- Test proves platform-admin self-verification with `allow_self_verify=true` and permission succeeds and is audit logged.
- Same coverage exists for identity-document verify action.

## FBI-007: Admin Client Data Is Masked Despite View/Manage Permissions

Source issue: `#7 Admin can view/manage clients but receives incomplete/masked client data`.

Status: Confirmed, fix pending after role decision.

Contradiction proof:

- Assume `crm.clients.view` and `crm.clients.update/manage` are sufficient to view full client data.
- `app/Http/Resources/ClientResource.php:24` computes `$showPii` using only `crm.pii.view`.
- `ClientResource` masks names, phone, email, birth, address, and other fields when `crm.pii.view` is absent.
- `RoleController::protectedPermissions()` protects `crm.pii.view`, so many admin-like roles cannot be granted it under current rules.
- Therefore client management permissions do not imply PII access, contradicting the frontend expectation.

Fix backlog:

- Decide which roles should have PII read access and whether PII is separate from client management.
- If admins need full client profiles, add `crm.pii.view` to the role or allow controlled delegation.
- Expose explicit `pii_redacted` metadata so the frontend can distinguish missing data from masked data.

Acceptance criteria:

- Tests prove a role with `crm.clients.view` but without `crm.pii.view` receives masked fields and `meta.pii_redacted=true`.
- Tests prove an authorized admin role with `crm.pii.view` receives unmasked PII.
- Tests prove permission changes are reflected by `GET /roles` and client responses immediately.
- API docs explain PII masking semantics.

## FBI-008: Account Number Auto-Generation Contract

Source issue: `#8 Should we have a formula to auto generate account numbers?`

Status: Investigated, fix pending product decision.

Contradiction proof:

- Assume customer account numbers can already be generated by the backend.
- `config/reference_numbers.php:14-17` defines an `account` sequence.
- `app/Http/Requests/StoreCustomerAccountRequest.php:32` still requires `account_number`.
- `app/Application/Accounts/CustomerAccountWorkflow.php:140` persists the request-provided account number rather than reserving the configured account sequence.
- Therefore the backend has a sequence capability but does not use it for customer account creation.

Fix backlog:

- Decide whether account numbers are always generated or optionally client-provided.
- If generated, make `account_number` optional and reserve `ReferenceNumberGenerator::reserve('account')`.
- Ensure uniqueness and idempotency under concurrent creation.

Acceptance criteria:

- Test proves creating a customer account without `account_number` generates a unique `ACC########` value.
- Test proves provided account numbers are either rejected or accepted according to the product decision.
- Test proves concurrent account creation cannot duplicate generated numbers.
- API docs describe account-number generation behavior.

## FBI-009: Add Search To Staff Users Index

Source issue: `#9 Staff users should have a search query param`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume `GET /api/v1/staff-users` supports search.
- `routes/api/v1/auth.php:39` exposes the staff index.
- `app/Application/Staff/StaffUserProfileWorkflow.php:28` builds an agency-scoped paginated query but applies no text search predicate.
- Therefore the staff list is not server-searchable.

Fix backlog:

- Add a `search` query parameter to `StaffUserProfileWorkflow::index`.
- Search by name, phone, email, matricule, job title, agency code/name, and possibly role.
- Preserve agency scoping.

Acceptance criteria:

- Feature test proves staff can be found by name, phone, email, and matricule.
- Feature test proves agency manager search cannot return staff outside the current agency.
- Blank search is ignored and pagination remains correct.
- API docs include searchable fields.

## FBI-010: Document Files Are Write-Only For Frontend Display

Source issue: `#10 Backend does not serve images; uploaded client profile photo cannot be displayed`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume uploaded documents/images can be displayed by the frontend.
- `app/Http/Controllers/Api/V1/DocumentController.php:135` returns only `DocumentResource` metadata.
- The method docblock explicitly states file download is not exposed.
- No route exists for document download, preview, or signed URL.
- Therefore uploaded images are write-only from the frontend perspective.

Fix backlog:

- Add a secure document file retrieval endpoint, preferably signed/short-lived URLs or streamed responses.
- Enforce document policy and agency/PII scope before serving bytes.
- Return content type, cache controls, and disposition appropriate for images/PDFs.

Acceptance criteria:

- Test uploads a PNG/JPEG and retrieves it through the new endpoint with the same content type and bytes/hash.
- Test proves unauthorized users and cross-agency users cannot retrieve the file.
- Client resources that expose profile photo document IDs include enough URL or link data for display.
- API docs document download/preview behavior and expiry if signed URLs are used.

## FBI-011: Super Admin Cannot Upload Client Image

Source issue: `#11 Super admin has create client permission but image upload returns 403`.

Status: Confirmed likely agency-resolution mismatch, fix pending.

Contradiction proof:

- Assume platform-admin can upload documents needed for client image/profile creation.
- `StoreDocumentRequest::authorize()` requires `documents.create`, which platform-admin has.
- `app/Http/Controllers/Api/V1/DocumentController.php:73-75` then calls `resolveAgencyId`.
- `resolveAgencyId()` returns only `$user->currentAgencyId()`, so a platform-admin without a current agency assignment receives 403.
- Therefore global create authority is contradicted by agency resolution during upload.

Fix backlog:

- Add explicit agency selection for platform/institution actors, for example `agency_public_id` on document upload.
- Keep non-platform users constrained to current agency.
- Ensure profile-photo linking validates selected agency equals client agency.

Acceptance criteria:

- Test proves platform-admin can upload a document with `agency_public_id`.
- Test proves platform-admin without `agency_public_id` receives a 422 explaining that agency is required, not an unexplained 403.
- Test proves agency users cannot upload to another agency.
- Test proves uploaded profile photo can be linked only to a same-agency client.

## FBI-012: Guarantor And Proxy Management Scope Decision

Source issue: `#12 Guarantors and proxies outside client scope do not exist; OK on clients but PDF has pages`.

Status: Investigated, architectural decision backlog.

Contradiction proof:

- Assume institution-level guarantor/proxy pages have backend support.
- `routes/api/v1/auth.php:68-78` exposes guarantor and proxy routes only under `clients/{client}`.
- Controllers enforce `client_id` and `agency_id` predicates in nested indexes.
- Therefore independent institution-level pages cannot be backed without new read models/routes or N+1 client traversal.

Fix backlog:

- Keep create/update/status mutations nested under clients to preserve ownership and validation.
- Add institution/agency-wide read-only indexes as described in FBI-013.
- Document that standalone "referential" pages are transversal views, not independent guarantor/proxy owners.

Acceptance criteria:

- API documentation clearly states guarantors/proxies are client-owned records.
- Transversal list endpoints exist for UI referential pages.
- No duplicate standalone create endpoint bypasses client-scoped validation.
- Frontend can render PDF-inspired pages without N+1 client traversal.

## FBI-013: Add Institution-Wide Guarantor And Proxy Indexes

Source issue: `#13 Need GET /api/v1/guarantors and GET /api/v1/proxies with filters`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume institution-wide guarantor/proxy listing exists.
- Routes only include `GET clients/{client}/guarantors` and `GET clients/{client}/proxies`.
- `ClientGuarantorController::index()` and `ClientProxyController::index()` both filter by a single `client_id` and `agency_id`.
- Therefore the backend forces N+1 client traversal and cannot return all guarantors/proxies in one paginated server-side list.

Fix backlog:

- Add `GET /api/v1/guarantors` and `GET /api/v1/proxies`.
- Support `scope=all`, `filter[status]`, `filter[verification_status]`, `filter[agency_id]`, and text search over names/phone.
- Reuse client index institution-scope logic.

Acceptance criteria:

- Tests prove platform/institution-read actors can list all agencies with `scope=all`.
- Tests prove agency-scoped actors see only current-agency records even if `scope=all` is requested.
- Tests prove status, verification_status, agency, and search filters work with pagination.
- Tests prove response includes client_public_id and linked document/customer-account public IDs needed by the UI.

## FBI-014: Loan Product Penalty Fields Are Unvalidated And Unused

Source issue: `#14 Produits de pret, penalty fields are dead and not validated`.

Status: Confirmed, fix pending product decision.

Contradiction proof:

- Assume `penalty_formula_type`, `penalty_formula_base`, `penalty_value_type`, and `penalty_value` are meaningful loan product controls.
- `StoreLoanProductRequest` and `UpdateLoanProductRequest` validate the first three as free strings/numeric only.
- `AssessLoanArrearsAndPenalties` reads `penalty_grace_days` from the product but calculates penalty amount from `config('formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty')`.
- `LoanProductFormulaPolicySnapshotter` snapshots the four product fields but does not interpret them.
- Therefore the fields are persisted/displayed but not authoritative business logic.

Fix backlog:

- Choose one direction: wire these fields into penalty calculation with enums, or remove/deprecate them while keeping penalty policy config as the only source.
- If wired, define allowed formula types, bases, value types, and calculation semantics.
- If removed, remove from API resources/requests or mark read-only/deprecated with migration strategy.

Acceptance criteria:

- Tests prove typo values such as `flate_rate` and `principel` are rejected if fields remain.
- Tests prove penalty assessment either honors product penalty field values or no longer exposes writable dead fields.
- Tests prove product snapshot content matches actual penalty behavior.
- API docs expose valid enum/catalog values or explicitly document global-config-only penalties.

## FBI-015: Missing GET For Loan Approvals And Active Schedule

Source issue: `#15 No GET for visas nor amortization table`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume loan approvals and generated schedules can be reloaded after refresh.
- `routes/api/v1/credit.php:29` exposes only `POST loans/{loan}/approvals/{step}` for approvals.
- `routes/api/v1/credit.php:35-36` exposes only schedule generate/reschedule POST routes.
- `LoanResource` does not include approvals or active schedule.
- Therefore the frontend can only see these states immediately after mutation responses, not by later GET.

Fix backlog:

- Add `GET /api/v1/loans/{loan}/approvals`.
- Add `GET /api/v1/loans/{loan}/schedule` returning active snapshot and lines.
- Consider optional include params on `GET /loans/{loan}` for `approvals` and `active_schedule`.

Acceptance criteria:

- Test proves approvals GET returns step, decision, acted_by, acted_at, and comments in approval order.
- Test proves schedule GET returns active snapshot and all lines after generation without regenerating.
- Test proves no active schedule returns 404 or an explicit empty state according to contract.
- Tests prove permissions and agency scoping match `GET /loans/{loan}`.

## FBI-016: Loan Creation 500 For Non-Approved Formula Policy

Source issue: `#16 POST /loans returns 500 if product references non-approved formula policy`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume creating a loan from an invalid formula-policy product returns a controlled validation error and names the failing policy.
- `config/formulas.php` has `penalties_and_arrears.approved=false`.
- Loan product requests allow `penalty_policy_key=penalties_and_arrears` via `Rule::in`.
- `LoanCrudWorkflow::store()` calls `LoanProductFormulaPolicySnapshotter::applyToLoan()` without catching `FormulaPolicyNotApproved`.
- `LoanProductFormulaPolicySnapshotter::snapshot()` checks `$errors` but throws while iterating configured policies, so it can name the first configured policy instead of the failing policy.
- Therefore valid product creation can later make loan creation fail with a 500 and misleading policy name.

Fix backlog:

- Validate formula policy approval at loan product create/update time.
- Catch `FormulaPolicyNotApproved` in loan creation and return 422 for defense in depth.
- Fix snapshotter to throw/report the actual unapproved fields from `$errors`.

Acceptance criteria:

- Test proves creating/updating a loan product with an unapproved policy returns 422 naming `penalties_and_arrears`.
- Test proves loan creation from an already-invalid existing product returns 422, not 500.
- Test proves error payload identifies the exact field and policy key that is unapproved.
- Formula policy approval rules are shared between validation and snapshot generation.

## FBI-017: KYC Documents Lack Face Model, Type Catalog, And File Retrieval

Source issue: `#17 KYC documents: no recto/verso, type free, file not retrievable`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume the backend supports real KYC document capture requirements.
- `DocumentController::store()` accepts one `file`.
- `Document` media collection is used as a single evidence object from identity/guarantor/proxy records.
- `client_identity_documents` has one `document_id`; guarantor/proxy records also have one `document_id`.
- `document_type` is free string only on client identity documents; guarantor/proxy only have coarse document category or proxy-specific free type.
- Document file retrieval is not exposed, as confirmed in FBI-010.
- Therefore the current model cannot represent front/back document faces, shared type semantics, or displayable evidence files.

Fix backlog:

- Add identity document type catalog with required faces.
- Add front/back document links or multi-file evidence model for client identity docs, guarantors, and proxies.
- Add secure document retrieval endpoint.

Acceptance criteria:

- Tests prove a national ID requiring front and back cannot be verified unless both faces are present.
- Tests prove passport or single-face types require only one face.
- Tests prove guarantor/proxy evidence records carry the same document type semantics where required.
- Tests prove uploaded files can be retrieved by authorized users and blocked cross-agency.

## FBI-018: Guarantee Obligation Release Condition Is Free Text And Ignored

Source issue: `#18 release_condition non validated and non exploited`.

Status: Confirmed, fix pending product decision.

Contradiction proof:

- Assume `release_condition` controls release behavior.
- `LoanGuaranteeObligationController::validateObligation()` validates `release_condition` as nullable free string max 128.
- `release()` ignores `release_condition` and always requires `loan.status === closed`.
- Therefore values other than `loan_closed` can be stored but have no effect.

Fix backlog:

- Decide supported release conditions, for example `loan_closed`, `manual`, `date`, `guarantee_replaced`.
- Add enum validation/catalog.
- Wire release logic to the condition or restrict the field to `loan_closed` only.

Acceptance criteria:

- Tests prove unknown release_condition values are rejected.
- Tests prove each supported release condition controls release behavior.
- If only `loan_closed` is supported, tests prove every non-`loan_closed` value is rejected.
- API docs expose release condition options and semantics.

## FBI-019: LoanResource Omits Outstanding Amounts

Source issue: `#19 LoanResource does not expose outstanding amounts`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume loan list/show responses provide real outstanding values.
- `database/migrations/2026_05_11_000000_finalize_stakeholder_complete_schema.php:104-115` adds outstanding projection columns.
- `app/Models/Loan.php` does not include outstanding projection columns in fillable/casts.
- `app/Http/Resources/LoanResource.php` exposes requested and approved principal but not `outstanding_principal_minor` or `global_outstanding_amount_minor`.
- Therefore consumers must fall back to approved/requested amounts, contradicting the requirement for real exposure/mutation totals.

Fix backlog:

- Add outstanding projection columns to `Loan` casts/fillable if they are mutable by services.
- Expose `outstanding_principal_minor`, `global_outstanding_amount_minor`, and likely `total_unpaid_amount_minor`/`due_amount_minor` in `LoanResource`.
- Confirm projection writers update those columns or mark them as nullable projections.

Acceptance criteria:

- Test creates/updates a loan with outstanding projection values and asserts `GET /loans` and `GET /loans/{loan}` return them.
- Tests prove null projection values serialize as null, not misleading requested/approved fallbacks.
- Mutation and dashboard consumers can compute totals from returned outstanding fields.
- API docs include field definitions and null semantics.

## FBI-020: Formula Policy Catalog Endpoint

Source issue: `#20 Expose formula policies catalog for loan product UI`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume the frontend can discover formula policy keys and approval status.
- `FormulaPolicyKey` enum and `config/formulas.php` hold policy keys and `approved` flags.
- Product request validation hardcodes one `Rule::in` value per policy field.
- There is no route exposing formula policy keys, categories, labels, or approval status.
- Therefore the frontend must hardcode hidden/disabled policy behavior and cannot know `approved=false`.

Fix backlog:

- Add `GET /api/v1/formula-policies`.
- Return key, category, approved, label, owner, approved_at, and allowed product field(s).
- Refactor product validation to use approved policy keys by category where appropriate.

Acceptance criteria:

- Test proves catalog includes all `FormulaPolicyKey` cases with accurate `approved` values from config.
- Test proves unapproved policies are visible as disabled/non-selectable or excluded according to API contract.
- Test proves product validation and catalog derive from the same source.
- API docs include category and field mapping.

## FBI-021: GET Roles Reads Config Instead Of Persisted Role Permissions

Source issue: `#21 Role editor writes DB but reads config`.

Status: Fix implemented in worktree, targeted regression passing.

Contradiction proof:

- Assume `PUT /roles/{role}/permissions` and `GET /roles` use the same source of truth.
- `RoleController::updatePermissions()` persists permissions through Spatie role permissions.
- Before this fix, `RoleController::roleCatalog()` built each role's `permissions` from `configuredRoleDefinitions()`, which reads `config('security.permissions.roles')`.
- Therefore `GET /roles` could ignore DB changes and show stale defaults, making successful updates look like no-ops.
- A second failure mode follows from the same stale baseline: because `PUT /roles/{role}/permissions` replaces the full role permission set, a frontend sending an incomplete stale selection can revoke omitted permissions while still receiving a success response.

Fix backlog:

- Changed `roleCatalog()` to load persisted Spatie role permissions for each configured role.
- Kept permission catalog sourced from configured known permissions.
- De-duplicate and sort permissions in update handling/response for deterministic editor baselines.
- Include roles existing in DB but absent from config only if product wants dynamic roles.

Acceptance criteria:

- Regression test grants a permission through PUT and proves immediate `GET /roles` returns that permission for the role.
- Test revokes a permission and proves `GET /roles` no longer returns it.
- Test proves available permission catalog still includes configured permissions.
- Test proves no permanent unsaved-change baseline mismatch after refresh.

## FBI-022: Cannot Update Loan Linked Accounts After Draft

Source issue: `#22 Impossible to modify linked accounts including recovery_account after draft`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume recovery/unpaid/amortization/transfer accounts can be corrected when the loan is active.
- `LoanCrudWorkflow::update()` returns 422 unless `loan.status === application`.
- `RecoverLoanFromAccounts` uses `loan.recovery_account_id` after disbursement/active states.
- No route exists for `PATCH /loans/{loan}/accounts` or equivalent post-draft account update.
- Therefore a missing recovery account at draft time cannot be added when it becomes operationally needed.

Fix backlog:

- Add a dedicated linked-account update endpoint or safely relax update for account fields only.
- Restrict allowed statuses and account fields based on lifecycle and accounting impact.
- Audit every linked-account change.

Acceptance criteria:

- Test proves an active/disbursed loan can update recovery_account through the new contract.
- Test proves non-account loan terms still cannot be edited after application stage.
- Test proves selected account belongs to the same client and agency and is active.
- Test proves recovery workflow uses the newly updated recovery account.

## FBI-023: Loan Index Lacks Client Filter

Source issue: `#23 Index /loans has no filter by client`.

Status: Confirmed, fix pending.

Contradiction proof:

- Assume the frontend can fetch all loans for a selected client from the backend.
- `LoanCrudWorkflow::index()` filters only by agency/institution scope and optional `status`.
- No `filter[client_public_id]` or `client_public_id` predicate is applied.
- No `GET /clients/{client}/loans` route exists.
- Therefore client-specific loan lists are forced to frontend filtering of an arbitrary page, which can miss older loans.

Fix backlog:

- Add `filter[client_public_id]` or top-level `client_public_id` to `GET /api/v1/loans`.
- Optionally add `GET /api/v1/clients/{client}/loans` as a convenience alias.
- Preserve agency and institution scoping.

Acceptance criteria:

- Test proves `GET /api/v1/loans?filter[client_public_id]=...` returns all loans for that client across pages.
- Test proves another client's loans are excluded even when they are newer.
- Test proves cross-agency client filters are forbidden or empty according to scope policy.
- API docs include the filter syntax and pagination behavior.

## Suggested Fix Order

1. High integration blockers: FBI-004, FBI-010, FBI-011, FBI-015, FBI-016, FBI-021, FBI-023.
2. Frontend list usability: FBI-001, FBI-009, FBI-013.
3. Authorization and role semantics: FBI-005, FBI-006, FBI-007, FBI-002.
4. Domain contract decisions: FBI-003, FBI-008, FBI-012, FBI-014, FBI-017, FBI-018, FBI-020, FBI-022, FBI-019.

## Adversarial Implementation Review (2026-05-31)

Scope: review of the current worktree implementation against this backlog, with extra focus on the frontend report that permissions/roles behave inconsistently: granting a permission can revoke another one, and successful responses do not always appear in later reads.

Status: implementation is not yet safe to hand back to frontend as complete. Several tickets have code and tests in progress, but the findings below still make the permission/document/loan contracts ambiguous or brittle.

### AIR-001: Role Permission Updates Still Use Full Replacement Semantics

Severity: High.

Related tickets: FBI-005, FBI-021, FBI-002.

Evidence:

- `app/Http/Controllers/Api/V1/RoleController.php:36-39` accepts a required `permissions` array.
- `app/Http/Controllers/Api/V1/RoleController.php:82` calls `syncPermissions($permissions)` through `SyncRolePermissions`.
- `syncPermissions` replaces the entire role permission set. It is not an additive grant endpoint and not a single-permission revoke endpoint.
- Therefore, if the frontend sends only the checkbox the user just toggled, the backend returns success while every omitted permission is revoked. This matches the frontend symptom: "je donne des permission a un teller meme, mais ca revoke plutot".

Required fix:

- Make the API contract explicit and hard to misuse.
- Option A: keep `PUT /roles/{role}/permissions` as full replacement, but require an explicit flag such as `mode=replace` or `replace=true`, return `previous_permissions`, `added_permissions`, and `removed_permissions`, and document that the frontend must send the full selected set.
- Option B: add safer mutation endpoints such as `POST /roles/{role}/permissions/{permission}` and `DELETE /roles/{role}/permissions/{permission}` for checkbox toggles, while keeping `PUT` for bulk replacement only.
- In both cases, add an optimistic-concurrency guard such as `permissions_version` or `updated_at` so stale role editor state cannot overwrite a newer grant.

Acceptance criteria:

- Test proves sending a partial toggle payload cannot silently revoke all omitted permissions.
- Test proves a full replacement response includes explicit `added_permissions` and `removed_permissions`.
- Test proves stale frontend baselines are rejected or detected instead of producing a misleading success.

### AIR-002: Protected Permission Delegation Conflicts With Seeded Non-Platform Roles

Severity: High.

Related tickets: FBI-005, FBI-006, FBI-007, FBI-021.

Evidence:

- `config/security.php:455-483` gives `kyc-officer` protected permissions such as `crm.pii.view`, `crm.kyc.verify`, `crm.identity_documents.verify`, and `crm.guarantors.pii.view`.
- `app/Http/Controllers/Api/V1/RoleController.php:49-56` rejects any protected permission on a non-`platform-admin` role unless `security.permissions.allow_protected_delegation` is enabled.
- Therefore, a role can legitimately contain protected permissions from the seed/config baseline, but a role editor cannot save that same full permission set under the default config. That makes normal editing of KYC/admin-like roles fail with 422 or forces the frontend to drop protected permissions from the payload.

Required fix:

- Decide whether configured baseline protected permissions for non-platform roles are allowed.
- If yes, allow retaining already-granted/configured protected permissions and block only newly-added protected permissions unless delegation is enabled.
- If no, remove protected permissions from non-platform role defaults and provide a controlled delegation workflow.
- Surface `protected`, `delegable`, `non_delegable`, and `currently_granted` metadata in `GET /roles` so the frontend can disable or explain restricted checkboxes.

Acceptance criteria:

- Test proves saving the current `kyc-officer` permission set does not fail purely because it already contains configured protected permissions.
- Test proves adding a new non-delegable protected permission to a non-platform role is still blocked.
- Test proves `GET /roles` exposes enough metadata for the frontend to distinguish disabled permissions from ordinary unchecked permissions.

### AIR-003: Platform Admin Document Upload Fix Does Not Extend To Read/Download/Archive

Severity: High.

Related tickets: FBI-010, FBI-011, FBI-017.

Evidence:

- `DocumentController::store` now allows platform/institution actors to upload with `agency_public_id`.
- `app/Policies/DocumentPolicy.php:18-22` still requires `isCurrentAgency($user, $document->agency_id)` for `view`, even for `platform-admin`.
- `app/Http/Controllers/Api/V1/DocumentController.php:186-200` uses the same `view` policy for the new file endpoint.
- Therefore, a platform-admin without an active agency assignment can upload a file for a selected agency, receive success, and then fail to show or download that same file for frontend display.

Required fix:

- Update `DocumentPolicy::view` and `archive` so platform-admin or institution-scoped document actors can access selected-agency documents according to the same agency-resolution model used by upload.
- Preserve same-agency enforcement for normal agency users.
- Add tests for platform-admin-without-current-agency upload, metadata show, file download, and archive.

Acceptance criteria:

- Test proves platform-admin can upload with `agency_public_id` and immediately retrieve `GET /documents/{document}`.
- Test proves platform-admin can retrieve `GET /documents/{document}/file` for that document.
- Test proves cross-agency agency users remain forbidden.

### AIR-004: Back-Face Identity Evidence Can Be Reused Or Duplicated

Severity: Medium.

Related tickets: FBI-003, FBI-017.

Evidence:

- `StoreClientIdentityDocumentRequest` blocks `back_document_public_id` from equaling `document_public_id`.
- `UpdateClientIdentityDocumentRequest` does not include the same `different:document_public_id` rule.
- `ClientIdentityDocumentController::resolveLinkableDocument` only checks existing links through `document_id`, not `back_document_id`.
- Therefore a document already used as another client's back face can be linked again, and update can attach the same file as both front and back evidence unless caught elsewhere.

Required fix:

- Add `different:document_public_id` to update validation.
- In `resolveLinkableDocument`, check both `document_id` and `back_document_id`.
- Add a database-level guard if the business invariant is "one evidence file belongs to one identity-document face only".

Acceptance criteria:

- Test proves update rejects identical front/back document IDs.
- Test proves a back-face document already linked to another client cannot be reused as front or back evidence.
- Test proves re-saving the same identity document can retain its own current front/back evidence without false positives.

### AIR-005: Loan Linked-Account Update Can Return Success With No Accepted Fields

Severity: Medium.

Related ticket: FBI-022.

Evidence:

- `UpdateLoanLinkedAccountsRequest` defines all four account fields as `sometimes|nullable`.
- `LoanCrudWorkflow::updateLinkedAccounts` builds `$updates` only from validated known fields and saves even if `$updates` is empty.
- Laravel validation ignores unknown payload keys by default, so a frontend typo such as `recovery_account_id` instead of `recovery_account_public_id` can produce a success response while changing nothing.

Required fix:

- Require at least one accepted linked-account field with `required_without_all` or an after-validation check.
- Reject unknown account-update keys or return a structured 422 when no recognized fields are present.
- Include `changed_fields` in the API response, not only the audit event.

Acceptance criteria:

- Test proves `{}` returns 422.
- Test proves `{ "recovery_account_id": "..." }` returns 422 instead of success.
- Test proves a valid update response lists the exact changed fields.

### AIR-006: Missing Regression Tests For The Remaining Ambiguities

Severity: Medium.

Related tickets: FBI-005, FBI-010, FBI-011, FBI-017, FBI-022.

Required test additions:

- Role partial-update misuse: prove a checkbox-style payload cannot revoke omitted permissions silently.
- Seeded protected role save: prove current `kyc-officer` permissions can be re-saved or returns a deliberate, documented 422 with frontend metadata.
- Platform-admin document lifecycle: upload with `agency_public_id`, then show/download/archive without current agency assignment.
- Identity back-face uniqueness: reject front/back equality on update and reject reuse through `back_document_id`.
- Loan linked-account no-op payload: reject empty and unknown-key payloads.

## Adversarial Implementation Review Round 2 (2026-05-31)

Scope: re-review after AIR-001 through AIR-006 were worked on in the current worktree.

Status: the Round 1 issues now have code/test evidence in progress:

- AIR-001: `PUT /roles/{role}/permissions` now exposes replacement diffs and versioning, and bulk replacement requires explicit `replace=true`. Single-checkbox grant/revoke endpoints exist so toggles do not revoke omitted permissions.
- AIR-002: protected permission metadata is exposed in `GET /roles`, seeded protected role permissions can be retained, and new non-delegable protected grants remain blocked.
- AIR-003: `DocumentPolicy` now lets platform/institution-scoped actors read/manage selected-agency documents consistently with upload, while agency users remain scoped.
- AIR-004: identity document evidence now checks front and back links, and update validation prevents front/back equality across partial updates.
- AIR-005: linked-account updates now reject empty payloads and unknown fields, including mixed valid-plus-unknown payloads.
- AIR-006: targeted regression tests were added for role replacement/toggles, protected permission baseline save, platform-admin document lifecycle, identity evidence uniqueness, and linked-account no-op payloads.

### AIR-007: Front-Only Identity Update Could Still Duplicate The Back Face

Severity: High.

Related ticket: FBI-017.

Evidence found in Round 2:

- The first AIR-004 fix guarded `front == back` only inside the `back_document_public_id` branch.
- A payload changing only `document_public_id` to the current back-face document could still bypass the guard.

Fix applied:

- `ClientIdentityDocumentController::update` now computes effective front and back IDs after both optional branches and rejects equality no matter which field changed.
- `resolveLinkableDocument` now rejects reuse by any other identity record in the same agency, not only reuse by a different client.

Regression tests:

- `test_identity_back_face_evidence_uniqueness_is_enforced` now covers front-only update to current back face.
- The same test covers same-client evidence reuse and cross-client reuse.

### AIR-008: Linked-Account Updates Could Mask Unknown Keys When A Valid Key Was Present

Severity: Medium.

Related ticket: FBI-022.

Evidence found in Round 2:

- The first AIR-005 fix rejected payloads where no recognized account fields were present.
- A mixed payload such as `recovery_account_public_id` plus typo `recovery_account_id` could still succeed while silently ignoring the typo.

Fix applied:

- `LoanCrudWorkflow::updateLinkedAccounts` now rejects any unknown keys before validation/update.

Regression tests:

- `test_linked_account_update_rejects_empty_and_unknown_key_payloads` now covers empty payload, unknown-only payload, mixed valid-plus-unknown payload, and valid changed-field metadata.

### AIR-009: Identity Type Catalog Advertised Expiry Requirements That Verification Did Not Enforce

Severity: Medium.

Related tickets: FBI-003, FBI-017.

Evidence found in Round 2:

- `IdentityDocumentTypeCatalog` returns `requires_expiry=true` for passport/national ID-like documents.
- Verification only rejected expired dates; it did not reject a missing expiry date for types that require one.

Fix applied:

- `IdentityDocumentTypeCatalog::requiresExpiry` now exposes the catalog rule to runtime code.
- Identity-document verification now returns 422 on `expires_on` when a required-expiry type has no expiry date.

Regression tests:

- `test_identity_document_expiry_requirement_is_enforced_from_catalog` proves passport without expiry cannot be verified.
- The same test proves `voter_card` can be verified without expiry because the catalog marks it `requires_expiry=false`.

### Round 2 Verification Evidence

Commands executed sequentially:

```bash
php artisan test --parallel --recreate-databases --filter=identity_back_face_evidence_uniqueness_is_enforced
php artisan test --parallel --recreate-databases --filter=linked_account_update_rejects_empty_and_unknown_key_payloads
php artisan test --parallel --recreate-databases --filter=role_permission
php artisan test --parallel --recreate-databases --filter=identity_document_expiry_requirement_is_enforced_from_catalog
php artisan test --parallel --recreate-databases --filter=self_verify_override_flags_do_not_bypass_verification_controls
php artisan test --parallel --recreate-databases tests/Feature/Api/Module1AdministrationTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/Module2CrmKycTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/Module4CreditLoansTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/FoundationOperationsTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/StaffUserManagementTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/Module3AccountingProductTest.php
composer test
```

Observed results:

- `identity_back_face_evidence_uniqueness_is_enforced`: passed, `1 test, 29 assertions`.
- `linked_account_update_rejects_empty_and_unknown_key_payloads`: passed, `1 test, 12 assertions`.
- `role_permission`: passed, `4 tests, 51 assertions`.
- `identity_document_expiry_requirement_is_enforced_from_catalog`: passed, `1 test, 21 assertions`.
- `self_verify_override_flags_do_not_bypass_verification_controls`: passed, `1 test, 43 assertions`.
- `Module1AdministrationTest`: passed, `27 tests, 304 assertions`.
- `Module2CrmKycTest`: passed, `31 tests, 520 assertions`.
- `Module4CreditLoansTest`: passed, `38 tests, 900 assertions`.
- `FoundationOperationsTest`: passed, `20 tests, 89 assertions`.
- `StaffUserManagementTest`: passed, `15 tests, 106 assertions`.
- `Module3AccountingProductTest`: passed, `6 tests, 96 assertions`.
- Full suite via `composer test`: passed, `690 tests, 10708 assertions`.

Round 2 conclusion:

- No additional actionable findings remained after the Round 2 fixes and full-suite verification.

## Verification Checklist For Future Fixes

- Every fixed ticket must add at least one feature or unit test that first fails against the contradiction described above.
- Every new API surface must be covered by route tests, authorization/scope tests, and API documentation.
- Every enum/catalog decision must share the same source between validation and catalog output.
- Every file or PII surface must prove same-agency/institution authorization and non-leakage in tests.
- Every money/formula field must be either executable business logic or removed/deprecated from writable API payloads.

## Test Execution Instructions

Use these commands while implementing FBI tickets:

```bash
# Frontend-facing integration replication (run first for reported UI issues)
cd ../habis-finance-api-test/suite
npm run -s test:feedback
npm run -s test:modules

# Full suite (preferred default, aligned with IF-020)
composer test

# Equivalent explicit full-suite command
php artisan test --parallel --recreate-databases

# Focused role/permission regression loop
php artisan test --parallel --recreate-databases --filter=role_catalog
php artisan test --parallel --recreate-databases --filter=staff_role_assignment

# Focused feature files used by current FBI-002/FBI-021 fixes
php artisan test --parallel --recreate-databases tests/Feature/Api/Module1AdministrationTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/StaffUserManagementTest.php

# Focused files for current frontend-integration tickets
php artisan test --parallel --recreate-databases tests/Feature/Api/Module2CrmKycTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/Module3AccountingProductTest.php
php artisan test --parallel --recreate-databases tests/Feature/Api/Module4CreditLoansTest.php

# Focused filters for the adversarial findings above
php artisan test --parallel --recreate-databases --filter=role
php artisan test --parallel --recreate-databases --filter=document
php artisan test --parallel --recreate-databases --filter=linked_accounts
```

Command rules:

- For frontend-reported integration bugs, run `test:feedback` before backend-only test filters.
- Use `composer test` as the default full-suite entrypoint.
- Put `--parallel` before any path argument.
- Do not run multiple `php artisan test --parallel --recreate-databases ...` commands concurrently; database recreation can collide at the Postgres sequence/table level.
- Do not run multiple non-parallel `php artisan test ...` processes concurrently.
- If running targeted commands without `--parallel`, run them sequentially.
