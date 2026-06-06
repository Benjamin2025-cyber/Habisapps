<?php

declare(strict_types=1);

namespace App\Jobs\DatabaseManagement;

use App\Application\Notifications\UserNotificationFeed;
use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreOperation;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\DatabaseManagement\Contracts\DatabaseBackupRunner;
use App\Support\DatabaseManagement\Contracts\DatabaseRestoreRunner;
use App\Support\DatabaseManagement\DatabaseBackupFactory;
use App\Support\DatabaseManagement\DatabaseMaintenanceLock;
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
 * Executes a planned restore asynchronously under a maintenance lock.
 *
 * For a destructive (replace) restore it: engages the write lock so financial
 * registration is refused, optionally takes a pre-restore safety backup, runs
 * the restore runner, and always releases the lock. Non-destructive modes
 * (dry_run / verify_only) never mutate the target or take the lock. Audit events
 * and notifications are recorded for start, success, and failure (ADM-DB-007/010/011).
 */
final class RunDatabaseRestore implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly int $operationId,
        private readonly ?int $actorUserId,
    ) {}

    public function handle(
        DatabaseRestoreRunner $restoreRunner,
        DatabaseBackupRunner $backupRunner,
        DatabaseManagementConfig $config,
        DatabaseBackupFactory $backupFactory,
        DatabaseMaintenanceLock $lock,
        SecurityAudit $audit,
        UserNotificationFeed $notifications,
    ): void {
        $operation = DatabaseRestoreOperation::query()->find($this->operationId);
        if (! $operation instanceof DatabaseRestoreOperation || $operation->status !== DatabaseRestoreOperation::STATUS_PENDING) {
            return;
        }

        $backup = $operation->backup;
        if (! $backup instanceof DatabaseBackup) {
            $this->fail($operation, $audit, $notifications, 'Backup artifact is no longer available.');

            return;
        }

        $actor = $this->actorUserId !== null ? User::query()->find($this->actorUserId) : null;
        $destructive = $operation->mode === DatabaseRestoreOperation::MODE_REPLACE;

        $operation->forceFill([
            'status' => DatabaseRestoreOperation::STATUS_RUNNING,
            'started_at' => Carbon::now(),
        ])->save();

        $audit->record('database.restore.started', actor: $actor, subject: $operation, properties: [
            'restore_public_id' => $operation->public_id,
            'backup_public_id' => $backup->public_id,
            'status' => $operation->status,
            'mode' => $operation->mode,
            'target' => $operation->target,
        ]);

        $notifications->notifyPlatform(
            type: UserNotification::TYPE_WARNING,
            category: 'database_management',
            title: 'Database restore started',
            message: 'A database restore operation has started.',
            sourceType: 'database_restore_started',
            sourcePublicId: $operation->public_id,
            metadata: ['mode' => $operation->mode, 'target' => $operation->target],
        );

        if ($destructive) {
            $lock->engage(
                ownerPublicId: $actor?->public_id,
                ownerName: $actor?->name,
                reason: 'Database restore in progress',
                restorePublicId: $operation->public_id,
            );
            $audit->record('database.maintenance.locked', actor: $actor, subject: $operation, properties: [
                'restore_public_id' => $operation->public_id,
            ]);
        }

        try {
            if ($this->needsPreRestoreBackup($operation, $config, $destructive)) {
                $pre = $this->takePreRestoreBackup($backupFactory, $backupRunner, $config, $actor, $audit);
                $operation->forceFill(['pre_restore_backup_id' => $pre->id])->save();
            }

            $restoreRunner->run($operation, $backup);

            $operation->forceFill([
                'status' => DatabaseRestoreOperation::STATUS_COMPLETED,
                'completed_at' => Carbon::now(),
            ])->save();

            $audit->record('database.restore.completed', actor: $actor, subject: $operation, properties: [
                'restore_public_id' => $operation->public_id,
                'backup_public_id' => $backup->public_id,
                'status' => $operation->status,
            ]);

            $notifications->notifyPlatform(
                type: UserNotification::TYPE_SUCCESS,
                category: 'database_management',
                title: 'Database restore completed',
                message: 'A database restore operation completed successfully.',
                sourceType: 'database_restore_completed',
                sourcePublicId: $operation->public_id,
                metadata: ['mode' => $operation->mode, 'target' => $operation->target],
            );
        } catch (Throwable $exception) {
            $this->fail($operation, $audit, $notifications, $this->boundedReason($exception), $actor);
        } finally {
            if ($destructive) {
                $lock->release();
                $audit->record('database.maintenance.unlocked', actor: $actor, subject: $operation, properties: [
                    'restore_public_id' => $operation->public_id,
                ]);
            }
        }
    }

    private function needsPreRestoreBackup(DatabaseRestoreOperation $operation, DatabaseManagementConfig $config, bool $destructive): bool
    {
        if (! $destructive || $operation->target !== DatabaseRestoreOperation::TARGET_SAME_DATABASE) {
            return false;
        }

        // In production a pre-restore backup is mandatory; outside production it
        // may be disabled via config.
        return $config->isProduction() || $config->requirePreRestoreBackup();
    }

    private function takePreRestoreBackup(
        DatabaseBackupFactory $factory,
        DatabaseBackupRunner $runner,
        DatabaseManagementConfig $config,
        ?User $actor,
        SecurityAudit $audit,
    ): DatabaseBackup {
        $backup = $factory->createPending($actor);
        $backup->forceFill([
            'status' => DatabaseBackup::STATUS_RUNNING,
            'started_at' => Carbon::now(),
            'metadata' => ['kind' => 'pre_restore'],
        ])->save();

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
            'kind' => 'pre_restore',
        ]);

        return $backup;
    }

    private function fail(
        DatabaseRestoreOperation $operation,
        SecurityAudit $audit,
        UserNotificationFeed $notifications,
        string $reason,
        ?User $actor = null,
    ): void {
        $operation->forceFill([
            'status' => DatabaseRestoreOperation::STATUS_FAILED,
            'failure_reason' => $reason,
            'completed_at' => Carbon::now(),
        ])->save();

        $audit->record('database.restore.failed', actor: $actor, subject: $operation, properties: [
            'restore_public_id' => $operation->public_id,
            'status' => $operation->status,
        ]);

        $notifications->notifyPlatform(
            type: UserNotification::TYPE_ERROR,
            category: 'database_management',
            title: 'Database restore failed',
            message: 'A database restore operation did not complete. Review the restore log.',
            sourceType: 'database_restore_failed',
            sourcePublicId: $operation->public_id,
            metadata: ['status' => $operation->status],
        );
    }

    private function boundedReason(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        if ($message === '') {
            $message = 'Restore runner failed.';
        }

        return mb_substr($message, 0, 500);
    }
}
