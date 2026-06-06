<?php

declare(strict_types=1);

$appEnv = env('APP_ENV', 'production');
$isLocalOrTest = in_array($appEnv, ['local', 'testing'], true);

return [
    /*
    |--------------------------------------------------------------------------
    | Feature Enablement
    |--------------------------------------------------------------------------
    |
    | Database-management APIs are privileged system operations. They stay
    | disabled outside local/testing until an operator explicitly enables them,
    | so a half-configured environment can never silently expose backup or
    | restore endpoints. When disabled, mutation endpoints return a clear
    | disabled-state response (503), never a 500.
    |
    */
    'enabled' => (bool) env('DATABASE_MANAGEMENT_ENABLED', $isLocalOrTest),

    /*
    |--------------------------------------------------------------------------
    | Database Driver Support
    |--------------------------------------------------------------------------
    |
    | Initially PostgreSQL only: the schema and test suite are Postgres-oriented
    | and the native runner shells out to pg_dump/pg_restore. The configured
    | database connection's driver must appear here for backups to run.
    |
    */
    'connection' => env('DATABASE_MANAGEMENT_CONNECTION', env('DB_CONNECTION', 'pgsql')),
    'supported_drivers' => ['pgsql'],

    /*
    |--------------------------------------------------------------------------
    | Storage Policy
    |--------------------------------------------------------------------------
    |
    | Backups are stored on a private filesystem disk by default. A public disk
    | is never acceptable for database artifacts; the config layer refuses
    | restore (and surfaces a clear error) when the backup disk is public. An
    | optional remote disk (S3-compatible) may be configured for off-host copies.
    |
    */
    'disk' => env('DATABASE_MANAGEMENT_DISK', 'local'),
    'remote_disk' => env('DATABASE_MANAGEMENT_REMOTE_DISK'),
    'path_prefix' => env('DATABASE_MANAGEMENT_PATH_PREFIX', 'database-backups'),

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | When enabled, artifacts are encrypted before being written to a remote
    | disk. The key is sourced from the dedicated env var below (falling back to
    | the application key). The key itself is never exposed in API responses or
    | audit properties.
    |
    */
    'encryption' => [
        'enabled' => (bool) env('DATABASE_MANAGEMENT_ENCRYPTION_ENABLED', false),
        'key' => env('DATABASE_MANAGEMENT_ENCRYPTION_KEY'),
    ],

    'compression' => env('DATABASE_MANAGEMENT_COMPRESSION', 'gzip'),

    /*
    |--------------------------------------------------------------------------
    | Retention Policy
    |--------------------------------------------------------------------------
    |
    | max_age_days   - completed backups older than this are retention candidates.
    | max_count      - keep at most this many completed backups.
    | min_protected  - never delete below this many of the newest completed
    |                  backups, regardless of age/count.
    | keep_last_verified - retention never deletes the last successful verified
    |                  backup unless this is explicitly disabled.
    |
    */
    'retention' => [
        'max_age_days' => (int) env('DATABASE_MANAGEMENT_RETENTION_MAX_AGE_DAYS', 30),
        'max_count' => (int) env('DATABASE_MANAGEMENT_RETENTION_MAX_COUNT', 30),
        'min_protected' => (int) env('DATABASE_MANAGEMENT_RETENTION_MIN_PROTECTED', 3),
        'keep_last_verified' => (bool) env('DATABASE_MANAGEMENT_RETENTION_KEEP_LAST_VERIFIED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Size Limits
    |--------------------------------------------------------------------------
    |
    | Maximum artifact size (bytes) accepted for download and restore. Guards
    | against streaming or restoring an unexpectedly large artifact. 0 disables
    | the check.
    |
    */
    'max_artifact_bytes' => (int) env('DATABASE_MANAGEMENT_MAX_ARTIFACT_BYTES', 5 * 1024 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Native Tool Paths
    |--------------------------------------------------------------------------
    |
    | Absolute or PATH-resolvable executables used by the native PostgreSQL
    | runner. Commands are always built with Symfony Process and explicit
    | argument arrays — never string concatenation.
    |
    */
    'tools' => [
        'pg_dump' => env('DATABASE_MANAGEMENT_PG_DUMP', 'pg_dump'),
        'pg_restore' => env('DATABASE_MANAGEMENT_PG_RESTORE', 'pg_restore'),
        'psql' => env('DATABASE_MANAGEMENT_PSQL', 'psql'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup Execution
    |--------------------------------------------------------------------------
    |
    | run_synchronously - run backup/restore inline instead of on the queue.
    |                     Defaults on for local/testing so feature tests observe
    |                     terminal status without a queue worker.
    | strict_verification - when true, downloads and restore plans require the
    |                     most recent verification to be valid.
    |
    */
    'run_synchronously' => (bool) env('DATABASE_MANAGEMENT_RUN_SYNC', $isLocalOrTest),
    'strict_verification' => (bool) env('DATABASE_MANAGEMENT_STRICT_VERIFICATION', false),

    /*
    |--------------------------------------------------------------------------
    | Download Links
    |--------------------------------------------------------------------------
    |
    | Backups are downloaded through short-lived signed URLs. Local absolute
    | paths are never exposed. ttl is in minutes.
    |
    */
    'download' => [
        'signed_url_ttl_minutes' => (int) env('DATABASE_MANAGEMENT_DOWNLOAD_TTL_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Restore Safeguards
    |--------------------------------------------------------------------------
    |
    | Restore is the most dangerous operation in the system. By default it is
    | disabled in production and requires the explicit env switch below. Same-
    | database replacement carries its own production switch. A step-up
    | confirmation (password) is always required to execute a restore, and a
    | confirmation phrase may additionally be required at plan time.
    |
    */
    'restore' => [
        'enabled' => (bool) env('DATABASE_RESTORE_ENABLED', $isLocalOrTest),
        'allow_same_database_in_production' => (bool) env('DATABASE_RESTORE_ALLOW_SAME_DB_IN_PRODUCTION', false),
        'require_pre_restore_backup' => (bool) env('DATABASE_RESTORE_REQUIRE_PRE_BACKUP', true),
        'require_confirmation_phrase' => (bool) env('DATABASE_RESTORE_REQUIRE_CONFIRMATION_PHRASE', false),
        'confirmation_phrase' => env('DATABASE_RESTORE_CONFIRMATION_PHRASE', 'RESTORE PRODUCTION DATABASE'),
        'plan_ttl_minutes' => (int) env('DATABASE_RESTORE_PLAN_TTL_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Lock
    |--------------------------------------------------------------------------
    |
    | While a restore runs the API takes a database-write lock so financial
    | registration writes are refused. The lock has a failsafe expiry so a
    | crashed runner cannot wedge the API indefinitely.
    |
    */
    'maintenance' => [
        'lock_ttl_minutes' => (int) env('DATABASE_MANAGEMENT_LOCK_TTL_MINUTES', 30),
    ],
];
