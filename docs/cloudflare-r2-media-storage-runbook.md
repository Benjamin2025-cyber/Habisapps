# Cloudflare R2 Media Storage — Operator Runbook

This runbook explains how to enable Cloudflare R2 for regulated document media
safely, validate it before uploads, migrate existing local media, and roll back.

It implements the policy in `backlogs/cloudflare-r2-media-storage-backlog.md`.

## What this feature does

- Media uploads (KYC, identity, signatures, regulatory/insurance/Islamic-finance
  evidence, profile photos) are stored on a disk chosen by
  `App\Support\Media\MediaStorageDiskResolver`.
- When R2 is enabled and fully configured, **new** uploads go to R2. Otherwise
  they stay on the private `local` disk. Enabling R2 never rewrites existing
  records — each `documents` row and Spatie `media` row keeps its own `disk`.
- Originals are stored verbatim. No conversions, responsive images, optimization,
  recompression, or metadata stripping run for regulated documents.
- Downloads and profile-photo thumbnails read from whichever disk the media row
  records, so local-backed and R2-backed media both serve correctly.

## Required Cloudflare R2 bucket settings

- Create a bucket dedicated to regulated media (e.g. `habis-media`).
- Keep the bucket **private**. Do not enable public access or an `r2.dev` public
  URL for this bucket. Sensitive documents are served only through authorized
  backend endpoints.
- Create an R2 API token (Access Key ID + Secret Access Key) scoped to this
  bucket with read/write (Object Read & Write) permissions.
- Note your Cloudflare **Account ID**. The S3 endpoint is
  `https://<ACCOUNT_ID>.r2.cloudflarestorage.com` and the region is the literal
  `auto`.

## Required environment variables

Minimal `.env` (no real secrets shown):

```dotenv
# Enable R2-backed media
R2_ENABLED=true
R2_ACCOUNT_ID=your-account-id
R2_ACCESS_KEY_ID=your-access-key-id
R2_SECRET_ACCESS_KEY=your-secret-access-key
R2_BUCKET=habis-media
R2_REGION=auto
# R2_ENDPOINT is derived from R2_ACCOUNT_ID when left blank.
R2_ENDPOINT=
# Optional custom/base URL; NOT required for private files.
R2_URL=
# Verify the correct value against R2/Flysystem before production rollout.
R2_USE_PATH_STYLE_ENDPOINT=true

# Storage policy
MEDIA_DISK_AUTO=true
# fail_closed | fallback_local
MEDIA_R2_FALLBACK_MODE=fail_closed
MEDIA_R2_HEALTH_TIMEOUT_SECONDS=5
```

Notes:

- Partial configuration (enabled but missing credentials, bucket, or
  `R2_ACCOUNT_ID`/`R2_ENDPOINT`) is treated as invalid: the app keeps using
  `local` and the status endpoint reports `r2_partial_config = true`.
- `MEDIA_DISK_AUTO=false` switches to explicit `MEDIA_DISK` selection, which is
  validated against the allowed sensitive-media disks (`local`, `r2`). The
  `public` disk is never permitted for documents.
- After changing env in production, run `php artisan config:cache` so the new
  values are loaded. All R2 settings are read through `config()`, so they work
  under cached config.

## How to validate R2 before enabling uploads

1. Set the env values above with `MEDIA_R2_FALLBACK_MODE=fail_closed`.
2. Check readiness (platform admin or `system.media-storage.view`):

   ```
   GET /api/v1/media-storage/status
   ```

   Expect:
   - `active_disk = "r2"`
   - `r2_enabled = true`, `r2_configured = true`, `r2_healthy = true`
   - `r2_partial_config = false`

   If `r2_healthy = false`, `failure_reason` explains why (config/connectivity).
   The response never contains secrets, bucket names, endpoints, or object keys.

3. The status probe performs a non-mutating existence check against R2; it does
   not create or modify objects.

## How to run a dry-run migration of existing local media

CLI:

```
php artisan media:migrate-to-r2 --dry-run
```

API (platform admin / `system.media-storage.migrate`):

```
POST /api/v1/media-storage/migrations
{ "dry_run": true }
```

A dry-run reports `total_candidates` and `total_bytes` and records a tracked
operation, without copying anything or changing any record. Only media on the
disks in `security.documents.backfill.allowed_source_disks`
(`DOCUMENT_BACKFILL_ALLOWED_SOURCE_DISKS`, default `local`) are candidates.

## How to run and verify a real migration

```
php artisan media:migrate-to-r2
```

or `POST /api/v1/media-storage/migrations { "dry_run": false }` (requires R2 to be
fully configured).

For each candidate the command:

1. Reads the source bytes, copies them verbatim to R2 at the same object key.
2. Recomputes the SHA-256 of the copied object and compares it to the source.
3. Only on an exact match updates the Spatie `media.disk` and the owning
   `documents.disk` to `r2`. The relative key is unchanged.
4. Leaves the local source file in place (no deletion).

Verify:

- `GET /api/v1/media-storage/migrations` lists operations with counts.
- `GET /api/v1/media-storage/migrations/{public_id}` shows `migrated_count`,
  `failed_count`, and bounded per-item failures (document public id + reason
  only; never raw paths).
- Spot-check that previously local documents still download
  (`GET /api/v1/documents/{document}/file`).

Migration is idempotent: media already on `r2` is not a candidate, so re-running
is safe.

## How to handle failed migration items

- A failed item leaves all source metadata unchanged; the bad target copy (if
  any) is removed during verification.
- Failed operations create an internal platform admin notification
  (category `media_storage`).
- Inspect the operation detail for the bounded failure list, fix the underlying
  cause (e.g. missing source object, R2 connectivity), and re-run the migration
  — already-migrated items are skipped automatically.

## How to roll back to local uploads

- Set `R2_ENABLED=false` (or `MEDIA_DISK_AUTO=false` with `MEDIA_DISK=local`) and
  re-cache config. New uploads return to the `local` disk immediately.
- Existing R2-backed documents continue to serve from R2 (their `media.disk`
  still says `r2`), so do not delete the bucket while R2-backed media exists.
- Because migration never deletes local source files, documents migrated to R2
  still have their original local bytes for recovery.

## How to confirm no derivatives are generated for regulated documents

- `Document::registerMediaConversions()` is intentionally empty and
  `kyc_documents` is a single-file collection.
- Uploading a JPEG produces exactly one `media` row with empty
  `generated_conversions` and empty `responsive_images`.
- Profile-photo thumbnails are rendered on demand per request and are never
  stored as media conversions.
- Automated guards: `tests/Feature/Api/MediaStorageR2Test.php`
  (`test_image_upload_creates_single_media_with_no_conversions`,
  `test_profile_photo_thumbnail_renders_from_r2_without_storing_derivatives`,
  `test_document_does_not_register_conversions_or_responsive_images`).

## Expected test commands and checks

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/MediaStorageR2Test.php
php artisan test --parallel --recreate-databases tests/Feature/Console/MediaMigrationToR2Test.php
php artisan test tests/Unit/Support/Media/MediaStorageDiskResolverTest.php

vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan scramble:export
```

Health/endpoint checks:

- `GET /api/v1/media-storage/status` — readiness and active disk.
- `GET /api/v1/media-storage/migrations` — migration history.
