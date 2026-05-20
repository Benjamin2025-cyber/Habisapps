<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Loans\DelinquencyTrackingWorkflow;
use App\Http\Controllers\BaseController;
use App\Models\DelinquencyTracking;
use App\Models\Loan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DelinquencyTrackingController extends BaseController
{
    public function __construct(
        private readonly DelinquencyTrackingWorkflow $workflow,
    ) {}

    public function index(Request $request, Loan $loan): JsonResponse
    {
        return $this->workflow->index($request, $loan);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        return $this->workflow->store($request, $loan);
    }

    public function update(Request $request, Loan $loan, DelinquencyTracking $delinquencyTracking): JsonResponse
    {
        return $this->workflow->update($request, $loan, $delinquencyTracking);
    }
}
