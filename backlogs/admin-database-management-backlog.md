# Admin Database Management Backlog

Investigation date: 2026-06-06.

## Context

The frontend needs an administration surface for database operations such as backup, restore, and related maintenance. The current backend does not expose database-management APIs and does not include a configured backup package or `pg_dump`/restore workflow.

This work is high risk because the application stores financial, accounting, client PII, KYC, teller cash, loan, insurance, HR, audit, and role/permission data. Frontend-driven database operations must be treated as privileged system operations, not normal CRUD.

## Current-State Evidence

- `routes/api.php` wires feature route files, but there is no database-management route file.
- `app/Console/Commands` contains backfill and producer commands only; there is no backup, restore, snapshot, or database-maintenance command.
- No configured dependency was found for `spatie/laravel-backup`, `pg_dump`, `pg_restore`, `mysqldump`, or equivalent backup tooling.
- `config/security.php` defines platform-admin as the institution-control role, but it has no `system.database.*` permissions.
- `RoleController::protectedPermissions()` and `RoleController::nonDelegableProtectedPermissions()` already protect institution-control permissions; database-management permissions should follow that model.
- `SecurityAudit` supports stable machine-event audit logging with hashed request metadata.
- `RouteClassification::SYSTEM_MAINTENANCE` already exists and should classify database-management mutations outside accounting-day registration writes.
- The audit event catalog has no curated labels for database backup/restore events.

## Scope

Build backend contracts that allow a platform administrator to manage database backups from the frontend without giving the frontend direct database access or shell execution.

In scope:

- Backup inventory and metadata.
- Backup creation.
- Backup download through short-lived signed links or streamed authorized responses.
- Backup verification.
- Restore planning and restore execution with strict safeguards.
- Restore dry-run where technically possible.
- Restore history and operation status.
- Retention, deletion, and storage-health visibility.
- Audit events and notifications for every sensitive action.

Out of scope:

- Exposing raw SQL execution to the frontend.
- Letting the frontend choose arbitrary shell commands or paths.
- Agency-scoped database restore.
- Silent production restore without explicit confirmation and audit trail.
- Replacing infrastructure-level disaster recovery managed by hosting/cloud providers.

## Security Model

Database management must be platform-admin only by default.

Required permissions:

- `system.database.view` for listing backups, operation history, and storage status.
- `system.database.backup.create` for requesting a backup.
- `system.database.backup.download` for downloading a backup artifact.
- `system.database.backup.delete` for deleting backup artifacts.
- `system.database.restore.plan` for validating a restore candidate and seeing the restore plan.
- `system.database.restore.execute` for executing a restore.
- `system.database.maintenance.manage` for retention cleanup and maintenance toggles.

Permission rules:

- Add all database-management permissions to platform-admin.
- Add all database-management permissions to protected permissions.
- Add all database-management permissions to non-delegable protected permissions.
- Non-platform roles must not receive these permissions even when protected delegation is enabled.
- Restore execution must require re-authentication or step-up confirmation in addition to the permission check.

## ADM-DB-001: Backup Configuration And Storage Policy

Define the backup infrastructure configuration.

Required configuration:

- Enabled flag, default false outside local/test until explicitly configured.
- Database driver support, initially PostgreSQL because the test suite and schema are PostgreSQL-oriented.
- Backup disk name, defaulting to a private filesystem disk.
- Optional remote disk support for S3-compatible storage.
- Encryption setting and encryption key source.
- Retention settings: max age, max count, and minimum protected count.
- Maximum backup artifact size accepted for download and restore.
- Tool paths for `pg_dump`, `pg_restore`, and `psql` if native tools are used.
- Environment guard for restore: production restore disabled unless `DATABASE_RESTORE_ENABLED=true`.

Acceptance criteria:

- Configuration is centralized under a dedicated key such as `config/database_management.php`.
- Missing required backup configuration causes backup requests to return a clear 422 or 503, not a 500.
- Production restore is disabled by default.
- Restore cannot run when the configured backup disk is public.
- Tests cover disabled config, missing tool path, and private-disk enforcement.

## ADM-DB-002: Backup Artifact Metadata

Create a first-class database backup artifact model.

Suggested table: `database_backups`.

Required fields:

- `public_id`
- `filename`
- `disk`
- `path`
- `status`: `pending`, `running`, `completed`, `failed`, `verified`, `deleted`
- `database_connection`
- `database_driver`
- `size_bytes`
- `checksum_sha256`
- `encrypted`
- `compression`
- `started_at`
- `completed_at`
- `expires_at`
- `created_by_user_id`
- `deleted_by_user_id`
- `failure_reason`
- `metadata`

Acceptance criteria:

- Backup artifacts use public ids in API routes.
- Raw filesystem paths are never exposed to ordinary API responses.
- Completed artifacts store size and checksum before becoming downloadable.
- Failed artifacts store a bounded, non-secret failure reason.
- Deleted artifacts are retained as metadata but their file path is no longer downloadable.
- Feature tests prove metadata is visible only to authorized platform admins.

