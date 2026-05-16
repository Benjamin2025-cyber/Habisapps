<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\InsuranceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('insurance-partners', [InsuranceController::class, 'storePartner']);
    Route::post('insurance-products', [InsuranceController::class, 'storeProduct']);
    Route::post('insurance-subscriptions', [InsuranceController::class, 'storeSubscription']);
    Route::post('insurance-claims', [InsuranceController::class, 'storeClaim']);
    Route::post('insurance-claims/{claimPublicId}/decision', [InsuranceController::class, 'decideClaim']);
});
