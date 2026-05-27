<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicTreatmentWorkflow;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicTreatmentController extends BaseController
{
    public function __construct(private readonly IslamicTreatmentWorkflow $workflow) {}

    public function indexPolicies(Request $request): JsonResponse
    {
        return $this->workflow->indexPolicies($request);
    }

    public function storePolicy(Request $request): JsonResponse
    {
        return $this->workflow->storePolicy($request);
    }

    public function approvePolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->workflow->approvePolicy($request, $policyPublicId);
    }

    public function storeEvent(Request $request): JsonResponse
    {
        return $this->workflow->storeEvent($request);
    }

    public function postEvent(Request $request, string $eventPublicId): JsonResponse
    {
        return $this->workflow->postEvent($request, $eventPublicId);
    }

    public function reconciliationReport(Request $request): JsonResponse
    {
        return $this->workflow->reconciliationReport($request);
    }
}