## ADM-DB-003: Backup Operation Jobs

Implement asynchronous backup creation.

Required endpoint:

- `POST /api/v1/database/backups`

Required behavior:

- Creates a backup metadata row with `pending` status.
- Dispatches a queued job to run the backup.
- Returns `202 Accepted` with the backup public id and operation status.
- Uses a lock so only one backup job runs at a time per database connection.
- Records security audit events for request, start, success, and failure.

Acceptance criteria:

- Authorized platform admin can request a backup.
- Unauthorized users receive 403.
- Duplicate concurrent backup requests return an existing running operation or a 409 conflict.
- Job success writes a completed artifact, size, checksum, timestamps, and audit event.
- Job failure writes failed status and bounded failure reason.
- Tests fake the backup runner and assert status transitions without invoking real shell tools.

## ADM-DB-004: Backup Inventory, Details, Download, And Deletion

Expose backup inventory and artifact actions for the admin frontend.

Required endpoints:

- `GET /api/v1/database/backups`
- `GET /api/v1/database/backups/{databaseBackup}`
- `GET /api/v1/database/backups/{databaseBackup}/download`
- `DELETE /api/v1/database/backups/{databaseBackup}`

Required behavior:

- List endpoint supports pagination, status filter, date filter, and search by filename/public id.
- Details endpoint returns metadata, status, checksum, size, and verification state.
- Download requires `system.database.backup.download`.
- Download returns either an authorized stream or a short-lived signed URL.
- Deletion removes or tombstones the stored artifact while retaining audit metadata.

Acceptance criteria:

- Download is denied for pending, running, failed, and deleted backups.
- Download is denied when checksum or file existence verification fails.
- Signed URLs expire quickly and never expose local absolute paths.
- Delete is denied for running backups.
- Delete records actor, backup public id, checksum, and size in audit properties.
- Tests cover list filters, download authorization, missing file behavior, and deletion tombstones.

## ADM-DB-005: Backup Verification

Allow admins to verify backup integrity.

Required endpoint:

- `POST /api/v1/database/backups/{databaseBackup}/verify`

Required behavior:

- Recomputes checksum and compares it with stored checksum.
- Confirms the artifact exists on the configured disk.
- Optionally runs a structural verification using backup tooling when available.
- Updates verification status and timestamp.

Acceptance criteria:

- Verification succeeds only when file exists and checksum matches.
- Verification failure does not delete the backup automatically.
- Verification failure records a security audit event.
- Download can require the most recent verification to be valid if config enables strict verification.
- Tests cover matching checksum, missing file, mismatched checksum, and audit behavior.

## ADM-DB-006: Restore Planning

Restore must be a two-step workflow: plan first, execute second.

Required endpoint:

- `POST /api/v1/database/restores/plan`

Required request fields:

- `backup_public_id`
- `target`: `same_database`, `staging_database`, or `external_database`
- `mode`: `dry_run`, `replace`, or `verify_only`
- `confirmation_phrase` only when required by config

Required behavior:

- Validates backup status, checksum, compatibility, driver, and encryption.
- Produces a restore plan without changing the database.
- Flags destructive effects clearly.
- Returns a restore operation public id and a short-lived execution token or challenge id.

Acceptance criteria:

- Plan is denied for unverified backups when strict verification is enabled.
- Plan is denied for deleted, failed, running, or missing backups.
- Same-database replacement is disabled in production unless explicitly enabled.
- Plan response includes target, mode, backup checksum, destructive flag, and expiry.
- Planning records a security audit event.
- Tests cover invalid backup, production disabled restore, and successful dry-run plan.

## ADM-DB-007: Restore Execution

Implement guarded restore execution.

Required endpoint:

- `POST /api/v1/database/restores/{restoreOperation}/execute`

Required behavior:

- Requires `system.database.restore.execute`.
- Requires a valid restore plan that has not expired.
- Requires step-up confirmation: recent password confirmation, signed challenge, or OTP.
- Refuses to run while another restore or backup is active.
- Runs asynchronously unless explicitly configured for local/test synchronous execution.
- Records operation status: `pending`, `running`, `completed`, `failed`, `cancelled`.

Acceptance criteria:

- Restore execution cannot be called directly without a prior plan.
- Expired plans cannot execute.
- Restore execution is blocked in production by default.
- Same-database restore first creates a pre-restore backup unless disabled outside production.
- Restore job records audit events for request, start, success, and failure.
- Tests fake the restore runner and prove no live database is mutated during feature tests.

## ADM-DB-008: Restore Operation History

Create restore operation metadata.

Suggested table: `database_restore_operations`.

Required fields:

