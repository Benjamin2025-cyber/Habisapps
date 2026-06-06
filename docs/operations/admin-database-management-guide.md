# Admin Database Management Guide

## Audience

This guide is for:

- Platform administrators who need to create, verify, download, delete, or restore database backups.
- Frontend engineers building the admin database-management screen.
- Backend operators deploying the database-management feature in local, staging, or production environments.

This feature is not a database browser and it is not a general SQL console. It is a controlled operations interface for backup, restore, retention, and storage-health visibility.

## What It Does

The admin database-management module provides authenticated API endpoints for:

- Checking backup storage status.
- Creating a database backup.
- Listing backup history.
- Viewing safe backup metadata.
- Downloading completed backups.
- Verifying backup artifacts with checksums.
- Deleting backup artifacts through retention-safe workflows.
- Running retention cleanup.
- Planning a restore before execution.
- Executing a planned restore with password step-up.
- Cancelling planned or pending restores.
- Viewing restore history.

The implementation is intentionally conservative because this is a microfinance system. Database artifacts can contain client PII, accounting records, loans, cash operations, audit data, and institution configuration.

## Security Model

All routes are under:

```text
/api/v1/database/*
```

All routes require Sanctum authentication.

The database-management permissions are protected platform permissions:

```text
system.database.view
system.database.backup.create
system.database.backup.download
system.database.backup.delete
system.database.restore.plan
system.database.restore.execute
system.database.maintenance.manage
```

These permissions belong to `platform-admin` and are treated as non-delegable institution-control permissions. Do not expose them to normal branch, teller, loan, CRM, or accounting roles.

The API never exposes raw storage paths, shell commands, DSNs, passwords, secrets, tokens, keys, stdout, or stderr in public resources.

## Setup

### 1. Enable The Feature

In local and testing, the feature is enabled by default. Outside local/testing, enable it explicitly:

```env
DATABASE_MANAGEMENT_ENABLED=true
```

Restore is separately guarded:

```env
DATABASE_RESTORE_ENABLED=true
```

Production same-database replacement is disabled unless explicitly allowed:

```env
DATABASE_RESTORE_ALLOW_SAME_DB_IN_PRODUCTION=false
```

Only set `DATABASE_RESTORE_ALLOW_SAME_DB_IN_PRODUCTION=true` when the operator process, backup storage, restore drill procedure, and approval workflow are already proven.

### 2. Configure The Database Connection

The current native runner supports PostgreSQL:

```env
DATABASE_MANAGEMENT_CONNECTION=pgsql
```

The configured connection must match the database connection used when the backup was created. A restore plan is rejected if the backup belongs to another connection.

### 3. Configure Private Backup Storage

Backups must be stored on a private filesystem disk:

```env
DATABASE_MANAGEMENT_DISK=local
DATABASE_MANAGEMENT_PATH_PREFIX=database-backups
```

Do not use the Laravel `public` disk. The API rejects public backup disks.

Optional remote/off-host copy:

```env
DATABASE_MANAGEMENT_REMOTE_DISK=s3
DATABASE_MANAGEMENT_ENCRYPTION_ENABLED=true
DATABASE_MANAGEMENT_ENCRYPTION_KEY=base64:...
```

Remote disks must also be private. When remote encryption is enabled, the remote copy is encrypted before upload. The API does not expose the encryption key.

### 4. Install PostgreSQL Native Tools

The worker/container that runs backups and restores must have PostgreSQL tools available:

```env
DATABASE_MANAGEMENT_PG_DUMP=pg_dump
DATABASE_MANAGEMENT_PG_RESTORE=pg_restore
DATABASE_MANAGEMENT_PSQL=psql
```

Use absolute paths if the queue worker environment has a restricted `PATH`.

### 5. Configure Queue Behavior

Local/testing defaults to synchronous execution so tests can observe final status immediately.

For real environments, prefer queued execution:

```env
DATABASE_MANAGEMENT_RUN_SYNC=false
```

Make sure queue workers are running before enabling production backup or restore actions.

### 6. Configure Retention

```env
DATABASE_MANAGEMENT_RETENTION_MAX_AGE_DAYS=30
DATABASE_MANAGEMENT_RETENTION_MAX_COUNT=30
DATABASE_MANAGEMENT_RETENTION_MIN_PROTECTED=3
DATABASE_MANAGEMENT_RETENTION_KEEP_LAST_VERIFIED=true
```

Retention preserves protected backups and, by default, keeps the latest verified backup.

### 7. Configure Restore Safety

```env
DATABASE_RESTORE_REQUIRE_PRE_BACKUP=true
DATABASE_RESTORE_REQUIRE_CONFIRMATION_PHRASE=false
DATABASE_RESTORE_CONFIRMATION_PHRASE="RESTORE PRODUCTION DATABASE"
DATABASE_RESTORE_PLAN_TTL_MINUTES=15
DATABASE_MANAGEMENT_LOCK_TTL_MINUTES=30
DATABASE_MANAGEMENT_STRICT_VERIFICATION=false
```

