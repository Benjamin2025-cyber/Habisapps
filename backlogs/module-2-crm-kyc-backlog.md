# Module 2 Backlog: CRM & KYC

This backlog covers stakeholder Module 2 from `stakeholderResources/definedModules.md`: client identity, KYC profile data, identity documents, guarantors, proxies, and collection assignment metadata.

Module 2 is the next safe module after Module 1 because it depends on the completed administration/security foundation, but does not require ledger posting, loan formula approval, teller sessions, repayment allocation, or cash reconciliation. It must consume existing agency scope, staff assignment, authorization, audit, reference-number, and private document/media foundations.

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.

Completion note (2026-05-16): this original safe-slice backlog is superseded for final production scope by
`backlogs/module-2-crm-completion-backlog.md`. Items below that say "Not In Module 2" describe the old safe-slice
boundary, not remaining Module 2 work.

## Implementation Status (2026-04-30)

Completed in code and verified in this implementation pass:

- [x] DEV-0101, DEV-0102, DEV-0103, SEC-0101
- [x] DEV-0201, DEV-0202, SEC-0201
- [x] DEV-0301, DEV-0302, SEC-0301
- [x] DEV-0401, DEV-0402, SEC-0401
- [x] DEV-0501, DEV-0502, SEC-0501
- [x] DEV-0601, SEC-0601
- [x] DEV-0701, DEV-0702, DEV-0703, SEC-0701

Completed after documentation pass:

- [x] DEV-0801 (Scramble/OpenAPI documentation update for Module 2 endpoints)
- [x] DEV-0802 (CRM operational documentation updates)

Verification completed in this pass:

- [x] `php artisan test --filter=Module2CrmKycTest`
- [x] `php artisan test --filter="Module1AdministrationTest|FoundationOperationsTest|StaffUserManagementTest|FoundationSchemaIntegrityTest|Module2CrmKycTest"`
- [x] `vendor/bin/phpstan analyze`
- [x] `php artisan scramble:export`

Completion backlog resolution:

- [x] Formula-adjacent business clarifications that affect Module 2 have been resolved or moved to their owning modules.
- [x] Final KYC vocabulary, encryption, and segregation-of-duties decisions are implemented in `backlogs/module-2-crm-completion-backlog.md`.

## Guiding Rules

- [x] Laravel scaffolding must be generated through Laravel/Artisan commands whenever Laravel provides a command for the artifact, then reviewed and adjusted manually as needed.
- [x] Composer must be used for package installation; package config and migrations must be published through Laravel/vendor publish commands where provided.
- [x] Public APIs must expose `public_id` and business references, not internal integer IDs.
- [x] Every client, guarantor, proxy, identity-document, KYC-review, and assignment mutation must be authenticated, authorized, agency-scoped, and audit logged.
- [x] KYC read endpoints that expose restricted PII must be permission-gated and audit logged.
- [x] List/search endpoints must not become identity enumeration endpoints.
- [x] Agency users must never view or mutate clients outside their active agency unless explicitly granted cross-agency authority.
- [x] Documents attached to clients, identity documents, guarantors, or proxies must be private and authorized through the owning domain record.
- [x] Identity documents must be verified through explicit transitions, not direct unguarded status updates.
- [x] Client KYC state must not imply account opening, loan approval, balance mutation, or ledger posting.
- [x] Collection-agent assignment is metadata only in this module; cash collection workflows belong to later cash/accounting modules.
- [x] Any amount-like field captured in Module 2 is user-entered descriptive metadata only; no formula, rounding, balance, fee, interest, repayment, penalty, schedule, report, or cash value may be calculated until `docs/domain/stakeholder-formula-questions.md` is answered and approved.
- [x] Do not implement customer accounts, account balances, loan applications, collateral valuation, cash deposits, withdrawals, or ledger postings in this backlog.

## Why Module 2 Is Next

