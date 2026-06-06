<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\DatabaseManagement\DatabaseManagementWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\DatabaseManagement\ExecuteDatabaseRestoreRequest;
use App\Http\Requests\Api\V1\DatabaseManagement\PlanDatabaseRestoreRequest;
use App\Http\Resources\DatabaseRestoreOperationCollection;
use App\Models\DatabaseRestoreOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseRestoreController extends BaseController
{
    public function __construct(
        private readonly DatabaseManagementWorkflowControllerAdapter $database,
    ) {}

    public function index(Request $request): JsonResponse|DatabaseRestoreOperationCollection
    {
        return $this->database->indexRestores($request);
    }

    public function plan(PlanDatabaseRestoreRequest $request): JsonResponse
    {
        return $this->database->planRestore($request);
    }

    public function show(Request $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        return $this->database->showRestore($request, $restoreOperation);
    }

    public function execute(ExecuteDatabaseRestoreRequest $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        return $this->database->executeRestore($request, $restoreOperation);
    }

    public function cancel(Request $request, DatabaseRestoreOperation $restoreOperation): JsonResponse
    {
        return $this->database->cancelRestore($request, $restoreOperation);
    }
}
