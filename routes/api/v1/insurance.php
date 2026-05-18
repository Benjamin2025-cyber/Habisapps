<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\InsuranceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('insurance-partners', [InsuranceController::class, 'storePartner']);
    Route::post('insurance-products', [InsuranceController::class, 'storeProduct']);
    Route::post('insurance-subscriptions', [InsuranceController::class, 'storeSubscription']);
    Route::post('insurance-subscriptions/{subscriptionPublicId}/premium-assessments', [InsuranceController::class, 'storePremiumAssessment']);
    Route::post('insurance-premium-assessments/{assessmentPublicId}/collect-from-account', [InsuranceController::class, 'collectPremiumFromAccount']);
    Route::post('insurance-premium-assessments/{assessmentPublicId}/collect-cash', [InsuranceController::class, 'collectPremiumCash']);
    Route::post('insurance-claims', [InsuranceController::class, 'storeClaim']);
    Route::post('insurance-claims/{claimPublicId}/documents', [InsuranceController::class, 'attachClaimDocument']);
    Route::post('insurance-claims/{claimPublicId}/decision', [InsuranceController::class, 'decideClaim']);
    Route::post('insurance-claims/{claimPublicId}/decision-requests', [InsuranceController::class, 'requestClaimDecision']);
    Route::post('insurance-claim-decisions/{decisionPublicId}/review', [InsuranceController::class, 'reviewClaimDecision']);
    Route::post('insurance-claims/{claimPublicId}/settlement-posting', [InsuranceController::class, 'postClaimSettlement']);

    Route::get('insurance-reports/active-subscriptions', [InsuranceController::class, 'activeSubscriptionsReport']);
    Route::get('insurance-reports/premiums', [InsuranceController::class, 'premiumsReport']);
    Route::get('insurance-reports/unpaid-premiums', [InsuranceController::class, 'unpaidPremiumsReport']);
    Route::get('insurance-reports/claims', [InsuranceController::class, 'claimsReport']);
    Route::get('insurance-reports/expiring-coverage', [InsuranceController::class, 'expiringCoverageReport']);
});
