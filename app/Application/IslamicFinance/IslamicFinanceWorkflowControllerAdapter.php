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
}
