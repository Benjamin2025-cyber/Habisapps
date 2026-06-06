<?php

declare(strict_types=1);

namespace Tests\Unit\Application\DatabaseManagement;

use App\Support\DatabaseManagement\DatabaseManagementConfig;
use App\Support\DatabaseManagement\DatabaseManagementError;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class DatabaseManagementConfigTest extends TestCase
{
    public function test_disabled_feature_yields_503_backup_error(): void
    {
        config(['database_management.enabled' => false]);

        $error = $this->config()->validateBackup();

        self::assertInstanceOf(DatabaseManagementError::class, $error);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $error->status);
        self::assertSame('database_management_disabled', $error->code);
    }

    public function test_unsupported_driver_yields_422(): void
    {
        config([
            'database_management.enabled' => true,
            'database_management.connection' => 'sqlite',
            'database.connections.sqlite.driver' => 'sqlite',
        ]);

        $error = $this->config()->validateBackup();

        self::assertInstanceOf(DatabaseManagementError::class, $error);
        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $error->status);
        self::assertSame('database_driver_unsupported', $error->code);
    }

    public function test_public_disk_is_rejected(): void
    {
        config([
            'database_management.enabled' => true,
            'database_management.disk' => 'public',
        ]);

        $error = $this->config()->validateBackup();

        self::assertInstanceOf(DatabaseManagementError::class, $error);
        self::assertSame('backup_disk_not_private', $error->code);
    }

    public function test_missing_backup_disk_is_rejected(): void
    {
        config([
            'database_management.enabled' => true,
            'database_management.disk' => 'missing-backup-disk',
        ]);

        $error = $this->config()->validateBackup();

        self::assertInstanceOf(DatabaseManagementError::class, $error);
        self::assertSame('backup_disk_missing', $error->code);
    }

    public function test_public_remote_disk_is_rejected(): void
    {
        config([
            'database_management.enabled' => true,
            'database_management.disk' => 'local',
            'database_management.remote_disk' => 'public',
        ]);

        $error = $this->config()->validateBackup();

        self::assertInstanceOf(DatabaseManagementError::class, $error);
        self::assertSame('backup_remote_disk_not_private', $error->code);
    }

    public function test_encrypted_remote_copy_requires_key_source(): void
    {
        config([
            'app.key' => '',
            'database_management.enabled' => true,
            'database_management.disk' => 'local',
            'database_management.remote_disk' => 'local',
            'database_management.encryption.enabled' => true,
            'database_management.encryption.key' => '',
        ]);

        $error = $this->config()->validateBackup();

        self::assertInstanceOf(DatabaseManagementError::class, $error);
        self::assertSame('backup_encryption_key_missing', $error->code);
    }

    public function test_missing_tool_path_is_rejected(): void
    {
        config([
            'database_management.enabled' => true,
            'database_management.disk' => 'local',
            'database_management.tools.pg_dump' => '',
        ]);

        $error = $this->config()->validateBackup();

        self::assertInstanceOf(DatabaseManagementError::class, $error);
        self::assertSame('backup_tool_missing', $error->code);
    }

    public function test_ready_environment_passes_validation(): void
    {
        config([
            'database_management.enabled' => true,
            'database_management.disk' => 'local',
            'database_management.tools.pg_dump' => 'pg_dump',
            'database_management.tools.pg_restore' => 'pg_restore',
        ]);

        self::assertNull($this->config()->validateBackup());
    }

    public function test_restore_disabled_yields_503(): void
    {
        config([
            'database_management.enabled' => true,
            'database_management.disk' => 'local',
            'database_management.tools.pg_dump' => 'pg_dump',
            'database_management.tools.pg_restore' => 'pg_restore',
            'database_management.restore.enabled' => false,
        ]);

        $error = $this->config()->validateRestore();

        self::assertInstanceOf(DatabaseManagementError::class, $error);
        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $error->status);
        self::assertSame('database_restore_disabled', $error->code);
    }

    private function config(): DatabaseManagementConfig
    {
        return app(DatabaseManagementConfig::class);
    }
}
