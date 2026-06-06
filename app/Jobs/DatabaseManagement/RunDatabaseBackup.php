<?php

declare(strict_types=1);

namespace App\Jobs\DatabaseManagement;

use App\Application\Notifications\UserNotificationFeed;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\DatabaseManagement\Contracts\DatabaseBackupRunner;
use App\Support\DatabaseManagement\DatabaseManagementConfig;
use App\Support\Security\SecurityAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Runs a database backup asynchronously: drives the artifact's status
 * transitions (running -> completed/failed), persists size/checksum/timestamps,
 * and records audit events plus a failure notification (ADM-DB-003/011).
 */
final class RunDatabaseBackup implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // A backup is not safely auto-retried (a partial artifact may exist); a
    // failed run is recorded and a fresh request must be made.
    public int $tries = 1;

    public function __construct(
        private readonly int $backupId,
        private readonly ?int $actorUserId,
    ) {}

    public function handle(
        DatabaseBackupRunner $runner,
        DatabaseManagementConfig $config,
        SecurityAudit $audit,
        UserNotificationFeed $notifications,
    ): void {
        $backup = DatabaseBackup::query()->find($this->backupId);
        if (! $backup instanceof DatabaseBackup || $backup->status !== DatabaseBackup::STATUS_PENDING) {
            return;
        }

        $actor = $this->actorUserId !== null ? User::query()->find($this->actorUserId) : null;

        $backup->forceFill([
            'status' => DatabaseBackup::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ])->save();

        $audit->record('database.backup.started', actor: $actor, subject: $backup, properties: [
            'backup_public_id' => $backup->public_id,
            'status' => $backup->status,
        ]);

        try {
            $result = $runner->run($backup);

            $maxAge = $config->retentionMaxAgeDays();

            $backup->forceFill([
                'status' => DatabaseBackup::STATUS_COMPLETED,
                'size_bytes' => $result->sizeBytes,
                'checksum_sha256' => $result->checksumSha256,
                'encrypted' => $result->encrypted,
                'compression' => $result->compression,
                'completed_at' => Carbon::now(),
                'expires_at' => $maxAge > 0 ? Carbon::now()->addDays($maxAge) : null,
            ])->save();

            $audit->record('database.backup.completed', actor: $actor, subject: $backup, properties: [
                'backup_public_id' => $backup->public_id,
                'status' => $backup->status,
                'size_bytes' => $backup->size_bytes,
                'checksum_sha256' => $backup->checksum_sha256,
            ]);
        } catch (Throwable $exception) {
            $backup->forceFill([
                'status' => DatabaseBackup::STATUS_FAILED,
                'failure_reason' => $this->boundedReason($exception),
                'completed_at' => Carbon::now(),
            ])->save();

            $audit->record('database.backup.failed', actor: $actor, subject: $backup, properties: [
                'backup_public_id' => $backup->public_id,
                'status' => $backup->status,
            ]);

            $notifications->notifyPlatform(
                type: UserNotification::TYPE_ERROR,
                category: 'database_management',
                title: 'Database backup failed',
                message: 'A database backup did not complete. Review the backup log.',
                sourceType: 'database_backup',
                sourcePublicId: $backup->public_id,
                metadata: ['status' => $backup->status],
            );
        }
    }

    private function boundedReason(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = 'Backup runner failed.';
        }

        return mb_substr($message, 0, 500);
    }
}
