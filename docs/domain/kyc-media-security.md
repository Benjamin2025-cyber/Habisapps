# KYC Media Security Policy

This policy defines the current baseline for KYC document media handling.

## Scope

- Applies to KYC documents stored through `App\Models\Document`.
- Applies to media in the `kyc_documents` collection.

## Storage And Exposure

- KYC media uses the private `local` disk by default (`storage/app/private`).
- Public URLs for KYC media are not exposed by API resources.
- Internal storage paths are never returned in API payloads.

## Conversions And Responsive Images

- KYC document media conversions are intentionally disabled in `Document::registerMediaConversions()`.
- Responsive image generation is not enabled for KYC upload flows.
- Any future KYC conversion use case requires explicit security review and approval before implementation.

## Temporary URL Policy

- No KYC media download endpoint is currently exposed.
- If a download endpoint is introduced later:
  - It must authorize through the `Document` domain policy and agency scope checks.
  - It must use short-lived temporary access (default max 5 minutes unless approved otherwise).
  - It must be audit logged with actor, agency, and document public ID.
  - It must never bypass authorization by direct media ID access.

## Upload Guardrails

- Allowed upload types for KYC API: `pdf`, `jpg`, `jpeg`, `png`.
- Maximum upload size for KYC API: 10 MB.
- File checksum (`sha256`) is computed and stored for integrity tracking.
- Uploaded filenames are sanitized before persistence to block path traversal-style names (`../`, `..\`, or unsafe characters).

## Idempotency Limitations

- The global `IdempotencyMiddleware` normalizes `UploadedFile` objects in request fingerprints for consistency.
- However, idempotency replay for file upload endpoints has a known limitation: the temporary uploaded file is consumed during the first request and is no longer available when the middleware attempts to replay a cached response.
- Clients should handle network retries for file uploads at the application level rather than relying on idempotency replay for upload endpoints.
- Non-file mutation endpoints (e.g., archive, status changes) fully support idempotency replay through the `Idempotency-Key` header.

## Storage Column Cutover Strategy

The `documents` table retains `disk` and `path` columns as a transitional mirror of Media Library metadata. This strategy provides a safety net during the Media Library adoption phase.

### Current Behavior

- During document upload, Media Library stores the file and generates metadata.
- The `disk`, `path`, `original_name`, `mime_type`, and `size_bytes` columns on the `documents` table are updated to mirror the media metadata.
- These columns serve as a read-only cache for quick access without joining the `media` table.
- The authoritative source of truth for file location is the Media Library `media` table.

### Future Deprecation Plan

- The `disk` and `path` columns on the `documents` table are deprecated for direct file access.
- After a stabilization period (minimum 6 months post-backfill), a migration will be created to drop these columns.
- The migration will:
  1. Verify all documents have associated media records in the `kyc_documents` collection.
  2. Drop the `disk`, `path`, `original_name`, `mime_type`, and `size_bytes` columns from `documents`.
  3. Update all queries and API resources to read from the media relationship instead.
- A rollback plan will be provided to restore the columns if needed (though this would require re-running the backfill).

### Backfill Transition

- Existing documents with `disk` and `path` values are migrated to Media Library via the `app:backfill-document-media` command.
- Backfill uses `preservingOriginal()` and therefore keeps legacy files at their original path while copying into Media Library-managed storage.
- The backfill command updates the `disk` and `path` columns to point to the new Media Library storage paths.
- Each attached media row is tagged with a `backfill_batch_id` custom property for traceability and rollback targeting.

### Backfill Safety Measures

The backfill command includes the following security safeguards:

- **Path masking**: Document public IDs are masked in logs and error messages (e.g., `01ab****cd23`).
- **Storage root validation**: For `local` disk sources, the command refuses to attach files outside the configured `local` disk root.
- **Disk restriction**: Source disks are allow-listed by `security.documents.backfill.allowed_source_disks`; disallowed disks are skipped.
- **Checksum verification**: File checksums are computed and compared against stored values. Mismatches are reported and the file is not attached.
- **Dry-run mode**: Operators can run `php artisan app:backfill-document-media --dry-run` to preview changes without modifying data.
- **Idempotency**: The command only processes documents without media in the `kyc_documents` collection, making it safe to re-run.
- **Error isolation**: Failed rows are reported but do not block unrelated valid rows unless `--strict` mode is enabled.

### Rollback Behavior

If a backfill operation needs to be rolled back:

1. **Media rollback**: Delete media records created during the backfill by identifying them via `backfill_batch_id` (preferred) or controlled execution window.
2. **Document state**: The `documents` table columns (`disk`, `path`, etc.) are updated during backfill to point to new Media Library paths. To rollback, restore these columns from a database backup taken before the backfill.
3. **File restoration**: Legacy files remain at their old paths by default because backfill copies rather than moves source files.

**Recommended rollback procedure**:
- Take a database backup before running the backfill.
- Run the backfill with `--dry-run` first to preview changes.
- If rollback is needed, restore the database from backup and delete any media records created during the backfill window.

## Operational Controls

### Private Disk Configuration

- **Development**: Uses `local` disk with root at `storage/app/private`. Files are not publicly accessible.
- **Testing**: Uses `local` disk with root at `storage/framework/testing/disks/local`. Files are isolated between test runs.
- **Production**: Should use `local` disk with root at `storage/app/private` or an S3-compatible private bucket. Configuration must be set via `MEDIA_DISK` environment variable.

### Backup and Restore Expectations

- **Database backups**: Must include the `documents` table and `media` table to maintain referential integrity.
- **File backups**: Private storage (`storage/app/private` or S3 bucket) must be backed up separately from the database.
- **Restore process**: Restore database first, then ensure file storage is available. For current backfill behavior, legacy files are expected to remain unless separately cleaned.
- **Backfill safety**: The backfill command preserves original files until a separate cleanup process removes them.

### Retention, Archive, and Deletion Expectations

- **Retention period**: Not hard-coded in the application. Retention policy should be defined by stakeholders and implemented via scheduled jobs or manual processes.
- **Archive behavior**: Archiving a document sets `status` to `archived` and records `archived_at` timestamp. The underlying file is not deleted.
- **Deletion**: File deletion is not currently exposed via API. If implemented, it should:
  - Require explicit privileged permission.
  - Be audit logged with actor and document details.
  - Optionally soft-delete files first before permanent deletion.
- **Cleanup of archived files**: Should be implemented as a separate scheduled job with configurable retention periods.

### Malware Scanning

Malware scanning is not currently implemented. When implemented, it should be designed as a plug-in point:

- Scan files during upload before attaching to Media Library.
- Scan files during backfill migration.
- Support integration with external scanning services (e.g., ClamAV, VirusTotal API).
- Quarantine suspicious files with manual review workflow.
- Log scan results with document reference for audit trail.

### Incident Response Expectations

In the event of exposed or corrupted KYC media:

1. **Immediate containment**:
   - Disable document upload endpoints if exposure is ongoing.
   - Revoke or rotate any compromised access tokens.
   - If files were exposed publicly, assess the scope of exposure.

2. **Investigation**:
   - Review audit logs to identify affected documents and access patterns.
   - Identify which documents may have been accessed by unauthorized parties.
   - Determine if files were modified or corrupted.

3. **Remediation**:
   - Notify affected stakeholders and regulators as required by policy.
   - Invalidate or re-upload affected documents if corruption is suspected.
   - Implement additional security controls to prevent recurrence.

4. **Post-incident**:
   - Conduct a root cause analysis.
   - Update security policies and procedures based on findings.
   - Document lessons learned and improve monitoring.
