<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicFinanceWorkflowControllerAdapter
{
    public function __construct(
        private readonly IslamicProductWorkflow $product,
        private readonly IslamicFinancingWorkflow $financing,
        private readonly IslamicStandardWorkflow $standard,
    ) {}

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->product->storeProduct($request);
    }

    public function storeComplianceReview(Request $request, string $productPublicId): JsonResponse
    {
        return $this->product->storeComplianceReview($request, $productPublicId);
    }

    public function reviewCompliance(Request $request, string $reviewPublicId): JsonResponse
    {
        return $this->product->reviewCompliance($request, $reviewPublicId);
    }

    public function storeFinancing(Request $request): JsonResponse
    {
        return $this->financing->storeFinancing($request);
    }

    public function storeFinancingAsset(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeFinancingAsset($request, $financingPublicId);
    }

    public function storeInstallments(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeInstallments($request, $financingPublicId);
    }

    public function approveFinancing(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->approveFinancing($request, $financingPublicId);
    }

    public function indexStandards(Request $request): JsonResponse
    {
        return $this->standard->index($request);
    }

    public function storeStandard(Request $request): JsonResponse
    {
        return $this->standard->store($request);
    }

    public function showStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->show($request, $standardPublicId);
    }

    public function updateStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->updateDraft($request, $standardPublicId);
    }

    public function amendStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->amend($request, $standardPublicId);
    }

    public function activateStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->activate($request, $standardPublicId);
    }

    public function retireStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->retire($request, $standardPublicId);
    }

    public function linkStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->link($request, $standardPublicId);
    }

    public function unlinkStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->unlink($request, $standardPublicId);
    }
}