- [x] It follows `docs/domain/implementation-roadmap.md`: Phase 2 CRM/KYC comes after Administration and before Accounting.
- [x] Existing migrations already provide the structural foundation for `clients`, `client_identity_documents`, `client_guarantors`, and `client_proxies`.
- [x] Existing document/media controls already support private KYC uploads without exposing raw storage paths.
- [x] The module can be completed with business-record CRUD, lifecycle, authorization, and audit behavior without approving stakeholder formulas because it must not calculate, allocate, round, or derive financial values.
- [x] It reduces risk for Module 3 and Module 4 by ensuring accounts and loans can depend on verified customer records.

## Epic 1: Client Profile Foundation

- [x] DEV-0101: Implement client domain models and route binding.

As a developer, I want first-class Eloquent models for CRM records so controllers, policies, resources, and tests do not operate directly on raw tables.

Acceptance criteria:

- [x] Models are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] `Client` model exists with ULID public route key.
- [x] Client relationships include agency, prospector, collection agent, identity documents, guarantors, proxies, and documents where applicable.
- [x] `ClientIdentityDocument`, `ClientGuarantor`, and `ClientProxy` models exist with ULID public route keys where the table has `public_id`.
- [x] Fillable/casts are explicit and do not allow unsafe mass assignment of verification actors, timestamps, or final statuses.
- [x] Model constants or enums define supported status and verification values.
- [x] Tests prove route model binding uses public IDs and rejects internal IDs.

- [x] DEV-0102: Align client schema with Module 2 API contract.

As a developer, I want the client table to support the required stakeholder KYC fields without leaking future accounting or loan responsibilities into CRM.

Acceptance criteria:

- [x] Migrations are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] Client records include agency scope, client reference, identity names, birth data, contact details, address fields, occupation/business metadata, status, KYC status, and onboarding dates.
- [x] Missing stakeholder fields required for the API are added through migrations rather than overloaded into metadata.
- [x] Collection configuration fields are represented as metadata only if the business rules are not yet stable.
- [x] Prospector and collection-agent references must point to active staff who are authorized in the client's agency.
- [x] Client references are generated through `ReferenceNumberGenerator` using the configured `client` sequence.
- [x] Unique constraints prevent duplicate client references inside an agency.
- [x] Tests cover schema integrity, public IDs, agency foreign keys, and client reference uniqueness.

- [x] DEV-0103: Implement client create/list/show/update API.

As a KYC or operations user, I want to create and maintain client profiles according to my agency authority.

Acceptance criteria:

- [x] Controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] API supports creating, listing, showing, and updating clients.
- [x] Create/update requests reject unknown fields, oversized strings, impossible dates, unsafe enum values, and future birth dates.
- [x] Agency managers and KYC staff can create clients only in their active agency unless they have explicit cross-agency permission.
- [x] Platform or auditor users with read authority can query across agencies only with an explicit scope parameter.
- [x] List endpoints support bounded pagination and safe filters without exposing unrestricted full-text PII scraping.
- [x] Responses expose public IDs, references, allowed PII, status, KYC status, and timestamps without internal IDs.
- [x] Client create/update actions are audit logged with safe before/after summaries.
- [x] Tests cover authorization, validation, public contract, agency scope, audit records, and no internal ID exposure.

- [x] SEC-0101: Review client profile abuse paths.

As a security reviewer, I want client profile APIs to resist cross-agency access, mass assignment, identity enumeration, and PII leakage.

Acceptance criteria:

- [x] Cross-agency list/show/update attempts are denied for agency-scoped users.
- [x] Search behavior does not reveal whether a phone number, email, or document number exists unless the actor is authorized.
- [x] PII fields are masked or omitted where the caller lacks explicit KYC/compliance permission.
- [x] Audit logs do not store full identity document numbers, raw uploaded paths, or unnecessary contact details.
- [x] Tests prove internal IDs cannot be used to fetch clients.

