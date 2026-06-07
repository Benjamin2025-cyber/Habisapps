<?php

declare(strict_types=1);

namespace App\Application\DatabaseManagement;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\DatabaseManagement\RunRetentionRequest;
use App\Http\Resources\DatabaseBackupResource;
use App\Models\DatabaseBackup;
use App\Models\User;
use App\Support\DatabaseManagement\BackupArtifactStore;
use App\Support\DatabaseManagement\BackupRetentionService;
use App\Support\DatabaseManagement\DatabaseMaintenanceLock;
use App\Support\DatabaseManagement\DatabaseManagementConfig;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Storage-health visibility and retention cleanup for the admin UI. Never
 * exposes secrets or absolute paths (ADM-DB-009).
 */
final class DatabaseStorageWorkflow extends BaseController
{
    public function __construct(
        private readonly DatabaseManagementConfig $config,
        private readonly BackupArtifactStore $store,
        private readonly BackupRetentionService $retention,
        private readonly DatabaseMaintenanceLock $lock,
        private readonly SecurityAudit $audit,
    ) {}

    public function storage(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.database.view')) {
            return $this->respondForbidden();
        }

        $disk = $this->config->disk();
        $completedStatuses = [DatabaseBackup::STATUS_COMPLETED, DatabaseBackup::STATUS_VERIFIED];

        $backupCount = DatabaseBackup::query()->getQuery()
            ->whereIn('status', $completedStatuses)
            ->count();

        $totalBytes = (int) DatabaseBackup::query()->getQuery()
            ->whereIn('status', $completedStatuses)
            ->sum('size_bytes');

        $lastSuccessfulQuery = DatabaseBackup::query()->latest('completed_at')->latest('id');
        $lastSuccessfulQuery->getQuery()->whereIn('status', $completedStatuses);
        $lastSuccessful = $lastSuccessfulQuery->first();

        // The lock stores its reason as a translation key; localize it for display.
        $maintenanceLock = $this->lock->current();
        if ($maintenanceLock !== null) {
            $maintenanceLock['reason'] = __($maintenanceLock['reason']);
        }

        return $this->respondSuccess([
            'storage' => [
                'enabled' => $this->config->isEnabled(),
                'disk' => $disk,
                'is_private' => ! $this->config->isDiskPublic($disk),
                'reachable' => $this->diskReachable($disk),
                'free_bytes' => $this->freeBytes($disk),
                'backup_count' => $backupCount,
                'total_bytes' => $totalBytes,
                'last_successful_backup' => $lastSuccessful instanceof DatabaseBackup
                    ? DatabaseBackupResource::make($lastSuccessful)
                    : null,
                'retention_policy' => [
                    'max_age_days' => $this->config->retentionMaxAgeDays(),
                    'max_count' => $this->config->retentionMaxCount(),
                    'min_protected' => $this->config->retentionMinProtected(),
                    'keep_last_verified' => $this->config->retentionKeepLastVerified(),
                ],
                'restore_enabled' => $this->config->restoreEnabled(),
                'maintenance_lock' => $maintenanceLock,
            ],
        ]);
    }

    public function runRetention(RunRetentionRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $this->config->isEnabled()) {
            return $this->respondError(__('database_management.disabled_env'), [
                'code' => 'database_management_disabled',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $dryRun = (bool) ($request->validated()['dry_run'] ?? false);
        $candidates = $this->retention->candidates();

        $summary = $candidates->map(static fn (DatabaseBackup $backup): array => [
            'public_id' => $backup->public_id,
            'size_bytes' => $backup->size_bytes,
        ])->values()->all();

        $totalBytes = $candidates->sum(static fn (DatabaseBackup $backup): int => $backup->size_bytes ?? 0);

        if ($dryRun) {
            return $this->respondSuccess([
                'dry_run' => true,
                'candidate_count' => $candidates->count(),
                'candidates' => $summary,
                'reclaimable_bytes' => $totalBytes,
            ], __('database_management.retention_dry_run_complete'));
        }

        $deletedPublicIds = [];
        foreach ($candidates as $backup) {
            $this->store->delete($backup);
            $backup->forceFill([
                'status' => DatabaseBackup::STATUS_DELETED,
                'deleted_by_user_id' => $actor->id,
            ])->save();
            $deletedPublicIds[] = $backup->public_id;
        }

        $this->audit->record('database.retention.run', actor: $actor, properties: [
            'deleted_backup_public_ids' => $deletedPublicIds,
            'deleted_count' => count($deletedPublicIds),
            'reclaimed_bytes' => $totalBytes,
        ], request: $request);

        return $this->respondSuccess([
            'dry_run' => false,
            'deleted_count' => count($deletedPublicIds),
            'deleted_public_ids' => $deletedPublicIds,
            'reclaimed_bytes' => $totalBytes,
        ], __('database_management.retention_run_complete'));
    }

    private function diskReachable(string $disk): bool
    {
        try {
            $this->store->disk($disk)->files($this->config->pathPrefix());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function freeBytes(string $disk): ?int
    {
        $root = config('filesystems.disks.'.$disk.'.root');
        if (! is_string($root) || $root === '') {
            return null;
        }

        $free = @disk_free_space($root);

        return is_float($free) ? (int) $free : null;
    }
}
