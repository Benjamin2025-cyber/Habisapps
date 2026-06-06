<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Dashboard\DashboardWorkflow;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController extends BaseController
{
    public function __construct(
        private readonly DashboardWorkflow $dashboard,
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
}
