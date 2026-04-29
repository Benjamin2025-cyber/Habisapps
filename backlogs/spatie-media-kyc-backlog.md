# Spatie Media Library KYC Backlog

This backlog covers adopting `spatie/laravel-medialibrary` as the physical file/media layer for KYC documents while keeping the existing `documents` model as the domain record for agency scope, ownership, verification, lifecycle, audit, and API behavior.

Progress convention:

- `[ ]` Not started.
- `[x]` Completed.
- Keep a story unchecked until all its acceptance criteria are checked.

## Guiding Rules

- [ ] Laravel scaffolding must be generated through Laravel/Artisan commands whenever Laravel provides a command for the artifact, then reviewed and adjusted manually as needed.
- [ ] Composer must be used for package installation; package config and migrations must be published through Laravel/vendor publish commands where provided.
- [ ] Spatie Media Library must not replace the KYC domain model blindly; `documents` remains the authoritative business record.
- [ ] Agency isolation, actor authorization, auditability, checksum verification, archive status, and verification status remain first-class domain concerns.
- [ ] Private KYC files must never expose raw storage paths or public disk URLs through API responses.
- [ ] Every migration, backfill command, and file lifecycle operation must be reversible, idempotent where applicable, and tested.

## Epic 1: Package Installation And Baseline Configuration

- [x] DEV-0101: Install and publish Spatie Media Library.

As a developer, I want Spatie Media Library installed through the standard Laravel package workflow so the project uses a maintained media layer instead of hand-rolled file attachment plumbing.

Acceptance criteria:

- [x] `spatie/laravel-medialibrary` is installed with Composer.
- [x] Package config is published through the package-supported Laravel command.
- [x] Package migrations are published through the package-supported Laravel command.
- [x] Generated migrations are reviewed before being committed.
- [x] The `media` table can be migrated in a clean testing database.
- [x] Existing document upload, archive, authorization, and API tests still pass.

- [x] SEC-0101: Review baseline media configuration.

As a security reviewer, I want package defaults reviewed before KYC documents depend on them so private identity files are not exposed accidentally.

Acceptance criteria:

- [x] The default disk for KYC media is private.
- [x] Public URLs are not enabled for KYC media by default.
- [x] Temporary URL behavior is reviewed and documented before any download endpoint is exposed (see `docs/domain/kyc-media-security.md`).
- [x] File size, MIME type, and extension controls are enforced at the application boundary.
- [x] Image conversions and responsive images are disabled for KYC unless a specific reviewed use case exists.
- [x] Configuration does not leak internal storage paths in API responses, logs, or validation errors.

## Epic 2: KYC Domain Integration

- [ ] DEV-0201: Integrate Media Library with the existing `Document` domain model.

As a developer, I want each KYC document domain record to own its media attachment so business rules stay attached to the document lifecycle, not to a generic file row.

Acceptance criteria:

- [ ] `Document` implements the Media Library model contract and uses the package interaction trait.
- [ ] A dedicated collection exists for KYC documents.
- [ ] One active domain `documents` row maps to the intended media item or collection policy.
- [ ] `documents.agency_id`, owner fields, document type, verification status, archive status, checksum, and audit metadata remain authoritative.
- [ ] API resources continue to expose domain document data, not raw package internals.
- [ ] Internal integer media IDs are not exposed unless a reviewed API need exists.

- [ ] DEV-0202: Move document upload storage to Media Library.

As a developer, I want document uploads to use Media Library storage APIs while preserving the existing public API contract and domain validations.

Acceptance criteria:

- [ ] Uploads use the package-supported media attachment API from the validated request file.
- [ ] Stored file name, original file name, MIME type, size, and checksum are recorded consistently.
- [ ] Existing domain validations still reject unsupported file types and oversized files.
- [ ] Existing idempotency behavior for upload endpoints is preserved or explicitly documented if unchanged.
- [ ] Archiving a document changes domain lifecycle state without physically deleting the underlying file by default.
- [ ] Tests prove upload, archive, and retrieval metadata behavior through the API.

- [ ] SEC-0201: Verify media access isolation.

As a security reviewer, I want every KYC media operation bound to the document policy so cross-agency access cannot bypass domain authorization.

Acceptance criteria:

- [ ] Staff from one agency cannot upload to, view, archive, or download another agency's document.
- [ ] Any media download route resolves through `Document` authorization, not directly through a generic media ID.
- [ ] Archived documents cannot be downloaded unless a specific privileged permission allows it.
- [ ] Direct path guessing does not return KYC files.
- [ ] Media creation, archive, and privileged access events are audit logged with actor and agency context.

