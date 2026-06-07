<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

use Symfony\Component\HttpFoundation\Response;

/**
 * Centralised, typed reader and validator for config/database_management.php.
 *
 * All readiness checks return a {@see DatabaseManagementError} (never throw a
 * raw exception) so callers can map a misconfigured/disabled environment to a
 * clear 422/503 response rather than a 500 (ADM-DB-001).
 */
final class DatabaseManagementConfig
{
    public function isEnabled(): bool
    {
        return (bool) config('database_management.enabled', false);
    }

    public function isProduction(): bool
    {
        return app()->isProduction();
    }

    public function connection(): string
    {
        $connection = config('database_management.connection');
        if (is_string($connection) && $connection !== '') {
            return $connection;
        }

        $default = config('database.default', 'pgsql');

        return is_string($default) && $default !== '' ? $default : 'pgsql';
    }

    public function driver(): string
    {
        $driver = config('database.connections.'.$this->connection().'.driver');

        return is_string($driver) ? $driver : '';
    }

    public function isDriverSupported(?string $driver = null): bool
    {
        return in_array($driver ?? $this->driver(), $this->supportedDrivers(), true);
    }

    /**
     * @return array<int, string>
     */
    public function supportedDrivers(): array
    {
        $drivers = config('database_management.supported_drivers', ['pgsql']);

        return is_array($drivers) ? array_values(array_filter($drivers, 'is_string')) : ['pgsql'];
    }

    public function disk(): string
    {
        $disk = config('database_management.disk');

        return is_string($disk) && $disk !== '' ? $disk : 'local';
    }

