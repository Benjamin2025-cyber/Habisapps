<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\BatchRuns\BatchRunWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Resources\BatchRunCollection;
use App\Models\BatchRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BatchRunController extends BaseController
{
    public function __construct(
        private readonly BatchRunWorkflow $workflow,
    ) {}

    public function index(Request $request): BatchRunCollection
    {
        return $this->workflow->index($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->workflow->store($request);
    }

    public function show(Request $request, BatchRun $batchRun): JsonResponse
    {
        return $this->workflow->show($request, $batchRun);
    }

    public function updateStatus(Request $request, BatchRun $batchRun): JsonResponse
    {
        return $this->workflow->updateStatus($request, $batchRun);
    }

    public function execute(Request $request, BatchRun $batchRun): JsonResponse
    {
        return $this->workflow->execute($request, $batchRun);
    }

    public function retry(Request $request, BatchRun $batchRun): JsonResponse
    {
        return $this->workflow->retry($request, $batchRun);
    }

    public function cancel(Request $request, BatchRun $batchRun): JsonResponse
    {
        return $this->workflow->cancel($request, $batchRun);
    }
}