If `DATABASE_MANAGEMENT_STRICT_VERIFICATION=true`, downloads and restore planning require the backup to have passed verification.

## API Workflows

### Storage Status

```http
GET /api/v1/database/storage
```

Required permission:

```text
system.database.view
```

Use this endpoint to render:

- Whether database management is enabled.
- The configured logical disk.
- Whether the disk is private.
- Whether the disk is reachable.
- Available local free space when known.
- Completed backup count.
- Total completed backup bytes.
- Last successful backup.
- Retention policy.
- Restore enabled status.
- Current maintenance lock status.

### Create Backup

```http
POST /api/v1/database/backups
```

Required permission:

```text
system.database.backup.create
```

Payload:

```json
{
  "note": "Before module deployment"
}
```

The note is optional and must not contain secrets.

The response is `202 Accepted`. The backup starts as `pending`, then moves through `running`, then `completed` or `failed`.

Only one backup or restore can be actively scheduled/running for the configured connection. Concurrent attempts return a conflict error.

### List Backups

```http
GET /api/v1/database/backups
```

Required permission:

```text
system.database.view
```

Supported filters:

```text
status
search
date_from
date_to
per_page
page
```

Backup statuses:

```text
pending
running
completed
failed
verified
deleted
```

Use `public_id` for all frontend actions. Do not depend on database IDs or storage paths.

### View Backup

```http
GET /api/v1/database/backups/{backup_public_id}
```

Required permission:

```text
system.database.view
```

The response includes safe metadata only:

```text
public_id
filename
disk
status
database_connection
database_driver
size_bytes
checksum_sha256
encrypted
compression
verification_status
verified_at
started_at
completed_at
expires_at
failure_reason
metadata
is_downloadable
created_at
updated_at
```

### Download Backup

```http
GET /api/v1/database/backups/{backup_public_id}/download
```

Required permission:

```text
system.database.backup.download
```

Downloads are streamed through the authorized API endpoint. The API does not expose local absolute paths.

A backup is downloadable only when:

- Status is `completed` or `verified`.
- The artifact exists.
- The checksum matches.
- The artifact does not exceed the configured max size.
- Strict verification is either disabled or the backup verification passed.

### Verify Backup

```http
POST /api/v1/database/backups/{backup_public_id}/verify
```

Required permission:

```text
system.database.maintenance.manage
```

Verification checks artifact existence and checksum.

If verification passes, a `completed` backup becomes `verified`.

If a previously verified backup fails re-verification, it is demoted back to `completed` and marked with failed verification.

### Delete Backup

```http
DELETE /api/v1/database/backups/{backup_public_id}
```

Required permission:

```text
system.database.backup.delete
```

Deletion removes the artifact and tombstones the backup metadata as `deleted`. Running backups cannot be deleted.

### Run Retention

```http
POST /api/v1/database/backups/retention/run
```

Required permission:

```text
system.database.maintenance.manage
```

Dry run:

```json
{
  "dry_run": true
}
```

Execution:

```json
{
  "dry_run": false
}
```

Run dry-run first in the frontend and show the candidate count, candidate public IDs, and reclaimable bytes before allowing execution.

## Restore Workflow

Restore is two-step by design: plan first, execute second.

### Plan Restore

```http
POST /api/v1/database/restores/plan
```

Required permission:

```text
system.database.restore.plan
```

Payload:

```json
{
  "backup_public_id": "01...",
  "target": "same_database",
  "mode": "dry_run"
}
```

Supported targets:

```text
same_database
staging_database
external_database
```

Supported modes:

```text
dry_run
verify_only
replace
```

Current destructive replacement support is intentionally limited:

```text
mode=replace is supported only with target=same_database
```

Replacement to `staging_database` or `external_database` is rejected until explicit target database configuration exists.

If confirmation phrase enforcement is enabled, include:

```json
{
  "confirmation_phrase": "RESTORE PRODUCTION DATABASE"
}
```

Planning validates:

- Database-management feature is enabled.
- Restore feature is enabled.
- Backup exists.
- Backup is completed or verified.
- Backup driver is supported.
- Backup connection matches the configured connection.
- Backup artifact exists.
- Backup checksum matches.
- Backup size is within the configured limit.
- Strict verification requirements, if enabled.
- Same-database production restore policy.

The plan response includes an `execution_token`, which is the restore operation public ID. Plans expire after `DATABASE_RESTORE_PLAN_TTL_MINUTES`.

### Execute Restore

```http
POST /api/v1/database/restores/{restore_operation_public_id}/execute
```

Required permission:

```text
system.database.restore.execute
```

Payload:

```json
{
  "password": "operator-current-password"
}
```

