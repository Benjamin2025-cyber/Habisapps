<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinanceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicMourabahaOriginationController extends BaseController
{
    public function __construct(private readonly IslamicFinanceWorkflowControllerAdapter $islamic) {}

    public function storeMourabahaRequest(Request $request): JsonResponse
    {
        return $this->islamic->storeMourabahaRequest($request);
    }

    public function storeMourabahaQuote(Request $request, string $requestPublicId): JsonResponse
    {
        return $this->islamic->storeMourabahaQuote($request, $requestPublicId);
    }

    public function approveMourabahaPurchase(Request $request, string $requestPublicId): JsonResponse
    {
        return $this->islamic->approveMourabahaPurchase($request, $requestPublicId);
    }

    public function storePurchaseEvidence(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storePurchaseEvidence($request, $financingPublicId);
    }

    public function storeCostEvidence(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeCostEvidence($request, $financingPublicId);
    }

    public function showOriginationSnapshot(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->showOriginationSnapshot($request, $financingPublicId);
    }

    public function storeCollection(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeCollection($request, $financingPublicId);
    }

    public function storeRebate(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeRebate($request, $financingPublicId);
    }

    public function storeCancellation(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeCancellation($request, $financingPublicId);
    }

    public function storeDefaultTreatment(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeDefaultTreatment($request, $financingPublicId);
    }

    public function storeReversal(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeReversal($request, $financingPublicId);
    }

    public function storeCorrection(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeCorrection($request, $financingPublicId);
    }

    public function showReceivableLedger(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->showReceivableLedger($request, $financingPublicId);
    }
}
