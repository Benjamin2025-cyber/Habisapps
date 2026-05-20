<?php

declare(strict_types=1);

namespace App\Application\Hr;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HrWorkflowControllerAdapter
{
    public function __construct(
        private readonly HrEmployeeWorkflow $employee,
        private readonly HrLeaveWorkflow $leave,
    ) {}

    public function storeEmployee(Request $request): JsonResponse
    {
        return $this->employee->storeEmployee($request);
    }

    public function attachEmployeeDocument(Request $request, string $employeePublicId): JsonResponse
    {
        return $this->employee->attachEmployeeDocument($request, $employeePublicId);
    }

    public function storeContractVersion(Request $request, string $employeePublicId): JsonResponse
    {
        return $this->employee->storeContractVersion($request, $employeePublicId);
    }

    public function storeLeaveRequest(Request $request, string $employeePublicId): JsonResponse
    {
        return $this->leave->storeLeaveRequest($request, $employeePublicId);
    }

    public function reviewLeaveRequest(Request $request, string $leavePublicId): JsonResponse
    {
        return $this->leave->reviewLeaveRequest($request, $leavePublicId);
    }
}