## Epic 2: KYC Status And Verification Workflow

- [x] DEV-0201: Implement controlled client KYC lifecycle transitions.

As a KYC reviewer, I want client records to move through explicit review states so verified customer status cannot be forged by ordinary profile updates.

Acceptance criteria:

- [x] Controllers, requests, policies, and tests are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] Supported KYC states include draft, pending_review, verified, rejected, suspended, and archived, or a documented equivalent compatible with existing data.
- [x] API supports submitting a client for review, approving/marking verified, rejecting with reason, suspending, and archiving.
- [x] Direct profile update endpoints cannot set privileged KYC states.
- [x] Verification requires required identity evidence according to configured minimum rules.
- [x] Rejection reason is required for rejected status and is safe for API display.
- [x] Suspended/archived clients cannot be used by future account or loan creation workflows.
- [x] State changes record actor, timestamp, previous state, next state, reason, and agency.
- [x] Tests cover valid transitions, invalid transition denial, missing evidence denial, and audit records.

- [x] DEV-0202: Implement KYC review history.

As an auditor, I want KYC decisions preserved as immutable history so status changes can be reconstructed.

Acceptance criteria:

- [x] Migrations, models, resources, and tests are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] KYC review history stores client, actor, agency, previous status, new status, reason/comment, and timestamp.
- [x] History is append-only from application workflows.
- [x] API exposes review history to authorized KYC, compliance, audit, and platform actors.
- [x] Agency-scoped users cannot view review history for another agency.
- [x] Review history responses do not expose internal IDs.
- [x] Tests cover append-only behavior, authorization, agency scope, and response contract.

- [x] SEC-0201: Review KYC lifecycle bypasses.

As a security reviewer, I want KYC approval to be hard to fake through profile edits, stale assignments, replayed requests, or forged document links.

Acceptance criteria:

- [x] Ordinary client update requests cannot set verification actor or verified timestamps.
- [x] Idempotent transition retries do not create duplicate review history entries.
- [x] A user losing agency authority cannot continue approving clients in the old agency.
- [x] Archived or suspended clients cannot be reactivated without explicit permission and audit reason.
- [x] Tests cover direct status tampering, replay, stale authority, and unauthorized document reuse.

## Epic 3: Identity Documents

- [x] DEV-0301: Implement client identity-document API.

As a KYC user, I want to register official identity evidence for clients without storing document data directly on the client row.

Acceptance criteria:

- [x] Controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] API supports create, list, show, update, archive, submit for verification, verify, and reject identity documents.
- [x] Document type, document number, issue date/place or issuing authority, expiry date, verification status, and linked private document are represented.
- [x] Identity document numbers are normalized for uniqueness checks without changing display-safe storage behavior.
- [x] Duplicate document number conflicts use generic responses that do not leak the owning client to unauthorized users.
- [x] Expired documents cannot verify a client unless a documented override permission is used.
- [x] Verification actions record actor and timestamp.
- [x] Tests cover validation, duplicate handling, expiry handling, archive behavior, and audit records.

- [x] DEV-0302: Bind private document uploads to identity evidence.

As a KYC user, I want uploaded files to be linked to the correct identity record so files cannot be reused across clients silently.

Acceptance criteria:

- [x] Identity document records can link only to active `documents` records in the same agency.
- [x] Linked documents must have a KYC-safe category and private media in the `kyc_documents` collection.
- [x] Archived documents cannot satisfy verification requirements.
- [x] A document already linked to one identity record cannot be linked to a different client unless an explicit reviewed reuse rule is approved.
- [x] API responses never expose disk, path, temporary URL, media ID, or internal document ID.
- [x] Tests cover cross-agency document linking denial, archived document denial, duplicate link denial, and no storage path exposure.

- [x] SEC-0301: Review identity-document PII and ownership risks.

As a security reviewer, I want identity-document APIs to avoid document-number leaks, private-file bypasses, and ownership confusion.

