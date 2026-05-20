<?php

declare(strict_types=1);

namespace App\Application\HrPayroll;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HrPayrollWorkflowControllerAdapter
{
    public function __construct(
        private readonly HrFormulaSetWorkflow $formulaSet,
        private readonly HrPayrollRunWorkflow $payrollRun,
    ) {}

    public function storeFormulaSet(Request $request): JsonResponse
    {
        return $this->formulaSet->storeFormulaSet($request);
    }

    public function activateFormulaSet(Request $request, string $setPublicId): JsonResponse
    {
        return $this->formulaSet->activateFormulaSet($request, $setPublicId);
    }

    public function storePayrollRun(Request $request): JsonResponse
    {
        return $this->payrollRun->storePayrollRun($request);
    }

    public function storeCorrectionPayrollRun(Request $request, string $runPublicId): JsonResponse
    {
        return $this->payrollRun->storeCorrectionPayrollRun($request, $runPublicId);
    }

    public function approvePayrollRun(Request $request, string $runPublicId): JsonResponse
    {
        return $this->payrollRun->approvePayrollRun($request, $runPublicId);
    }

    public function declarationExport(Request $request, string $runPublicId): JsonResponse
    {
        return $this->payrollRun->declarationExport($request, $runPublicId);
    }
}
