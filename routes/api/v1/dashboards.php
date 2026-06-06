<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'accounting.day.registration-lock'])->group(function (): void {
    Route::get('dashboards/operational', [DashboardController::class, 'operational']);
    Route::get('dashboards/operational/timeseries', [DashboardController::class, 'timeseries']);
    Route::get('dashboards/agencies-performance', [DashboardController::class, 'agenciesPerformance']);
    Route::get('dashboards/loan-officer', [DashboardController::class, 'loanOfficer']);
    Route::get('dashboards/kyc-officer', [DashboardController::class, 'kycOfficer']);
    Route::get('dashboards/accountant', [DashboardController::class, 'accountant']);
    Route::get('dashboards/regional', [DashboardController::class, 'regional']);
    Route::get('dashboards/executive', [DashboardController::class, 'executive']);
});
