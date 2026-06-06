# Cloudflare R2 Media Storage Backlog

Investigation date: 2026-06-06.

External documentation checked: 2026-06-06.

References:

- Cloudflare R2 S3 API compatibility: `https://developers.cloudflare.com/r2/api/s3/api/`
- Cloudflare R2 S3 getting started: `https://developers.cloudflare.com/r2/get-started/s3/`
- Laravel 13 filesystem documentation: `https://laravel.com/docs/13.x/filesystem`

## Context

The API currently stores uploaded media through Spatie Media Library. `Document::registerMediaCollections()` uses `config('media-library.disk_name', 'local')`, and `config/media-library.php` resolves that value from `MEDIA_DISK`, defaulting to `local`.

The frontend needs the system to be adaptive: when Cloudflare R2 is configured, uploaded media should use R2; when R2 is not configured, the current local/private disk behavior should continue to work.

This matters because documents include KYC evidence, profile photos, signatures, regulatory evidence, insurance evidence, Islamic finance evidence, and other sensitive files. R2 integration must preserve authorization, privacy, file integrity, agency scoping, auditability, and existing document APIs.

This is a microfinance application, not an ecommerce catalog. Media handling must preserve evidentiary files, not optimize them into product-style thumbnails, responsive variants, or compressed derivatives by default.

## Current-State Evidence

- `config/filesystems.php` defines `local`, `public`, and generic `s3` disks only. There is no dedicated `r2` disk.
- `config/media-library.php` sets `disk_name` to `env('MEDIA_DISK', 'local')`.
- `Document` implements Spatie `HasMedia` and stores files in the `kyc_documents` media collection.
- `Document::registerMediaCollections()` calls `useDisk($diskName)`, so the selected media disk is already configurable.
- `DocumentController::store()` uploads through `addMediaFromRequest('file')->toMediaCollection('kyc_documents')`.
- `DocumentController::download()` streams files through `$media->toInlineResponse($request)`.
- `ClientProfilePhotoController` reads profile-photo media via `$media->stream()` and renders a thumbnail locally before responding.
- `DocumentResource` intentionally hides `disk` and `path` from API responses.
- Feature tests currently fake the `local` disk and assert files exist on local storage.
- `config/security.php` has document backfill allowed-source-disk config, currently defaulting to `local`.
- `Document::registerMediaConversions()` is empty and explicitly comments that KYC documents do not generate conversions or responsive images.
- `config/media-library.php` still contains global conversion jobs, image generators, image optimizers, and responsive-image configuration. Those are available framework defaults, but they should not become active for regulated documents without a deliberate reviewed change.
- The `media` table stores Spatie metadata columns such as `generated_conversions` and `responsive_images`; for regulated documents these should remain empty/false unless a future approved media class intentionally opts in.

## Scope

Implement adaptive media storage so R2 is used automatically when correctly configured, without breaking local development, tests, or existing local media.

In scope:

- Dedicated Cloudflare R2 filesystem disk.
- Media disk resolver that chooses R2 when configured and healthy, otherwise local/private disk.
- Uploads, downloads, profile-photo thumbnails, and Spatie Media Library compatibility.
- Health/readiness visibility for media storage.
- Existing local media compatibility.
- Optional migration/backfill from local disk to R2.
- Audit events for storage changes and migrations.
- Documentation and test coverage.

Out of scope:

- Public unauthenticated access to KYC documents.
- Exposing raw R2 object keys, bucket names, or signed URLs in normal document metadata responses.
- Making the `public` disk the fallback for sensitive documents.
- Frontend direct-to-R2 uploads until backend-mediated upload is stable.
- Deleting local originals immediately after first migration without verification.

## Storage Policy

Default behavior:

- If R2 is fully configured and enabled, new media uploads use the R2 disk.
- If R2 is disabled or not fully configured, new media uploads use the existing private `local` disk.
- If R2 is configured but unhealthy, behavior must follow config:
  - `fail_closed`: upload returns a clear 503/422.
  - `fallback_local`: upload stores on local and records an audit/storage warning.

Required privacy model:

- R2 bucket must be private by default.
- Document APIs must continue to enforce authorization before serving file bytes.
- Normal document API responses must not expose object paths, disk names, raw bucket names, endpoints, or credentials.
- Download and thumbnail endpoints must work with local and R2-backed media.

Required evidentiary-media model:

