<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Loans\LoanWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\UpdateLoanLinkedAccountsRequest;
use App\Http\Requests\UpdateLoanRequest;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoanController extends BaseController
{
    public function __construct(
        private readonly LoanWorkflowControllerAdapter $loan,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->loan->index($request);
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        return $this->loan->store($request);
    }

    public function show(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->show($request, $loan);
    }

    public function update(UpdateLoanRequest $request, Loan $loan): JsonResponse
    {
        return $this->loan->update($request, $loan);
    }

    public function updateLinkedAccounts(UpdateLoanLinkedAccountsRequest $request, Loan $loan): JsonResponse
    {
        return $this->loan->updateLinkedAccounts($request, $loan);
    }

    public function assessSetupCharges(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->assessSetupCharges($request, $loan);
    }

    public function decideSetupChargeException(Request $request, Loan $loan, string $chargePublicId): JsonResponse
    {
        return $this->loan->decideSetupChargeException($request, $loan, $chargePublicId);
    }

    public function collectSetupCharge(Request $request, Loan $loan, string $chargePublicId): JsonResponse
    {
        return $this->loan->collectSetupCharge($request, $loan, $chargePublicId);
    }

    public function collectInsurancePremium(Request $request, Loan $loan, string $premiumPublicId): JsonResponse
    {
        return $this->loan->collectInsurancePremium($request, $loan, $premiumPublicId);
    }

    public function listApprovals(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->listApprovals($request, $loan);
    }

    public function decideApproval(Request $request, Loan $loan, string $step): JsonResponse
    {
        return $this->loan->decideApproval($request, $loan, $step);
    }

    public function showSchedule(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->showActiveSchedule($request, $loan);
    }

    public function transitionStatus(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->transitionStatus($request, $loan);
    }

    public function generateSchedule(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->generateSchedule($request, $loan);
    }

    public function reschedule(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->reschedule($request, $loan);
    }

    public function disburse(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->disburse($request, $loan);
    }

    public function repay(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->repay($request, $loan);
    }

    public function assessArrears(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->assessArrears($request, $loan);
    }

    public function earlyRepay(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->earlyRepay($request, $loan);
    }
}
