<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\UpdateLoanRequest;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoanWorkflowControllerAdapter
{
    public function __construct(
        private readonly LoanCrudWorkflow $crud,
        private readonly LoanSetupChargeWorkflow $setupCharge,
        private readonly LoanApprovalWorkflow $approval,
        private readonly LoanScheduleWorkflow $schedule,
        private readonly LoanRepaymentWorkflow $repayment,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->crud->index($request);
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        return $this->crud->store($request);
    }

    public function show(Request $request, Loan $loan): JsonResponse
    {
        return $this->crud->show($request, $loan);
    }

    public function update(UpdateLoanRequest $request, Loan $loan): JsonResponse
    {
        return $this->crud->update($request, $loan);
    }

    public function assessSetupCharges(Request $request, Loan $loan): JsonResponse
    {
        return $this->setupCharge->assessSetupCharges($request, $loan);
    }

    public function decideSetupChargeException(Request $request, Loan $loan, string $chargePublicId): JsonResponse
    {
        return $this->setupCharge->decideSetupChargeException($request, $loan, $chargePublicId);
    }

    public function collectSetupCharge(Request $request, Loan $loan, string $chargePublicId): JsonResponse
    {
        return $this->setupCharge->collectSetupCharge($request, $loan, $chargePublicId);
    }

    public function collectInsurancePremium(Request $request, Loan $loan, string $premiumPublicId): JsonResponse
    {
        return $this->setupCharge->collectInsurancePremium($request, $loan, $premiumPublicId);
    }

    public function decideApproval(Request $request, Loan $loan, string $step): JsonResponse
    {
        return $this->approval->decideApproval($request, $loan, $step);
    }

    public function transitionStatus(Request $request, Loan $loan): JsonResponse
    {
        return $this->approval->transitionStatus($request, $loan);
    }

    public function generateSchedule(Request $request, Loan $loan): JsonResponse
    {
        return $this->schedule->generateSchedule($request, $loan);
    }

    public function reschedule(Request $request, Loan $loan): JsonResponse
    {
        return $this->schedule->reschedule($request, $loan);
    }

    public function disburse(Request $request, Loan $loan): JsonResponse
    {
        return $this->repayment->disburse($request, $loan);
    }

    public function repay(Request $request, Loan $loan): JsonResponse
    {
        return $this->repayment->repay($request, $loan);
    }

    public function assessArrears(Request $request, Loan $loan): JsonResponse
    {
        return $this->repayment->assessArrears($request, $loan);
    }

    public function earlyRepay(Request $request, Loan $loan): JsonResponse
    {
        return $this->repayment->earlyRepay($request, $loan);
    }
}
