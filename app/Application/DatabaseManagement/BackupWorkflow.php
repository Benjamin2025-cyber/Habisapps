<?php

declare(strict_types=1);

namespace App\Application\DatabaseManagement;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\DatabaseManagement\IndexDatabaseBackupRequest;
use App\Http\Requests\Api\V1\DatabaseManagement\StoreDatabaseBackupRequest;
use App\Http\Resources\DatabaseBackupCollection;
use App\Http\Resources\DatabaseBackupResource;
use App\Jobs\DatabaseManagement\RunDatabaseBackup;
use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreOperation;
use App\Models\User;
use App\Support\DatabaseManagement\BackupArtifactStore;
use App\Support\DatabaseManagement\DatabaseBackupFactory;
use App\Support\DatabaseManagement\DatabaseManagementConfig;
use App\Support\DatabaseManagement\DatabaseManagementError;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Backup inventory, creation, download, and deletion (ADM-DB-003/004).
 */
final class BackupWorkflow extends BaseController
{
    public function __construct(
        private readonly DatabaseManagementConfig $config,
        private readonly BackupArtifactStore $store,
        private readonly DatabaseBackupFactory $factory,
        private readonly SecurityAudit $audit,
    ) {}

    public function index(IndexDatabaseBackupRequest $request): JsonResponse|DatabaseBackupCollection
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.database.view')) {
            return $this->respondForbidden();
        }

        $validated = $request->validated();

        $query = DatabaseBackup::query();

        $status = $validated['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $search = $validated['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $term = '%'.trim($search).'%';
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('filename', 'like', $term)
                    ->orWhere('public_id', 'like', $term);
            });
        }

        $dateFrom = $validated['date_from'] ?? null;
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->where('created_at', '>=', $dateFrom);
        }
        $dateTo = $validated['date_to'] ?? null;
        if (is_string($dateTo) && $dateTo !== '') {
            $query->where('created_at', '<=', $dateTo);
        }

        $query->latest('created_at')->latest('id');

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new DatabaseBackupCollection($query->paginate($perPage));
    }

    public function show(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.database.view')) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(['backup' => DatabaseBackupResource::make($databaseBackup)]);
    }

    public function store(StoreDatabaseBackupRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $error = $this->config->validateBackup();
        if ($error instanceof DatabaseManagementError) {
            return $this->respondConfigError($error);
        }

        $lock = Cache::lock($this->operationScheduleLockKey(), 10);
        if ($lock->get() !== true) {
            return $this->respondOperationConflict();
        }

        try {
            $existing = $this->activeBackupForConnection();
            if ($existing instanceof DatabaseBackup) {
                return $this->respondError(
                    __('database_management.backup_already_running'),
                    [
                        'code' => 'backup_already_running',
                        'backup_public_id' => $existing->public_id,
                        'status' => $existing->status,
                    ],
                    Response::HTTP_CONFLICT,
                );
            }

            if ($this->activeRestoreExists()) {
                return $this->respondError(
                    __('database_management.database_restore_active'),
                    ['code' => 'database_restore_active'],
                    Response::HTTP_CONFLICT,
                );
            }

            $backup = $this->factory->createPending($actor);

            $note = $request->validated()['note'] ?? null;
            if (is_string($note) && $note !== '') {
                $backup->forceFill(['metadata' => ['note' => $note]])->save();
            }

            $this->audit->record('database.backup.requested', actor: $actor, subject: $backup, properties: [
                'backup_public_id' => $backup->public_id,
                'status' => $backup->status,
            ], request: $request);
        } finally {
            $lock->release();
        }

        if ($this->config->runSynchronously()) {
            RunDatabaseBackup::dispatchSync($backup->id, $actor->id);
        } else {
            RunDatabaseBackup::dispatch($backup->id, $actor->id);
        }

        return $this->respondSuccess(
            ['backup' => DatabaseBackupResource::make($backup->refresh())],
            __('database_management.backup_requested'),
            [],
            Response::HTTP_ACCEPTED,
        );
    }

    public function download(Request $request, DatabaseBackup $databaseBackup): JsonResponse|Response
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.database.backup.download')) {
            return $this->respondForbidden();
        }

        if (! $databaseBackup->isDownloadable()) {
            return $this->respondUnprocessable(__('database_management.backup_not_downloadable'), [
                'code' => 'backup_not_downloadable',
                'status' => $databaseBackup->status,
            ]);
        }

        if (! $this->store->exists($databaseBackup)) {
            return $this->respondUnprocessable(__('database_management.backup_artifact_missing'), [
                'code' => 'backup_artifact_missing',
            ]);
        }

        if ($this->config->strictVerification()
            && $databaseBackup->verification_status !== DatabaseBackup::VERIFICATION_PASSED) {
            return $this->respondUnprocessable(__('database_management.backup_verification_required'), [
                'code' => 'backup_verification_required',
            ]);
        }

        $recomputed = $this->store->checksum($databaseBackup);
        if ($recomputed === null || $databaseBackup->checksum_sha256 === null || ! hash_equals($databaseBackup->checksum_sha256, $recomputed)) {
            return $this->respondUnprocessable(__('database_management.backup_checksum_mismatch'), [
                'code' => 'backup_checksum_mismatch',
            ]);
        }

        $maxBytes = $this->config->maxArtifactBytes();
        $size = $this->store->size($databaseBackup);
        if ($maxBytes > 0 && $size !== null && $size > $maxBytes) {
            return $this->respondUnprocessable(__('database_management.backup_too_large'), [
                'code' => 'backup_too_large',
            ]);
        }

        $this->audit->record('database.backup.downloaded', actor: $actor, subject: $databaseBackup, properties: [
            'backup_public_id' => $databaseBackup->public_id,
            'checksum_sha256' => $databaseBackup->checksum_sha256,
            'size_bytes' => $size,
        ], request: $request);

        return $this->store->download($databaseBackup);
    }

    public function destroy(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('system.database.backup.delete')) {
            return $this->respondForbidden();
        }

        if (! $this->config->isEnabled()) {
            return $this->respondError(__('database_management.disabled_env'), [
                'code' => 'database_management_disabled',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($databaseBackup->status === DatabaseBackup::STATUS_RUNNING) {
            return $this->respondUnprocessable(__('database_management.backup_running_cannot_be_deleted'), [
                'code' => 'backup_running',
            ]);
        }

        if ($databaseBackup->isDeleted()) {
            return $this->respondUnprocessable(__('database_management.backup_already_deleted'), [
                'code' => 'backup_already_deleted',
            ]);
        }

        $checksum = $databaseBackup->checksum_sha256;
        $size = $databaseBackup->size_bytes;

        // Tombstone: remove the artifact file but retain metadata for audit.
        $this->store->delete($databaseBackup);

        $databaseBackup->forceFill([
            'status' => DatabaseBackup::STATUS_DELETED,
            'deleted_by_user_id' => $actor->id,
        ])->save();

        $this->audit->record('database.backup.deleted', actor: $actor, subject: $databaseBackup, properties: [
            'backup_public_id' => $databaseBackup->public_id,
            'checksum_sha256' => $checksum,
            'size_bytes' => $size,
        ], request: $request);

        return $this->respondSuccess(
            ['backup' => DatabaseBackupResource::make($databaseBackup)],
            __('database_management.backup_deleted'),
        );
    }

    private function activeBackupForConnection(): ?DatabaseBackup
    {
        $query = DatabaseBackup::query()->where('database_connection', $this->config->connection());
        $query->getQuery()->whereIn('status', [DatabaseBackup::STATUS_PENDING, DatabaseBackup::STATUS_RUNNING]);

        return $query->first();
    }

    private function activeRestoreExists(): bool
    {
        return DatabaseRestoreOperation::query()->getQuery()
            ->whereIn('status', [
                DatabaseRestoreOperation::STATUS_PENDING,
                DatabaseRestoreOperation::STATUS_RUNNING,
            ])
            ->exists();
    }

    private function operationScheduleLockKey(): string
    {
        return 'database_management:operation_schedule:'.$this->config->connection();
    }

    private function respondOperationConflict(): JsonResponse
    {
        return $this->respondError(__('database_management.another_operation_scheduling_locked'), [
            'code' => 'database_operation_scheduling_locked',
        ], Response::HTTP_CONFLICT);
    }

    private function respondConfigError(DatabaseManagementError $error): JsonResponse
    {
        return $this->respondError($error->message, ['code' => $error->code], $error->status);
    }
}