- Uploaded KYC, identity, signature, regulatory, insurance, loan, and Islamic-finance evidence files must be stored as originals.
- No automatic optimization, recompression, responsive image generation, derivative thumbnails, AVIF/WebP conversion, or metadata-stripping should run for regulated document uploads.
- Any preview/thumbnail response must be generated on demand for display only unless a future reviewed feature explicitly creates a separate non-evidence derivative.
- Checksums must be based on the original uploaded file, not an optimized derivative.
- The stored original must remain retrievable for audit, verification, and legal evidence.

## R2-001: Filesystem Disk And Configuration

Add a dedicated R2 filesystem configuration.

Required config:

- `R2_ENABLED`
- `R2_ACCOUNT_ID`
- `R2_ACCESS_KEY_ID`
- `R2_SECRET_ACCESS_KEY`
- `R2_BUCKET`
- `R2_REGION`, default `auto`
- `R2_ENDPOINT`, derived from account id when not supplied
- `R2_URL`, optional custom public/base URL but not required for private files
- `R2_USE_PATH_STYLE_ENDPOINT`, configurable; verify the correct value with Laravel/Flysystem against R2 before production rollout
- `MEDIA_DISK_AUTO`, default true
- `MEDIA_R2_FALLBACK_MODE=fail_closed|fallback_local`
- `MEDIA_R2_HEALTH_TIMEOUT_SECONDS`

Acceptance criteria:

- `config/filesystems.php` contains an `r2` disk using Laravel's S3-compatible driver.
- The R2 disk uses Cloudflare's documented S3 endpoint shape: `https://<ACCOUNT_ID>.r2.cloudflarestorage.com`.
- The R2 disk uses Cloudflare's documented S3 region value: `auto`.
- The app can determine whether R2 is configured without attempting an upload.
- Partial R2 config is treated as invalid and does not silently use malformed S3 settings.
- Local/test environments continue to use `local` unless R2 is explicitly enabled.
- Tests cover complete config, missing bucket, missing credentials, disabled R2, and fallback mode.
- A real-environment smoke test or documented operator check verifies the configured path-style behavior against Cloudflare R2.

## R2-002: Adaptive Media Disk Resolver

Create a central resolver for media storage disk selection.

Suggested service:

- `App\Support\Media\MediaStorageDiskResolver`

Required behavior:

- Returns `r2` when R2 is enabled, fully configured, and selected by policy.
- Returns `local` when R2 is disabled or auto mode is off and `MEDIA_DISK=local`.
- Returns configured explicit disk when an operator intentionally sets `MEDIA_DISK`, but validates it against allowed sensitive-media disks.
- Exposes a reason/status for diagnostics.

Acceptance criteria:

- `Document::registerMediaCollections()` uses the resolver or a resolved config value rather than duplicating env logic.
- Invalid explicit media disk returns clear configuration failure instead of storing on an unintended disk.
- Sensitive documents cannot be stored on the `public` disk.
- Tests prove new uploads choose `r2` under valid R2 config and `local` otherwise.
- Tests prove explicit `MEDIA_DISK=local` remains supported.

## R2-003: Upload Compatibility For Documents

Update document upload behavior to work across local and R2 disks.

Required behavior:

- `POST /api/v1/documents` stores new files on the resolved media disk.
- `documents.disk` and Spatie media `disk` both reflect the actual disk used.
- `documents.path` stores the relative object path/key, not an absolute URL.
- `checksum_sha256`, `mime_type`, `size_bytes`, and sanitized original name remain correct.
- Upload errors from R2 are translated to structured API errors.

Acceptance criteria:

- Feature test proves local upload still works with `Storage::fake('local')`.
- Feature test proves R2 upload works with `Storage::fake('r2')` and stores `disk=r2`.
- Feature test proves API response still omits `disk` and `path`.
- Feature test proves failed remote storage does not leave an active document row without media.
- Audit event `document.created` still records the upload without leaking object keys.

## R2-004: Download And Inline Preview Compatibility

Ensure document serving works for both local and R2 media.

Required endpoints:

- Existing `GET /api/v1/documents/{document}/file`
- Existing profile-photo thumbnail route

Required behavior:

- Authorized users can stream local-backed and R2-backed documents.
- Archived, missing, or unauthorized documents remain denied/not found under current policy.
- The response preserves safe headers: content type, inline disposition, no sniffing.
- R2 object keys and signed storage URLs are not exposed unless a dedicated signed URL mode is explicitly added.

