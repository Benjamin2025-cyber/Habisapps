<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Loans\LoanTransferWorkflow;
use App\Http\Controllers\BaseController;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoanTransferController extends BaseController
{
    public function __construct(
        private readonly LoanTransferWorkflow $workflow,
    ) {}

    public function index(Request $request, Loan $loan): JsonResponse
    {
        return $this->workflow->index($request, $loan);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        return $this->workflow->store($request, $loan);
    }
}