Execution requires password step-up. If the password is wrong, the restore remains planned and does not execute.

For destructive same-database replacement:

- The runner takes a maintenance lock.
- A pre-restore backup is created when configured.
- Registration/day lifecycle writes are blocked while the restore lock is active.
- The runner restores using PostgreSQL native tooling.
- The lock is released when the job finishes or fails.

Dry-run and verify-only modes do not replace the database. They validate the PostgreSQL archive list through `pg_restore --list`.

### List Restores

```http
GET /api/v1/database/restores
```

Required permission:

```text
system.database.view
```

Supported query values:

```text
status
per_page
page
```

Restore statuses:

```text
planned
pending
running
completed
failed
cancelled
```

### View Restore

```http
GET /api/v1/database/restores/{restore_operation_public_id}
```

Required permission:

```text
system.database.view
```

The response links backups by public IDs and checksums. It does not expose paths or commands.

### Cancel Restore

```http
POST /api/v1/database/restores/{restore_operation_public_id}/cancel
```

Required permission:

```text
system.database.restore.plan
```

Only `planned` and `pending` restores can be cancelled. Running restores cannot be cancelled through this endpoint.

## Frontend Integration Notes

Frontend screens should:

- Use only `public_id` values for actions.
- Show storage status before enabling backup or restore buttons.
- Show disabled/misconfigured states as operator-facing warnings, not generic failures.
- Poll backup and restore detail endpoints after `202 Accepted`.
- Require a retention dry-run before retention execution.
- Require explicit confirmation UI before destructive restore planning.
- Require password step-up only on restore execution.
- Display the current maintenance lock from storage status.
- Hide download actions unless `is_downloadable=true`.
- Never ask users to enter shell commands, storage paths, DSNs, R2/S3 credentials, or encryption keys in the browser.

Recommended frontend sections:

- Storage health card.
- Latest successful backup card.
- Backup inventory table.
- Backup detail drawer.
- Restore planning form.
- Restore operation history.
- Retention dry-run and cleanup panel.
- Maintenance lock warning banner.

## Operator Runbook

### Create And Verify A Backup

1. Open the admin database-management screen.
2. Check storage status.
3. Create a backup with a non-secret note.
4. Wait for status `completed`.
5. Run verification.
6. Confirm status is `verified` or verification status is passed.
7. Download only if operationally required.

### Dry-Run A Restore

1. Select a completed or verified backup.
2. Create a restore plan with `mode=dry_run`.
3. Execute the plan with password step-up.
4. Confirm the operation completes.
5. Review audit events and notifications.

### Execute Same-Database Replacement

1. Confirm the selected backup is verified.
2. Confirm the current environment is the intended target.
3. Confirm queue workers are healthy.
4. Confirm pre-restore backup is enabled.
5. Plan restore with `target=same_database` and `mode=replace`.
6. Execute with password step-up.
7. Monitor the restore operation until terminal status.
8. Confirm the maintenance lock has cleared.
9. Run application smoke tests.
10. Review audit events and platform notifications.

## Common Error Codes

```text
database_management_disabled
database_restore_disabled
database_driver_unsupported
backup_disk_missing
backup_disk_not_private
backup_remote_disk_missing
backup_remote_disk_not_private
backup_encryption_key_missing
backup_tool_missing
restore_tool_missing
backup_not_found
backup_not_restorable
backup_driver_unsupported
backup_connection_mismatch
backup_encryption_unsupported
backup_verification_required
backup_artifact_missing
backup_checksum_mismatch
backup_too_large
backup_not_downloadable
restore_not_planned
restore_plan_expired
restore_not_cancellable
restore_target_unsupported
same_database_restore_disabled
database_operation_active
database_operation_scheduling_locked
```

## Testing

Use backend tests for this feature:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/AdminDatabaseManagementTest.php
php artisan test --parallel --recreate-databases tests/Unit/Application/DatabaseManagement
php artisan test --parallel --recreate-databases tests/Feature/Api/Module1AdministrationTest.php --filter "protected_permission_delegation|permission_policy"
php artisan test --parallel --recreate-databases tests/Feature/Api/AccountingDayRouteClassificationTest.php
vendor/bin/phpstan analyse
vendor/bin/pint --test
```

Do not run multiple `--parallel --recreate-databases` commands at the same time. They recreate test databases and can interfere with each other.

## Current Limitations

- PostgreSQL is the only supported native backup/restore driver.
- This is not a SQL execution feature.
- This is not a database table browser.
- Restore replacement currently supports only `same_database`.
- `staging_database` and `external_database` replacement require explicit target database configuration before they should be enabled.
- Downloads are streamed through the authorized API endpoint.
- Native PostgreSQL tools must exist in the runtime environment that executes the jobs.
- Public disks are rejected for database artifacts.
- Remote backup copies can be configured, but remote storage must remain private.

