<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinanceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicFinanceController extends BaseController
{
    public function __construct(
        private readonly IslamicFinanceWorkflowControllerAdapter $islamic,
    ) {}

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->islamic->storeProduct($request);
    }

    public function storeComplianceReview(Request $request, string $productPublicId): JsonResponse
    {
        return $this->islamic->storeComplianceReview($request, $productPublicId);
    }

    public function reviewCompliance(Request $request, string $reviewPublicId): JsonResponse
    {
        return $this->islamic->reviewCompliance($request, $reviewPublicId);
    }

    public function storeFinancing(Request $request): JsonResponse
    {
        return $this->islamic->storeFinancing($request);
    }

    public function storeFinancingAsset(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeFinancingAsset($request, $financingPublicId);
    }

    public function storeInstallments(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeInstallments($request, $financingPublicId);
    }

    public function approveFinancing(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->approveFinancing($request, $financingPublicId);
    }

    public function listComplianceCases(Request $request): JsonResponse
    {
        return $this->islamic->listComplianceCases($request);
    }

    public function showComplianceCase(Request $request, string $casePublicId): JsonResponse
    {
        return $this->islamic->showComplianceCase($request, $casePublicId);
    }

    public function showComplianceCaseTimeline(Request $request, string $casePublicId): JsonResponse
    {
        return $this->islamic->showComplianceCaseTimeline($request, $casePublicId);
    }

    public function complianceCaseSummary(Request $request): JsonResponse
    {
        return $this->islamic->complianceCaseSummary($request);
    }
}
