<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinanceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicApprovalWorkflowController extends BaseController
{
    public function __construct(
        private readonly IslamicFinanceWorkflowControllerAdapter $islamic,
    ) {}

    public function show(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->islamic->showApprovalWorkflow($request, $subjectType, $subjectPublicId);
    }

    public function submit(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->islamic->submitApprovalWorkflow($request, $subjectType, $subjectPublicId);
    }

    public function approve(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->islamic->approveApprovalWorkflow($request, $subjectType, $subjectPublicId);
    }

    public function reject(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->islamic->rejectApprovalWorkflow($request, $subjectType, $subjectPublicId);
    }

    public function suspend(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->islamic->suspendApprovalWorkflow($request, $subjectType, $subjectPublicId);
    }

    public function revoke(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->islamic->revokeApprovalWorkflow($request, $subjectType, $subjectPublicId);
    }

    public function expire(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->islamic->expireApprovalWorkflow($request, $subjectType, $subjectPublicId);
    }

    public function archive(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->islamic->archiveApprovalWorkflow($request, $subjectType, $subjectPublicId);
    }
}
