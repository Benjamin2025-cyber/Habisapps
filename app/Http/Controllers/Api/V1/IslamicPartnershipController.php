<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicPartnershipWorkflow;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicPartnershipController extends BaseController
{
    public function __construct(private readonly IslamicPartnershipWorkflow $workflow) {}

    public function store(Request $request): JsonResponse
    {
        return $this->workflow->storePartnership($request);
    }

    public function show(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->showPartnership($request, $partnershipPublicId);
    }

    public function addPartner(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->addPartner($request, $partnershipPublicId);
    }

    public function storeContribution(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->storeContribution($request, $partnershipPublicId);
    }

    public function activate(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->activatePartnership($request, $partnershipPublicId);
    }

    public function storeReport(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->storeReport($request, $partnershipPublicId);
    }

    public function storeProfitDeclaration(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->storeProfitDeclaration($request, $partnershipPublicId);
    }

    public function storeLoss(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->storeLoss($request, $partnershipPublicId);
    }

    public function storeValuation(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->storeValuation($request, $partnershipPublicId);
    }

    public function storeBuyout(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->storeBuyout($request, $partnershipPublicId);
    }

    public function liquidate(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->liquidatePartnership($request, $partnershipPublicId);
    }

    public function timeline(Request $request, string $partnershipPublicId): JsonResponse
    {
        return $this->workflow->showTimeline($request, $partnershipPublicId);
    }
}
