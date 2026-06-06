<?php

declare(strict_types=1);

namespace App\Application\DatabaseManagement;

use App\Http\Requests\Api\V1\DatabaseManagement\ExecuteDatabaseRestoreRequest;
use App\Http\Requests\Api\V1\DatabaseManagement\IndexDatabaseBackupRequest;
use App\Http\Requests\Api\V1\DatabaseManagement\PlanDatabaseRestoreRequest;
use App\Http\Requests\Api\V1\DatabaseManagement\RunRetentionRequest;
use App\Http\Requests\Api\V1\DatabaseManagement\StoreDatabaseBackupRequest;
use App\Http\Resources\DatabaseBackupCollection;
use App\Http\Resources\DatabaseRestoreOperationCollection;
use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delegation-only seam between the transport controllers and the database-
 * management workflows. Keeps controllers as thin transport glue.
 */
final class DatabaseManagementWorkflowControllerAdapter
{
    public function __construct(
        private readonly BackupWorkflow $backups,
        private readonly BackupVerificationWorkflow $verification,
        private readonly RestoreWorkflow $restores,
        private readonly DatabaseStorageWorkflow $storage,
    ) {}

    public function indexBackups(IndexDatabaseBackupRequest $request): JsonResponse|DatabaseBackupCollection
    {
        return $this->backups->index($request);
    }

    public function showBackup(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        return $this->backups->show($request, $databaseBackup);
    }

    public function storeBackup(StoreDatabaseBackupRequest $request): JsonResponse
    {
        return $this->backups->store($request);
    }

    public function downloadBackup(Request $request, DatabaseBackup $databaseBackup): JsonResponse|Response
    {
        return $this->backups->download($request, $databaseBackup);
    }

    public function destroyBackup(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        return $this->backups->destroy($request, $databaseBackup);
    }

    public function verifyBackup(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        return $this->verification->verify($request, $databaseBackup);
    }

    public function planRestore(PlanDatabaseRestoreRequest $request): JsonResponse
    {
        return $this->restores->plan($request);
    }

    public function executeRestore(ExecuteDatabaseRestoreRequest $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        return $this->restores->execute($request, $restoreOperation);
    }

    public function indexRestores(Request $request): JsonResponse|DatabaseRestoreOperationCollection
    {
        return $this->restores->index($request);
    }

    public function showRestore(Request $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        return $this->restores->show($request, $restoreOperation);
    }

    public function cancelRestore(Request $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        return $this->restores->cancel($request, $restoreOperation);
    }

    public function storage(Request $request): JsonResponse
    {
        return $this->storage->storage($request);
    }

    public function runRetention(RunRetentionRequest $request): JsonResponse
    {
        return $this->storage->runRetention($request);
    }
}
