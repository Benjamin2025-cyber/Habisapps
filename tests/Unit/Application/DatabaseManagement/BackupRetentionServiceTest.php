<?php

declare(strict_types=1);

namespace Tests\Unit\Application\DatabaseManagement;

use App\Models\DatabaseBackup;
use App\Support\DatabaseManagement\BackupRetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackupRetentionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_backups_are_candidates_when_age_exceeded(): void
    {
        config([
            'database_management.retention.max_age_days' => 1,
            'database_management.retention.max_count' => 0,
            'database_management.retention.min_protected' => 0,
            'database_management.retention.keep_last_verified' => false,
        ]);

        $old1 = $this->backup(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(5));
        $old2 = $this->backup(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(3));
        $recent = $this->backup(DatabaseBackup::STATUS_COMPLETED, Carbon::now());

        $candidates = app(BackupRetentionService::class)->candidates()->pluck('id')->all();

        self::assertContains($old1->id, $candidates);
        self::assertContains($old2->id, $candidates);
        self::assertNotContains($recent->id, $candidates);
    }

    public function test_minimum_protected_count_is_never_a_candidate(): void
    {
        config([
            'database_management.retention.max_age_days' => 1,
            'database_management.retention.max_count' => 0,
            'database_management.retention.min_protected' => 2,
            'database_management.retention.keep_last_verified' => false,
        ]);

        // Three old backups; the two newest must be protected.
        $oldest = $this->backup(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(10));
        $middle = $this->backup(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(5));
        $newest = $this->backup(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(3));

        $candidates = app(BackupRetentionService::class)->candidates()->pluck('id')->all();

        self::assertSame([$oldest->id], $candidates);
        self::assertNotContains($middle->id, $candidates);
        self::assertNotContains($newest->id, $candidates);
    }

    public function test_last_verified_backup_is_preserved_when_configured(): void
    {
        config([
            'database_management.retention.max_age_days' => 1,
            'database_management.retention.max_count' => 0,
            'database_management.retention.min_protected' => 0,
            'database_management.retention.keep_last_verified' => true,
        ]);

        $verified = $this->backup(DatabaseBackup::STATUS_VERIFIED, Carbon::now()->subDays(8));
        $completed = $this->backup(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(6));

        $candidates = app(BackupRetentionService::class)->candidates()->pluck('id')->all();

        self::assertNotContains($verified->id, $candidates);
        self::assertContains($completed->id, $candidates);
    }

    private function backup(string $status, Carbon $createdAt): DatabaseBackup
    {
        $publicId = (string) Str::ulid();

        $backup = DatabaseBackup::query()->create([
            'public_id' => $publicId,
            'filename' => 'backup_'.$publicId.'.dump',
            'disk' => 'local',
            'path' => 'database-backups/backup_'.$publicId.'.dump',
            'status' => $status,
            'database_connection' => 'pgsql',
            'database_driver' => 'pgsql',
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', $publicId),
            'verification_status' => $status === DatabaseBackup::STATUS_VERIFIED ? DatabaseBackup::VERIFICATION_PASSED : null,
        ]);

        // created_at drives age-based retention; set it explicitly.
        $backup->forceFill(['created_at' => $createdAt])->save();

        return $backup->refresh();
    }
}