    public function remoteDisk(): ?string
    {
        $disk = config('database_management.remote_disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    public function pathPrefix(): string
    {
        $prefix = config('database_management.path_prefix', 'database-backups');

        return is_string($prefix) && $prefix !== '' ? trim($prefix, '/') : 'database-backups';
    }

    /**
     * A disk is "public" when its configured visibility is public or it is the
     * conventional public disk. Database artifacts must never live on such a
     * disk (ADM-DB-001).
     */
    public function isDiskPublic(string $disk): bool
    {
        // Only an explicitly public-visibility disk (or the conventional
        // "public" disk) is rejected. A private remote disk that merely has a
        // configured URL/endpoint (common for S3) is still private.
        return config('filesystems.disks.'.$disk.'.visibility') === 'public'
            || $disk === 'public';
    }

    public function isDiskConfigured(string $disk): bool
    {
        return is_array(config('filesystems.disks.'.$disk));
    }

    public function encryptionEnabled(): bool
    {
        return (bool) config('database_management.encryption.enabled', false);
    }

    public function encryptionKey(): ?string
    {
        $key = config('database_management.encryption.key');
        if (is_string($key) && $key !== '') {
            return $key;
        }

        $appKey = config('app.key');

        return is_string($appKey) && $appKey !== '' ? $appKey : null;
    }

    public function compression(): ?string
    {
        $compression = config('database_management.compression');

        return is_string($compression) && $compression !== '' ? $compression : null;
    }

    public function maxArtifactBytes(): int
    {
        return max(0, $this->intConfig('database_management.max_artifact_bytes', 0));
    }

    public function tool(string $name): string
    {
        $tool = config('database_management.tools.'.$name);

        return is_string($tool) ? trim($tool) : '';
    }

    public function runSynchronously(): bool
    {
        return (bool) config('database_management.run_synchronously', false);
    }

    public function strictVerification(): bool
    {
        return (bool) config('database_management.strict_verification', false);
    }

    public function downloadTtlMinutes(): int
    {
        return max(1, $this->intConfig('database_management.download.signed_url_ttl_minutes', 5));
    }

    public function retentionMaxAgeDays(): int
    {
        return max(0, $this->intConfig('database_management.retention.max_age_days', 30));
    }

    public function retentionMaxCount(): int
    {
        return max(0, $this->intConfig('database_management.retention.max_count', 30));
    }

    public function retentionMinProtected(): int
    {
        return max(0, $this->intConfig('database_management.retention.min_protected', 0));
    }

    public function retentionKeepLastVerified(): bool
    {
        return (bool) config('database_management.retention.keep_last_verified', true);
    }

    public function restoreEnabled(): bool
    {
        return (bool) config('database_management.restore.enabled', false);
    }

    public function allowSameDatabaseInProduction(): bool
    {
        return (bool) config('database_management.restore.allow_same_database_in_production', false);
    }

    public function requirePreRestoreBackup(): bool
    {
        return (bool) config('database_management.restore.require_pre_restore_backup', true);
    }

    public function requireConfirmationPhrase(): bool
    {
        return (bool) config('database_management.restore.require_confirmation_phrase', false);
    }

    public function confirmationPhrase(): string
    {
        $phrase = config('database_management.restore.confirmation_phrase');

        return is_string($phrase) ? $phrase : '';
    }

    public function planTtlMinutes(): int
    {
        return max(1, $this->intConfig('database_management.restore.plan_ttl_minutes', 15));
    }

    public function lockTtlMinutes(): int
    {
        return max(1, $this->intConfig('database_management.maintenance.lock_ttl_minutes', 30));
    }

    private function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Readiness for creating/verifying/downloading backups. Returns null when
     * the environment is ready, otherwise a clear disabled/misconfig error.
     */
    public function validateBackup(): ?DatabaseManagementError
    {
        if (! $this->isEnabled()) {
            return new DatabaseManagementError(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'database_management_disabled',
                __('database_management.disabled_env'),
            );
        }

        if (! $this->isDriverSupported()) {
            return new DatabaseManagementError(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'database_driver_unsupported',
                __('database_management.driver_unsupported'),
            );
        }

        if (! $this->isDiskConfigured($this->disk())) {
            return new DatabaseManagementError(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'backup_disk_missing',
                __('database_management.backup_disk_missing'),
            );
        }

        if ($this->isDiskPublic($this->disk())) {
            return new DatabaseManagementError(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'backup_disk_not_private',
                __('database_management.backup_disk_not_private'),
            );
        }

        $remoteDisk = $this->remoteDisk();
        if ($remoteDisk !== null) {
            if (! $this->isDiskConfigured($remoteDisk)) {
                return new DatabaseManagementError(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'backup_remote_disk_missing',
                    __('database_management.remote_disk_missing'),
                );
            }

            if ($this->isDiskPublic($remoteDisk)) {
                return new DatabaseManagementError(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'backup_remote_disk_not_private',
                    __('database_management.remote_disk_not_private'),
                );
            }

            if ($this->encryptionEnabled() && $this->encryptionKey() === null) {
                return new DatabaseManagementError(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'backup_encryption_key_missing',
                    __('database_management.backup_encryption_key_required'),
                );
            }
        }

        foreach (['pg_dump'] as $required) {
            if ($this->tool($required) === '') {
                return new DatabaseManagementError(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'backup_tool_missing',
                    __('database_management.backup_tooling_missing'),
                );
            }
        }

        return null;
    }

    /**
     * Readiness for planning/executing a restore. Builds on backup readiness
     * and adds restore-specific guards (feature switch, private disk, tools).
     */
    public function validateRestore(): ?DatabaseManagementError
    {
        $backup = $this->validateBackup();
        if ($backup instanceof DatabaseManagementError) {
            return $backup;
        }

        if (! $this->restoreEnabled()) {
            return new DatabaseManagementError(
                Response::HTTP_SERVICE_UNAVAILABLE,
                'database_restore_disabled',
                __('database_management.restore_disabled_env'),
            );
        }

        if ($this->isDiskPublic($this->disk())) {
            return new DatabaseManagementError(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'backup_disk_not_private',
                __('database_management.restore_disk_public'),
            );
        }

        foreach (['pg_restore'] as $required) {
            if ($this->tool($required) === '') {
                return new DatabaseManagementError(
                    Response::HTTP_UNPROCESSABLE_ENTITY,
                    'restore_tool_missing',
                    __('database_management.restore_tooling_missing'),
                );
            }
        }

        return null;
    }
}
