<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Dashboard\DashboardWorkflow;
use App\Application\Dashboard\FieldRoleDashboardWorkflow;
use App\Http\Controllers\BaseController;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController extends BaseController
{
    public function __construct(
        private readonly DashboardWorkflow $dashboard,
        private readonly FieldRoleDashboardWorkflow $fieldRoleDashboard,
    ) {}

    public function operational(Request $request): JsonResponse
    {
        return $this->dashboard->operational($request);
    }

    public function executive(Request $request): JsonResponse
    {
        return $this->dashboard->executive($request);
    }

    public function timeseries(Request $request): JsonResponse
    {
        return $this->dashboard->timeseries($request);
    }

    public function agenciesPerformance(Request $request): JsonResponse
    {
        return $this->dashboard->agenciesPerformance($request);
    }

    #[QueryParameter('currency', 'Portfolio currency for loan-officer metrics (default XAF).', type: 'string')]
    public function loanOfficer(Request $request): JsonResponse
    {
        return $this->fieldRoleDashboard->loanOfficer($request);
    }

    public function kycOfficer(Request $request): JsonResponse
    {
        return $this->fieldRoleDashboard->kycOfficer($request);
    }

    #[QueryParameter('currency', 'Currency for awaiting-disbursement counts (default XAF).', type: 'string')]
    public function accountant(Request $request): JsonResponse
    {
        return $this->fieldRoleDashboard->accountant($request);
    }

    #[QueryParameter('currency', 'Portfolio currency for regional metrics (default XAF).', type: 'string')]
    public function regional(Request $request): JsonResponse
    {
        return $this->fieldRoleDashboard->regional($request);
    }
}
