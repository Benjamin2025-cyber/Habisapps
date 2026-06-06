<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\DatabaseManagement\DatabaseManagementWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\DatabaseManagement\IndexDatabaseBackupRequest;
use App\Http\Requests\Api\V1\DatabaseManagement\StoreDatabaseBackupRequest;
use App\Http\Resources\DatabaseBackupCollection;
use App\Models\DatabaseBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DatabaseBackupController extends BaseController
{
    public function __construct(
        private readonly DatabaseManagementWorkflowControllerAdapter $database,
    ) {}

    public function index(IndexDatabaseBackupRequest $request): JsonResponse|DatabaseBackupCollection
    {
        return $this->database->indexBackups($request);
    }

    public function store(StoreDatabaseBackupRequest $request): JsonResponse
    {
        return $this->database->storeBackup($request);
    }

    public function show(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        return $this->database->showBackup($request, $databaseBackup);
    }

    public function download(Request $request, DatabaseBackup $databaseBackup): JsonResponse|Response
    {
        return $this->database->downloadBackup($request, $databaseBackup);
    }

    public function destroy(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        return $this->database->destroyBackup($request, $databaseBackup);
    }

    public function verify(Request $request, DatabaseBackup $databaseBackup): JsonResponse
    {
        return $this->database->verifyBackup($request, $databaseBackup);
    }
}