Acceptance criteria:

- [x] Full document numbers are returned only to actors with explicit KYC/compliance permission.
- [x] List responses mask document numbers by default unless full PII access is authorized and audited.
- [x] Cross-agency users cannot infer document existence through conflict, not-found, or validation responses.
- [x] Document verification cannot be performed by the same actor who uploaded the file if segregation-of-duties is enabled.
- [x] Tests cover masking, cross-agency inference attempts, and unauthorized file association.

## Epic 4: Guarantors

- [x] DEV-0401: Implement guarantor API.

As a KYC or credit-preparation user, I want to capture guarantor identity/contact information before loans reference guarantors.

Acceptance criteria:

- [x] Controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] API supports create, list, show, update, archive/deactivate, submit for verification, verify, and reject guarantor records.
- [x] A guarantor may be linked to another client in the same agency or represented as standalone identity/contact details.
- [x] A client cannot be their own guarantor.
- [x] Guarantor-client links cannot cross agencies without explicit transfer/cross-agency authority.
- [x] Verification status is controlled through explicit actions.
- [x] Linked documents must be active, private, and agency-compatible.
- [x] Tests cover same-client denial, cross-agency denial, lifecycle transitions, document linking, and audit records.

- [x] DEV-0402: Preserve guarantor reuse boundaries.

As a future credit module implementer, I want guarantor records to be reusable without rewriting historical loan relationships.

Acceptance criteria:

- [x] Guarantor records expose stable public IDs for future loan/collateral linking.
- [x] API distinguishes the guarantor identity record from future loan-specific guarantee obligations.
- [x] Updating guarantor contact data does not mutate historical relationship dates.
- [x] Guarantor deactivation prevents new future use but does not delete existing history.
- [x] Tests cover deactivation, history preservation, and no cascade deletion of clients.

- [x] SEC-0401: Review guarantor abuse paths.

As a security reviewer, I want guarantor APIs to resist coerced identity reuse, hidden cross-agency relationships, and accidental deletion of evidence.

Acceptance criteria:

- [x] A malicious user cannot attach another agency's client as guarantor.
- [x] A user cannot replace a verified guarantor's identity details without resetting verification status or requiring re-review.
- [x] Deactivation and rejection are audit logged with reason.
- [x] Full guarantor contact data is masked unless the actor is authorized for restricted PII.
- [x] Tests cover verified-data tampering and cross-agency inference.

## Epic 5: Proxies And Mandates

- [x] DEV-0501: Implement client proxy/mandate API.

As an operations user, I want to register authorized representatives for clients without granting them account-operation power before accounts exist.

Acceptance criteria:

- [x] Controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] API supports create, list, show, update, archive/deactivate, verify, reject, and expire proxy records.
- [x] Proxy records include identity/contact fields, mandate type, start date, end date, status, and linked mandate/signature document.
- [x] Proxy date ranges must be valid and cannot be active when ended.
- [x] Active proxy status requires valid date range and required identity evidence.
- [x] Proxy records remain client-scoped in this module; account-specific operating authority is deferred until customer accounts exist.
- [x] Tests cover validation, lifecycle, agency scope, document linking, and audit records.

- [x] DEV-0502: Implement proxy expiry behavior.

As an operations user, I want expired mandates to stop appearing as active authority.

Acceptance criteria:

- [x] API filters expose active/current proxies separately from expired/inactive records.
- [x] Expired proxies cannot be used by future account-operation workflows.
- [x] Optional scheduled or command-based expiry marking is documented if implemented.
- [x] Expiry does not delete mandate history or linked documents.
- [x] Tests cover date-bound active filtering and historical visibility.

- [x] SEC-0501: Review proxy authority confusion.

As a security reviewer, I want proxy records to avoid implying unauthorized account access or allowing stale mandates to be abused.

Acceptance criteria:

