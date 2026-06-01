<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HrController;
use App\Http\Controllers\Api\V1\HrPayrollController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'accounting.day.registration-lock'])->group(function (): void {
    // HR / employee lifecycle
    Route::post('hr-employees', [HrController::class, 'storeEmployee']);
    Route::post('hr-employees/{employeePublicId}/documents', [HrController::class, 'attachEmployeeDocument']);
    Route::post('hr-employees/{employeePublicId}/contracts', [HrController::class, 'storeContractVersion']);
    Route::post('hr-employees/{employeePublicId}/leave-requests', [HrController::class, 'storeLeaveRequest']);
    Route::post('hr-leave-requests/{leavePublicId}/review', [HrController::class, 'reviewLeaveRequest']);

    // Payroll
    Route::post('hr-payroll-formula-sets', [HrPayrollController::class, 'storeFormulaSet']);
    Route::post('hr-payroll-formula-sets/{setPublicId}/activate', [HrPayrollController::class, 'activateFormulaSet']);
    Route::post('hr-payroll-runs', [HrPayrollController::class, 'storePayrollRun']);
    Route::post('hr-payroll-runs/{runPublicId}/corrections', [HrPayrollController::class, 'storeCorrectionPayrollRun']);
    Route::post('hr-payroll-runs/{runPublicId}/approve', [HrPayrollController::class, 'approvePayrollRun']);
    Route::get('hr-payroll-runs/{runPublicId}/declaration-export', [HrPayrollController::class, 'declarationExport']);
});
