<?php

declare(strict_types=1);

namespace App\Application\FxExchange;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FxWorkflowControllerAdapter extends BaseController
{
    public function __construct(
        private readonly FxSetupWorkflow $fxSetupWorkflow,
        private readonly FxRateWorkflow $fxRateWorkflow,
        private readonly FxTransactionWorkflow $fxTransactionWorkflow,
        private readonly FxStockWorkflow $fxStockWorkflow,
    ) {}

    public function storeAuthorization(Request $request): JsonResponse
    {
        return $this->fxSetupWorkflow->storeAuthorization($request);
    }

    public function storeCurrency(Request $request): JsonResponse
    {
        return $this->fxSetupWorkflow->storeCurrency($request);
    }

    public function storeRateDraft(Request $request): JsonResponse
    {
        return $this->fxRateWorkflow->storeRateDraft($request);
    }

    public function approveRate(Request $request, string $ratePublicId): JsonResponse
    {
        return $this->fxRateWorkflow->approveRate($request, $ratePublicId);
    }

    public function storeExchangeTransaction(Request $request, string $tillPublicId): JsonResponse
    {
        return $this->fxTransactionWorkflow->storeExchangeTransaction($request, $tillPublicId);
    }

    public function reverseExchangeTransaction(Request $request, string $transactionPublicId): JsonResponse
    {
        return $this->fxTransactionWorkflow->reverseExchangeTransaction($request, $transactionPublicId);
    }

    public function storeStockMovement(Request $request, string $tillPublicId): JsonResponse
    {
        return $this->fxStockWorkflow->storeStockMovement($request, $tillPublicId);
    }

    public function approveStockMovement(Request $request, string $movementPublicId): JsonResponse
    {
        return $this->fxStockWorkflow->approveStockMovement($request, $movementPublicId);
    }

    public function storeReconciliation(Request $request, string $tillPublicId): JsonResponse
    {
        return $this->fxStockWorkflow->storeReconciliation($request, $tillPublicId);
    }

    public function register(Request $request): JsonResponse
    {
        return $this->fxStockWorkflow->register($request);
    }
}