- `public_id`
- `backup_id`
- `status`
- `target`
- `mode`
- `planned_by_user_id`
- `executed_by_user_id`
- `started_at`
- `completed_at`
- `expires_at`
- `confirmation_method`
- `pre_restore_backup_id`
- `failure_reason`
- `metadata`

Required endpoints:

- `GET /api/v1/database/restores`
- `GET /api/v1/database/restores/{restoreOperation}`
- `POST /api/v1/database/restores/{restoreOperation}/cancel` for pending operations only

Acceptance criteria:

- Restore history is visible only to platform admins with database view permission.
- Operation detail links backup metadata without exposing raw paths.
- Cancellation is allowed only before the runner starts.
- Failed restore operations preserve bounded failure reasons.
- Tests cover listing, detail, cancellation, authorization, and audit behavior.

## ADM-DB-009: Storage Health And Retention

Expose storage and retention status for the admin UI.

Required endpoints:

- `GET /api/v1/database/storage`
- `POST /api/v1/database/backups/retention/run`

Required behavior:

- Reports backup disk name, reachability, free-space signal where available, configured retention policy, backup count, total bytes, and last successful backup.
- Retention run deletes expired backups while preserving minimum protected count.
- Retention never deletes the last successful verified backup unless explicitly configured.

Acceptance criteria:

- Storage status never exposes secrets or local absolute paths.
- Retention dry-run mode reports candidates without deleting them.
- Retention execution records audit event with deleted artifact public ids and byte count.
- Tests cover dry-run, protected minimum count, expired deletion, and unauthorized access.

## ADM-DB-010: Maintenance Mode And Operational Safeguards

Add operational controls needed around restores.

Required behavior:

- Restore execution can put the API into maintenance mode or a database-write lock.
- Writes classified as registration are blocked during restore.
- Read-only health and restore-status endpoints remain available.
- Restore runner can clear the lock on success/failure.

Acceptance criteria:

- Database restore sets a maintenance flag before destructive restore work begins.
- Financial registration endpoints return a clear unavailable response while restore is running.
- The lock has a failsafe expiry.
- Admin can see lock owner, reason, and expiry.
- Tests cover lock activation, registration write denial, read endpoint allowance, and lock release.

## ADM-DB-011: Audit, Alerts, And Notifications

Every database-management action must be auditable and visible.

Required security events:

- `database.backup.requested`
- `database.backup.started`
- `database.backup.completed`
- `database.backup.failed`
- `database.backup.verified`
- `database.backup.deleted`
- `database.restore.planned`
- `database.restore.requested`
- `database.restore.started`
- `database.restore.completed`
- `database.restore.failed`
- `database.restore.cancelled`
- `database.retention.run`
- `database.maintenance.locked`
- `database.maintenance.unlocked`

Acceptance criteria:

- Event labels are added to `SecurityEventCatalog`.
- Audit properties include public ids, checksums, size, status, and operation mode only.
- Audit properties never include passwords, raw DSNs, local filesystem paths, encryption keys, or raw command output.
- Restore and failed backup events create internal admin notifications.
- Tests verify audit events for every mutation endpoint and job result.

## ADM-DB-012: API Documentation And Frontend Contract

Regenerate API documentation and provide stable frontend payloads.

Required response conventions:

- Use existing `success`, `message`, `data`, `errors`, and `meta.pagination` envelope conventions.
- Use public ids for all backup and restore resources.
- Include operation status values in documented enums.
- Include explicit disabled-state responses for environments where restore is unavailable.

Acceptance criteria:

- `php artisan scramble:export` documents all database-management endpoints.
- Feature tests assert representative response shapes for list, detail, create, verify, plan, execute, and storage status.
- Frontend can render empty state, running state, failed state, completed state, and disabled restore state without guessing.

## Implementation Notes

- Prefer a service abstraction such as `DatabaseBackupRunner` and `DatabaseRestoreRunner` so feature tests can fake shell execution.
- Avoid raw shell command construction in controllers or jobs.
- If using native PostgreSQL tools, build commands through Symfony Process with explicit argument arrays.
- Store artifacts outside public web root.
- Encrypt backup artifacts before remote storage when supported.
- Use queue jobs for long-running operations.
- Do not run restore against the active production database until the environment, operator confirmation, pre-restore backup, and lock behavior are proven by tests.

## Suggested Test Targets

Focused tests:

```bash
php artisan test --parallel --recreate-databases tests/Feature/Api/AdminDatabaseManagementTest.php
php artisan test --parallel --recreate-databases tests/Unit/Application/DatabaseManagement
php artisan test --parallel --recreate-databases tests/Feature/Api/RolePermissionManagementTest.php --filter database
```

Quality gates:

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan scramble:export
```

Test-running notes:

- Put `--parallel` before path arguments.
- Do not execute real `pg_restore` or destructive restore commands in feature tests.
- Do not run multiple `php artisan test --parallel --recreate-databases ...` commands concurrently.
