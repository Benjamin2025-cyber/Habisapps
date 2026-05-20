<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\IslamicFinanceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Products and Sharia compliance
    Route::post('islamic-products', [IslamicFinanceController::class, 'storeProduct']);
    Route::post('islamic-products/{productPublicId}/compliance-reviews', [IslamicFinanceController::class, 'storeComplianceReview']);
    Route::post('islamic-compliance-reviews/{reviewPublicId}/review', [IslamicFinanceController::class, 'reviewCompliance']);

    // Murabaha financing
    Route::post('islamic-financings', [IslamicFinanceController::class, 'storeFinancing']);
    Route::post('islamic-financings/{financingPublicId}/assets', [IslamicFinanceController::class, 'storeFinancingAsset']);
    Route::post('islamic-financings/{financingPublicId}/installments', [IslamicFinanceController::class, 'storeInstallments']);
    Route::post('islamic-financings/{financingPublicId}/approve', [IslamicFinanceController::class, 'approveFinancing']);
});
