<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Loans\LoanWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\UpdateLoanLinkedAccountsRequest;
use App\Http\Requests\UpdateLoanRequest;
use App\Models\Loan;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoanController extends BaseController
{
    public function __construct(
        private readonly LoanWorkflowControllerAdapter $loan,
    ) {}

    #[QueryParameter('filter[credit_agent_public_id]', 'Limit results to loans assigned to a credit agent public ID.', type: 'string')]
    #[QueryParameter('filter[status]', 'Bracketed alias for top-level status.', type: 'string')]
    #[QueryParameter('filter[in_arrears]', 'When true, return only loans with overdue unpaid schedule exposure.', type: 'boolean')]
    #[QueryParameter('filter[par_bucket]', 'Non-cumulative PAR band filter: 30, 60, or 90. Requires arrears context.', type: 'integer')]
    #[QueryParameter('filter[as_of_date]', 'As-of date for delinquency projection (YYYY-MM-DD). Defaults to today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[awaiting_disbursement]', 'When true, return only approved loans ready for principal disbursement.', type: 'boolean')]
    #[QueryParameter('agency_public_id', 'Institution-scope readers may limit results to an agency public ID.', type: 'string')]
    #[QueryParameter('currency', 'Limit results to a currency code.', type: 'string')]
    #[QueryParameter('per_page', 'Results per page. Capped at 100.', type: 'integer')]
    public function stats(Request $request): JsonResponse
    {
        return $this->loan->stats($request);
    }

    #[QueryParameter('filter[credit_agent_public_id]', 'Limit results to loans assigned to a credit agent public ID.', type: 'string')]
    #[QueryParameter('status', 'Loan status filter.', type: 'string')]
    #[QueryParameter('filter[status]', 'Bracketed alias for top-level status.', type: 'string')]
    #[QueryParameter('filter[in_arrears]', 'When true, return only loans with overdue unpaid schedule exposure.', type: 'boolean')]
    #[QueryParameter('filter[par_bucket]', 'Non-cumulative PAR band filter: 30, 60, or 90. Requires arrears context.', type: 'integer')]
    #[QueryParameter('filter[as_of_date]', 'As-of date for delinquency projection (YYYY-MM-DD). Defaults to today.', type: 'string', format: 'date')]
    #[QueryParameter('filter[awaiting_disbursement]', 'When true, return only approved loans ready for principal disbursement.', type: 'boolean')]
    #[QueryParameter('agency_public_id', 'Institution-scope readers may limit results to an agency public ID.', type: 'string')]
    #[QueryParameter('currency', 'Limit results to a currency code.', type: 'string')]
    #[QueryParameter('per_page', 'Results per page. Capped at 100.', type: 'integer')]
    public function index(Request $request): JsonResponse
    {
        return $this->loan->index($request);
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        return $this->loan->store($request);
    }

    /**
     * Show a loan. Pass include_setup_charges=true to embed the reloadable
     * setup-charge readiness state under `setup_charges`, using the same
     * serializer as GET /loans/{loan}/setup-charges (FBI2-030).
     */
    #[QueryParameter('include_setup_charges', 'Embed the setup-charge readiness state under data.setup_charges.', type: 'boolean')]
    public function show(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->show($request, $loan);
    }

    /**
     * Reloadable loan setup-charge state (FBI2-030).
     *
     * Returns readiness status, assessed charges (with collectable /
     * blocking_disbursement / waiver_decision flags) and the required next
     * actions before disbursement. Shares the readiness rules enforced by loan
     * disbursement.
     */
    public function setupCharges(Request $request, Loan $loan): JsonResponse
    {
        return $this->loan->showSetupCharges($request, $loan);
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