- [x] API wording and response fields do not imply a proxy can transact before account-module rules exist.
- [x] A stale active status cannot override an expired end date.
- [x] Proxy document links cannot cross agencies or owners.
- [x] Full proxy identity/contact data is permission-gated and audit logged when exposed.
- [x] Tests cover expired mandate denial and stale status handling.

## Epic 6: Collection Assignment Metadata

- [x] DEV-0601: Implement collection-agent assignment metadata.

As an agency manager, I want client collection metadata recorded so future cash/loan workflows can use it after accounting controls exist.

Acceptance criteria:

- [x] Controllers, requests, resources, policies, and tests are scaffolded through Laravel/Artisan-supported commands where applicable, then reviewed and adjusted manually.
- [x] Client collection type, frequency, optional user-entered target amount, and collection agent can be stored where supported by approved schema.
- [x] Target amount, if captured, is stored exactly as submitted within decimal validation limits and is not rounded, calculated, allocated, posted, reported, or used to infer expected cash.
- [x] If stakeholders have not approved target-amount semantics, the API may defer the amount field while still supporting collection type, frequency, and collection agent assignment.
- [x] Collection agent must be active and assigned to the client's agency unless explicit cross-agency authority exists.
- [x] Collection metadata changes are audit logged.
- [x] No cash receipt, ledger posting, deposit, withdrawal, or balance update is created.
- [x] Tests cover invalid agent denial, cross-agency denial, validation, and no financial side effects.

- [x] SEC-0601: Review collection metadata misuse.

As a security reviewer, I want collection metadata to avoid becoming an unapproved cash workflow.

Acceptance criteria:

- [x] No endpoint accepts actual payment, receipt, or collection completion data in Module 2.
- [x] Amount fields cannot be negative, floating-point, currency-ambiguous, rounded by the application, or used in any formula.
- [x] Collection-agent assignment changes cannot be used to access another agency's client list.
- [x] Tests prove no journal entries, teller transactions, or customer accounts are created.

## Epic 7: Authorization, Permissions, And Audit

- [x] DEV-0701: Add Module 2 permissions and role assignments.

As a platform administrator, I want CRM/KYC permissions represented explicitly so staff authority is not inferred from broad administrative roles.

Acceptance criteria:

- [x] Permissions exist for client read/create/update/archive, KYC submit/review/verify/reject, identity-document manage/verify, guarantor manage/verify, proxy manage/verify, restricted PII view, and CRM audit view where needed.
- [x] Role seeding grants least-privilege defaults to platform admin, agency manager, KYC/compliance staff, loan officer, auditor, and baseline staff.
- [x] Sensitive review and restricted PII permissions are not assigned to baseline staff.
- [x] Permission catalog API groups CRM permissions by module.
- [x] Tests cover seeded permission availability and default role grants.

- [x] DEV-0702: Implement CRM policies and agency query scopes.

As a developer, I want reusable policy/scope behavior so every CRM endpoint enforces the same agency boundary.

Acceptance criteria:

- [x] Policies exist for clients, identity documents, guarantors, and proxies.
- [x] Query builders default to the actor's active agency unless explicit cross-agency scope is authorized.
- [x] Auditors can read according to permission scope but cannot mutate CRM records.
- [x] Platform admins must still specify agency context when creating agency-owned records.
- [x] Tests cover agency manager, platform admin, auditor, KYC staff, loan officer, and unauthorized staff behavior.

- [x] DEV-0703: Expand audit coverage for Module 2.

As an auditor, I want every sensitive CRM action recorded so customer identity changes are traceable.

Acceptance criteria:

- [x] Client create/update/archive and KYC transitions emit audit records.
- [x] Identity-document create/update/archive/verify/reject emits audit records.
- [x] Guarantor and proxy create/update/archive/verify/reject emits audit records.
- [x] Restricted PII read endpoints emit audit records with actor, agency, resource, and reason/scope where available.
- [x] Audit payloads mask restricted identity and contact data.
- [x] Tests cover audit records for every mutation family and restricted PII read path.