Acceptance criteria:

- Tests cover streaming local document media.
- Tests cover streaming R2 document media with `Storage::fake('r2')`.
- Tests cover profile-photo thumbnail rendering from R2-backed media.
- Cross-agency authorization tests pass for R2-backed documents.
- Missing object on R2 returns a controlled not-found response, not a 500.

## R2-005: Regulated Media Processing Policy

Make the "no ecommerce-style media optimization" rule explicit and testable.

Required behavior:

- `Document` media collections must not generate conversions.
- `Document` media collections must not generate responsive images.
- Uploading an image document must produce exactly one Spatie media record for the original file.
- The media row's `generated_conversions` remains empty.
- The media row's `responsive_images` remains empty.
- Global Spatie image optimizers and conversion generators must not run for regulated document uploads.
- Profile-photo thumbnails remain request-time render responses and are not stored as media conversions.

Acceptance criteria:

- Feature test uploads a JPEG KYC/profile-photo document and asserts one media row, empty `generated_conversions`, empty `responsive_images`, and no conversion files on disk/R2.
- Feature test uploads a PDF document and asserts no preview image or thumbnail media is created.
- Feature test proves `checksum_sha256` equals the original upload checksum.
- Feature test proves profile-photo thumbnail endpoint does not create a new media row or stored conversion file.
- Static/code review check ensures no `addMediaConversion()` or `withResponsiveImages()` is added to `Document`.
- If a future non-evidence media domain needs optimization, it must use a separate model/collection and cannot reuse `kyc_documents`.

## R2-006: Media Storage Health Endpoint

Expose media storage readiness for platform/admin diagnostics.

Required endpoint:

- `GET /api/v1/media-storage/status`

Required response:

- active media disk
- R2 enabled/configured/healthy booleans
- fallback mode
- last health-check timestamp
- safe failure reason
- local fallback status

Acceptance criteria:

- Only platform admins or users with a new `system.media-storage.view` permission can call the endpoint.
- Response never exposes secrets, full object keys, endpoint credentials, or bucket access keys.
- Healthy R2 config returns `active_disk=r2`.
- Disabled R2 returns `active_disk=local`.
- Partial config returns a clear invalid-config status.
- Tests cover authorized, unauthorized, healthy, disabled, and partial-config cases.

## R2-007: Existing Media Compatibility

Existing local media must continue to serve after R2 is enabled.

Required behavior:

- `Document` records keep their own stored `disk` and `path`.
- Enabling R2 changes new uploads only; it does not rewrite existing records.
- Download and thumbnail code must use the Spatie media row's disk, not the current default disk.

Acceptance criteria:

- Test creates a local document, then enables R2, then verifies the old local document still downloads.
- Test creates an R2 document after enabling R2 and verifies both documents can be served.
- Document listing remains independent of disk.
- No API response leaks which document is local vs R2 unless an admin diagnostics endpoint intentionally exposes aggregate storage stats.

## R2-008: Local-To-R2 Migration And Backfill

Add a controlled migration path for existing local media.

Required command:

- `php artisan media:migrate-to-r2`

Suggested API endpoint for admin-triggered migration:

- `POST /api/v1/media-storage/migrations`

Required behavior:

- Supports dry-run.
- Copies media from allowed source disks to R2.
- Verifies checksum after copy.
- Preserves original bytes; migration must not optimize, recompress, transform, or strip metadata from the object.
- Updates Spatie media disk/path and document disk/path only after verification.
- Keeps local source file by default until retention cleanup is explicitly run.
- Supports resume/idempotency.

Acceptance criteria:

- Dry-run reports candidate counts and total bytes without modifying records.
- Migration copies one local document to R2 and preserves checksum.
- Migration proves source and target byte checksums match exactly.
- Failed copy leaves source metadata unchanged.
- Re-running migration skips already migrated records.
- Backfill respects `security.documents.backfill.allowed_source_disks`.
- Tests fake both local and R2 disks and do not require real Cloudflare credentials.

## R2-009: Migration Operation Tracking

Track migration jobs and their outcomes.

Suggested table:

- `media_storage_migrations`

Required fields:

- `public_id`
- `source_disk`
- `target_disk`
- `status`
- `dry_run`
- `total_candidates`
- `processed_count`
- `migrated_count`
- `failed_count`
- `total_bytes`
- `started_at`
- `completed_at`
- `requested_by_user_id`
- `failure_summary`
- `metadata`

