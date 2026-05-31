<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CollateralController;
use App\Http\Controllers\Api\V1\DelinquencyTrackingController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\LoanGuaranteeObligationController;
use App\Http\Controllers\Api\V1\LoanProductController;
use App\Http\Controllers\Api\V1\LoanRecoveryController;
use App\Http\Controllers\Api\V1\LoanTransferController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('loan-products', [LoanProductController::class, 'index']);
    Route::post('loan-products', [LoanProductController::class, 'store']);
    Route::get('loan-products/{loanProduct}', [LoanProductController::class, 'show']);
    Route::patch('loan-products/{loanProduct}', [LoanProductController::class, 'update']);
    Route::delete('loan-products/{loanProduct}', [LoanProductController::class, 'destroy']);

    Route::get('loans', [LoanController::class, 'index']);
    Route::post('loans', [LoanController::class, 'store']);
    Route::get('loans/{loan}', [LoanController::class, 'show']);
    Route::patch('loans/{loan}', [LoanController::class, 'update']);
    Route::patch('loans/{loan}/linked-accounts', [LoanController::class, 'updateLinkedAccounts']);
    Route::post('loans/{loan}/setup-charges/assess', [LoanController::class, 'assessSetupCharges']);
    Route::post('loans/{loan}/setup-charges/{chargePublicId}/collect', [LoanController::class, 'collectSetupCharge']);
    Route::post('loans/{loan}/insurance-premiums/{premiumPublicId}/collect', [LoanController::class, 'collectInsurancePremium']);
    Route::post('loans/{loan}/setup-charges/{chargePublicId}/direction-decision', [LoanController::class, 'decideSetupChargeException']);
    Route::get('loans/{loan}/approvals', [LoanController::class, 'listApprovals']);
    Route::post('loans/{loan}/approvals/{step}', [LoanController::class, 'decideApproval']);
    Route::post('loans/{loan}/status-transitions', [LoanController::class, 'transitionStatus']);
    Route::post('loans/{loan}/disburse', [LoanController::class, 'disburse']);
    Route::post('loans/{loan}/repayments', [LoanController::class, 'repay']);
    Route::post('loans/{loan}/arrears/assess', [LoanController::class, 'assessArrears']);
    Route::post('loans/{loan}/early-repayment', [LoanController::class, 'earlyRepay']);
    Route::get('loans/{loan}/schedule', [LoanController::class, 'showSchedule']);
    Route::post('loans/{loan}/schedule/generate', [LoanController::class, 'generateSchedule']);
    Route::post('loans/{loan}/schedule/reschedule', [LoanController::class, 'reschedule']);

    Route::get('loans/{loan}/delinquency-trackings', [DelinquencyTrackingController::class, 'index']);
    Route::post('loans/{loan}/delinquency-trackings', [DelinquencyTrackingController::class, 'store']);
    Route::patch('loans/{loan}/delinquency-trackings/{delinquencyTracking}', [DelinquencyTrackingController::class, 'update']);

    Route::get('loans/{loan}/transfers', [LoanTransferController::class, 'index']);
    Route::post('loans/{loan}/transfers', [LoanTransferController::class, 'store']);

    Route::get('loans/{loan}/recovery-attempts', [LoanRecoveryController::class, 'index']);
    Route::post('loans/{loan}/recovery-attempts', [LoanRecoveryController::class, 'store']);

    Route::get('loans/{loan}/collaterals', [CollateralController::class, 'index']);
    Route::post('loans/{loan}/collaterals', [CollateralController::class, 'store']);
    Route::patch('loans/{loan}/collaterals/{collateral}', [CollateralController::class, 'update']);
    Route::post('loans/{loan}/collaterals/{collateral}/release', [CollateralController::class, 'release']);
    Route::post('loans/{loan}/collaterals/{collateral}/items', [CollateralController::class, 'storeItem']);
    Route::patch('loans/{loan}/collaterals/{collateral}/items/{item}', [CollateralController::class, 'updateItem']);

    Route::get('loans/{loan}/guarantee-obligations', [LoanGuaranteeObligationController::class, 'index']);
    Route::post('loans/{loan}/guarantee-obligations', [LoanGuaranteeObligationController::class, 'store']);
    Route::patch('loans/{loan}/guarantee-obligations/{obligation}', [LoanGuaranteeObligationController::class, 'update']);
    Route::post('loans/{loan}/guarantee-obligations/{obligation}/release', [LoanGuaranteeObligationController::class, 'release']);
});
