<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinancingWorkflow;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicIjaraController extends BaseController
{
    public function __construct(private readonly IslamicFinancingWorkflow $workflow) {}

    public function storeConditionReport(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->workflow->storeIjaraConditionReport($request, $financingPublicId);
    }

    public function storeRentalSchedules(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->workflow->storeIjaraRentalSchedules($request, $financingPublicId);
    }

    public function activateLease(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->workflow->activateIjaraLease($request, $financingPublicId);
    }

    public function storeDamageEvent(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->workflow->storeIjaraDamageEvent($request, $financingPublicId);
    }

    public function storeSuspension(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->workflow->storeIjaraSuspension($request, $financingPublicId);
    }

    public function storeEarlyTermination(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->workflow->storeIjaraEarlyTermination($request, $financingPublicId);
    }

    public function requestTransfer(Request $request, string $financingPublicId, string $assetPublicId): JsonResponse
    {
        return $this->workflow->requestIjaraTransfer($request, $financingPublicId, $assetPublicId);
    }

    public function approveTransfer(Request $request, string $transferEventPublicId): JsonResponse
    {
        return $this->workflow->approveIjaraTransfer($request, $transferEventPublicId);
    }

    public function postTransfer(Request $request, string $transferEventPublicId): JsonResponse
    {
        return $this->workflow->postIjaraTransfer($request, $transferEventPublicId);
    }

    public function showTransferEvent(Request $request, string $transferEventPublicId): JsonResponse
    {
        return $this->workflow->showIjaraTransferEvent($request, $transferEventPublicId);
    }
}