## Epic 3: Existing Document Migration And Backfill

- [ ] DEV-0301: Create an idempotent backfill command for existing document files.

As a developer, I want existing `documents.disk` and `documents.path` records migrated into Media Library safely so package adoption does not break existing KYC records.

Acceptance criteria:

- [ ] The backfill command is scaffolded with Artisan.
- [ ] The command supports dry-run mode.
- [ ] The command is idempotent and can be re-run without duplicating media rows.
- [ ] Each backfilled row verifies the source file exists before attaching media.
- [ ] Checksum mismatches are detected and reported without silently accepting corrupted files.
- [ ] Failed rows are reported with masked/safe identifiers and do not block unrelated valid rows unless a strict mode is selected.

- [ ] DEV-0302: Define the cutover strategy for old storage columns.

As a developer, I want a clear transition plan so the system does not have two conflicting sources of truth for file location.

Acceptance criteria:

- [ ] The old `documents.disk` and `documents.path` columns are kept, deprecated, or removed according to a documented cutover decision.
- [ ] If kept temporarily, their write behavior is documented and tested.
- [ ] If removed later, a separate migration and rollback plan exists.
- [ ] API behavior remains stable during the transition.

- [ ] SEC-0301: Review migration and backfill safety.

As a security reviewer, I want the backfill process to avoid leaking or corrupting private KYC files during migration.

Acceptance criteria:

- [ ] Logs do not expose raw private paths, national ID values, or sensitive file names.
- [ ] The command refuses to attach files outside the configured private storage roots.
- [ ] Checksum mismatch handling prevents silent data corruption.
- [ ] Rollback behavior is documented before production execution.
- [ ] Operators can run a dry-run report before changing data.

## Epic 4: API Documentation And Test Coverage

- [ ] DEV-0401: Update Scramble/OpenAPI documentation for KYC document media behavior.

As an API consumer, I want accurate document upload and retrieval documentation so clients understand the domain API without depending on storage internals.

Acceptance criteria:

- [ ] Upload request documentation shows the expected multipart field, allowed types, size limit, and domain fields.
- [ ] Response documentation excludes raw storage paths and package internals.
- [ ] Error documentation covers invalid file type, oversized file, archived document, unauthorized agency access, and duplicate/idempotency cases.
- [ ] Any download endpoint documents authorization and temporary URL behavior if implemented.

- [ ] DEV-0402: Add regression tests for Media Library-backed documents.

As a developer, I want tests that lock the KYC document contract so future feature work does not accidentally weaken file handling.

Acceptance criteria:

- [ ] Upload creates both a domain document record and the expected media record.
- [ ] Upload rejects unsupported MIME types and oversized files.
- [ ] Cross-agency access attempts are denied.
- [ ] Archive behavior does not physically delete files unless explicitly designed.
- [ ] API responses do not expose raw private storage paths.
- [ ] Existing `php artisan test` suite passes.

- [ ] SEC-0401: Execute a focused KYC media penetration checklist.

As a security reviewer, I want adversarial checks specific to private document media before the project relies on the implementation.

Acceptance criteria:

- [ ] Path traversal payloads cannot read or write outside allowed storage.
- [ ] Content-type spoofing does not bypass validation.
- [ ] Oversized uploads are rejected consistently.
- [ ] Generic media IDs cannot be used to bypass document authorization.
- [ ] Archived documents are protected according to lifecycle policy.
- [ ] Audit logs capture sensitive media actions without leaking sensitive values.

## Epic 5: Production Readiness Gate

- [ ] DEV-0501: Run full implementation validation.

As a developer, I want package adoption validated end-to-end before feature work depends on it.

Acceptance criteria:

- [ ] `php artisan migrate:fresh --env=testing` succeeds.
- [ ] Migration rollback succeeds for the new package-related migrations.
- [ ] `php artisan test` passes.
- [ ] `vendor/bin/phpstan analyze` passes.
- [ ] Formatting/linting commands used by the project pass.
- [ ] Scramble/OpenAPI generation still works.

- [ ] SEC-0501: Sign off operational KYC media controls.

As a security reviewer, I want production storage and lifecycle controls identified before real KYC files are stored.

Acceptance criteria:

- [ ] Private disk configuration is documented for each environment.
- [ ] Backup and restore expectations for private KYC files are documented.
- [ ] Retention, archive, and deletion expectations are documented without hard-coding stakeholder-dependent policy.
- [ ] Malware scanning is represented as a plug-in point if not implemented immediately.
- [ ] Incident response expectations for exposed or corrupted KYC media are documented.
