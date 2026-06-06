<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\DatabaseManagement\DatabaseManagementWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\DatabaseManagement\RunRetentionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DatabaseStorageController extends BaseController
{
    public function __construct(
        private readonly DatabaseManagementWorkflowControllerAdapter $database,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->database->storage($request);
    }

    public function runRetention(RunRetentionRequest $request): JsonResponse
    {
        return $this->database->runRetention($request);
    }
}
