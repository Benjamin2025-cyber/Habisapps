<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicIstisnaaProjectWorkflow;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicIstisnaaProjectController extends BaseController
{
    public function __construct(private readonly IslamicIstisnaaProjectWorkflow $workflow) {}

    public function store(Request $request): JsonResponse
    {
        return $this->workflow->storeProject($request);
    }

    public function show(Request $request, string $projectPublicId): JsonResponse
    {
        return $this->workflow->showProject($request, $projectPublicId);
    }

    public function timeline(Request $request, string $projectPublicId): JsonResponse
    {
        return $this->workflow->showTimeline($request, $projectPublicId);
    }

    public function storeMilestone(Request $request, string $projectPublicId): JsonResponse
    {
        return $this->workflow->storeMilestone($request, $projectPublicId);
    }

    public function storeInspection(Request $request, string $milestonePublicId): JsonResponse
    {
        return $this->workflow->storeInspection($request, $milestonePublicId);
    }

    public function storePayment(Request $request, string $milestonePublicId): JsonResponse
    {
        return $this->workflow->storePayment($request, $milestonePublicId);
    }

    public function storeVariation(Request $request, string $projectPublicId): JsonResponse
    {
        return $this->workflow->storeVariation($request, $projectPublicId);
    }

    public function accept(Request $request, string $projectPublicId): JsonResponse
    {
        return $this->workflow->acceptProject($request, $projectPublicId);
    }

    public function approveParallelSupplier(Request $request, string $projectPublicId): JsonResponse
    {
        return $this->workflow->approveParallelSupplier($request, $projectPublicId);
    }
}