Acceptance criteria:

- Migration operations are listable by platform admins.
- Migration detail shows counts and bounded failure summaries.
- Failed per-file details avoid exposing raw storage paths to normal responses.
- Tests cover status transitions, dry-run operation records, and failed migration records.

## R2-010: Audit And Notifications

Add audit coverage for storage configuration usage and migrations.

Required audit events:

- `media.storage.r2_selected`
- `media.storage.local_fallback_used`
- `media.storage.health_checked`
- `media.migration.requested`
- `media.migration.started`
- `media.migration.completed`
- `media.migration.failed`
- `media.migration.item_failed`

Acceptance criteria:

- Event labels are added to `SecurityEventCatalog`.
- Upload fallback to local records an audit event when R2 was enabled but unavailable.
- Migration request/start/completion/failure are audited.
- Audit properties use public ids, disk names, counts, and checksums only.
- Audit properties never include credentials, raw endpoint secrets, raw object URLs, or full local absolute paths.
- Failed migration creates an internal admin notification.

## R2-011: Permissions And Role Catalog

Add media-storage administration permissions.

Required permissions:

- `system.media-storage.view`
- `system.media-storage.manage`
- `system.media-storage.migrate`

Permission rules:

- Platform-admin receives all media-storage permissions.
- Media-storage permissions are protected.
- `system.media-storage.manage` and `system.media-storage.migrate` are non-delegable protected permissions.
- Non-platform roles cannot trigger migrations by default.

Acceptance criteria:

- `GET /roles` exposes the new permissions with descriptions.
- Non-platform roles cannot be granted non-delegable media-storage permissions.
- Platform-admin can view media-storage status and request migrations.
- Tests cover role catalog, protected permission enforcement, and endpoint authorization.

## R2-012: API Documentation And Frontend Contract

Regenerate and stabilize API docs for the storage surface.

Required docs:

- R2 environment variables.
- Fallback behavior.
- Status endpoint response.
- Migration request/list/detail responses.
- Error responses for invalid config, disabled R2, unhealthy R2, and missing objects.
- The regulated-media policy: originals are stored, automatic derivatives are disabled, previews are served through authorized backend endpoints.

Acceptance criteria:

- `php artisan scramble:export` includes new endpoints.
- Frontend can render local-only, R2 active, R2 configured but unhealthy, migration running, migration failed, and migration completed states.
- No frontend contract requires secrets or raw storage paths.
- Frontend must not assume ecommerce-style image variants or responsive URLs exist for documents.

## R2-013: Operational Runbook

Create an operator runbook for enabling R2 safely.

Required content:

- Required Cloudflare R2 bucket settings.
- Required environment variables.
- How to validate R2 before enabling uploads.
- How to run dry-run migration.
- How to verify migrated media.
- How to roll back to local uploads.
- How to handle failed migration items.
- How to confirm no stored conversions/responsive variants are being generated for regulated documents.

Acceptance criteria:

- Runbook is committed under `docs/` or `backlogs/` implementation notes.
- Runbook includes a minimal R2 `.env` example without secrets.
- Runbook includes the expected test commands and health endpoint checks.

## Implementation Notes

- Cloudflare R2 is S3-compatible; use Laravel's S3 filesystem driver with an R2-specific disk.
- Keep the bucket private for KYC and financial evidence.
- Prefer streaming through authorized backend endpoints for sensitive documents.
- Avoid direct frontend-to-R2 upload until backend-mediated upload, storage selection, and migration are stable.
- Do not make `r2` the default Laravel filesystem disk globally unless explicitly required; use it only for media/document storage.
- Do not enable Spatie conversions, responsive images, or optimizers for `Document` evidence media.
- Keep tests credential-free with `Storage::fake('r2')`.

## Suggested Test Targets

Focused tests:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/FoundationOperationsTest.php --filter document
php artisan test --parallel --recreate-databases tests/Feature/Api/MediaStorageR2Test.php
php artisan test --parallel --recreate-databases tests/Feature/Console/MediaMigrationToR2Test.php
```

Quality gates:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan scramble:export
```

Test-running notes:

- Put `--parallel` before path arguments.
- Do not require real Cloudflare credentials in automated tests.
- Do not run multiple `php artisan test --parallel --recreate-databases ...` commands concurrently.