- [x] SEC-0701: Review CRM authorization and audit completeness.

As a security reviewer, I want the completed Module 2 APIs reviewed before accounting, accounts, loans, or cash operations depend on them.

Acceptance criteria:

- [x] Cross-agency access attempts are covered for every CRM endpoint.
- [x] Permission escalation attempts are covered for KYC review, restricted PII, and document verification.
- [x] PII masking and audit behavior are reviewed against `docs/security/pii-data-classification.md`.
- [x] Idempotency behavior is reviewed for retryable CRM mutation endpoints.
- [x] Audit logs are reviewed for completeness and sensitive data leakage.
- [x] `vendor/bin/phpstan analyze` passes.
- [x] `php artisan test` passes.
- [x] Any findings are fixed or explicitly tracked with risk owner and target date.

## Epic 8: API Documentation And Operational Readiness

- [x] DEV-0801: Update Scramble/OpenAPI documentation for Module 2 APIs.

As an API consumer, I want Module 2 endpoints documented accurately so clients do not infer KYC behavior from implementation details.

Acceptance criteria:

- [x] Client, identity-document, guarantor, proxy, KYC-review, and collection-metadata endpoints are documented.
- [x] Request schemas document required fields, validation rules, status values, and date semantics.
- [x] Response schemas exclude internal IDs, raw private storage paths, media IDs, and unauthorized restricted PII.
- [x] Error documentation covers unauthorized, forbidden, validation, conflict/idempotency, not found, and invalid transition cases.
- [x] Scramble/OpenAPI generation succeeds.

- [x] DEV-0802: Add CRM operational documentation.

As an operator, I want CRM/KYC behavior documented so support staff and reviewers understand boundaries before accounts and loans exist.

Acceptance criteria:

- [x] Documentation explains client KYC states and allowed transitions.
- [x] Documentation explains document ownership, private-file handling, and no-download default.
- [x] Documentation explains restricted PII access and audit expectations.
- [x] Documentation explicitly says Module 2 does not open accounts, approve loans, post ledger entries, or move cash.
- [x] Documentation lists unresolved business questions and whether they block implementation.

## Not In Module 2 Safe Slice

- [x] Customer account opening, account numbers, account balances, and account closure belong to Module 3.
- [x] Chart of accounts, journal entries, posting, reversals, and balance projections belong to Module 3.
- [x] Loan product setup, loan application workflow, collateral valuation, schedules, disbursement, repayment, penalties, arrears, and portfolio transfers belong to Module 4.
- [x] Tills, teller sessions, deposits, withdrawals, receipts, denominations, and cash reconciliation belong to Module 5.
- [x] End-of-day jobs that compute balances, penalties, interest, reconciliation differences, reports, or portfolio metrics belong to their owning modules after formula policy approval.
- [x] Rounding, precision, interest, fees, tax, insurance, repayment allocation, installment, penalty, available-balance, till-difference, and reporting calculations are handled by the formula policy docs and owning module completion backlogs.
- [x] Public or temporary KYC file download endpoints are not included unless separately approved by security review.

## Open Questions Before Implementation

- [x] Confirm the exact KYC status vocabulary to use with existing `clients.kyc_status` values.
- [x] Confirm whether identity document numbers must be encrypted at rest before production.
- [x] Confirm whether KYC verification requires maker-checker segregation.
- [x] Confirm whether duplicate identity documents are globally unique or unique by document type plus owner category.
- [x] Confirm whether collection amount is required for all clients or only clients enrolled in field collection.
- [x] Confirm whether standalone guarantors should become independent `guarantors` records later, or remain client-scoped until loans are implemented.
- [x] Confirm whether proxies should be client-only in Module 2 or prepared for account-specific mandates after Module 3.
