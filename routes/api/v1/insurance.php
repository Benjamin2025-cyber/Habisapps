<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\InsuranceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Partners & products
    Route::post('insurance-partners', [InsuranceController::class, 'storePartner']);
    Route::post('insurance-products', [InsuranceController::class, 'storeProduct']);
    Route::post('insurance-products/{productPublicId}/activate', [InsuranceController::class, 'activateProduct']);
    Route::post('insurance-products/{productPublicId}/rule-versions', [InsuranceController::class, 'storeProductRuleVersion']);
    Route::post('insurance-products/{productPublicId}/evidence-requirements', [InsuranceController::class, 'storeClaimEvidenceConfig']);
    Route::post('insurance-product-rule-versions/{versionPublicId}/approve', [InsuranceController::class, 'approveProductRuleVersion']);

    // Subscriptions & lifecycle
    Route::post('insurance-subscriptions', [InsuranceController::class, 'storeSubscription']);
    Route::post('insurance-subscriptions/{subscriptionPublicId}/activate', [InsuranceController::class, 'activateSubscription']);
    Route::post('insurance-subscriptions/{subscriptionPublicId}/renew', [InsuranceController::class, 'renewSubscription']);
    Route::post('insurance-subscriptions/{subscriptionPublicId}/endorsements', [InsuranceController::class, 'storeEndorsement']);
    Route::post('insurance-subscriptions/{subscriptionPublicId}/cancel', [InsuranceController::class, 'cancelSubscription']);
    Route::post('insurance-endorsements/{endorsementPublicId}/review', [InsuranceController::class, 'approveEndorsement']);
    Route::post('insurance-cancellations/{cancellationPublicId}/review', [InsuranceController::class, 'reviewCancellation']);

    // Premiums
    Route::post('insurance-subscriptions/{subscriptionPublicId}/premium-assessments', [InsuranceController::class, 'storePremiumAssessment']);
    Route::post('insurance-premium-assessments/{assessmentPublicId}/collect-from-account', [InsuranceController::class, 'collectPremiumFromAccount']);
    Route::post('insurance-premium-assessments/{assessmentPublicId}/collect-cash', [InsuranceController::class, 'collectPremiumCash']);
    Route::post('insurance-premium-payments/{paymentPublicId}/reverse', [InsuranceController::class, 'reversePremiumPayment']);
    Route::post('insurance-premium-batch-generate', [InsuranceController::class, 'generatePremiumBatch']);

    // Claims
    Route::post('insurance-claims', [InsuranceController::class, 'storeClaim']);
    Route::post('insurance-claims/{claimPublicId}/documents', [InsuranceController::class, 'attachClaimDocument']);
    Route::post('insurance-claims/{claimPublicId}/decision', [InsuranceController::class, 'decideClaim']);
    Route::post('insurance-claims/{claimPublicId}/decision-requests', [InsuranceController::class, 'requestClaimDecision']);
    Route::post('insurance-claim-decisions/{decisionPublicId}/review', [InsuranceController::class, 'reviewClaimDecision']);
    Route::post('insurance-claims/{claimPublicId}/settlement-posting', [InsuranceController::class, 'postClaimSettlement']);
    Route::post('insurance-claims/{claimPublicId}/settlement-reversal', [InsuranceController::class, 'reverseClaimSettlement']);

    // Remittances
    Route::post('insurance-remittance-batches', [InsuranceController::class, 'storeRemittanceBatch']);
    Route::post('insurance-remittance-batches/{batchPublicId}/approve', [InsuranceController::class, 'approveRemittanceBatch']);

    // Reports (A7 + A11)
    Route::get('insurance-reports/active-subscriptions', [InsuranceController::class, 'activeSubscriptionsReport']);
    Route::get('insurance-reports/premiums', [InsuranceController::class, 'premiumsReport']);
    Route::get('insurance-reports/unpaid-premiums', [InsuranceController::class, 'unpaidPremiumsReport']);
    Route::get('insurance-reports/claims', [InsuranceController::class, 'claimsReport']);
    Route::get('insurance-reports/expiring-coverage', [InsuranceController::class, 'expiringCoverageReport']);
    Route::get('insurance-reports/commissions', [InsuranceController::class, 'commissionsReport']);
    Route::get('insurance-reports/remittances', [InsuranceController::class, 'remittancesReport']);
    Route::get('insurance-reports/loss-ratio', [InsuranceController::class, 'lossRatioReport']);
    Route::get('insurance-reports/cancellations-refunds', [InsuranceController::class, 'cancellationsRefundsReport']);

    // Exports (A13)
    Route::get('insurance-exports/subscriptions', [InsuranceController::class, 'exportSubscriptions']);
    Route::get('insurance-exports/premiums', [InsuranceController::class, 'exportPremiums']);
    Route::get('insurance-exports/claims', [InsuranceController::class, 'exportClaims']);
    Route::get('insurance-exports/commissions', [InsuranceController::class, 'exportCommissions']);
    Route::get('insurance-exports/remittances', [InsuranceController::class, 'exportRemittances']);
    Route::get('insurance-exports/cancellations-refunds', [InsuranceController::class, 'exportCancellationsRefunds']);
});
