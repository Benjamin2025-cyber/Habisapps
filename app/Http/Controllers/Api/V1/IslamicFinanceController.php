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

    public function indexStandards(Request $request): JsonResponse
    {
        return $this->islamic->indexStandards($request);
    }

    public function storeStandard(Request $request): JsonResponse
    {
        return $this->islamic->storeStandard($request);
    }

    public function showStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->showStandard($request, $standardPublicId);
    }

    public function updateStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->updateStandard($request, $standardPublicId);
    }

    public function amendStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->amendStandard($request, $standardPublicId);
    }

    public function activateStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->activateStandard($request, $standardPublicId);
    }

    public function retireStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->retireStandard($request, $standardPublicId);
    }

    public function linkStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->linkStandard($request, $standardPublicId);
    }

    public function unlinkStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->unlinkStandard($request, $standardPublicId);
    }
}
