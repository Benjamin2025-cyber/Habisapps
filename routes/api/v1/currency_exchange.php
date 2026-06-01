<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CurrencyExchangeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'accounting.day.registration-lock'])->group(function (): void {
    Route::post('fx-authorizations', [CurrencyExchangeController::class, 'storeAuthorization']);
    Route::post('currencies', [CurrencyExchangeController::class, 'storeCurrency']);
    Route::post('exchange-rates', [CurrencyExchangeController::class, 'storeRateDraft']);
    Route::post('exchange-rates/{ratePublicId}/approve', [CurrencyExchangeController::class, 'approveRate']);
    Route::post('fx-tills/{tillPublicId}/exchange-transactions', [CurrencyExchangeController::class, 'storeExchangeTransaction']);
    Route::post('fx-transactions/{transactionPublicId}/reversal', [CurrencyExchangeController::class, 'reverseExchangeTransaction']);
    Route::post('fx-tills/{tillPublicId}/stock-movements', [CurrencyExchangeController::class, 'storeStockMovement']);
    Route::post('fx-stock-movements/{movementPublicId}/approve', [CurrencyExchangeController::class, 'approveStockMovement']);
    Route::post('fx-tills/{tillPublicId}/reconciliations', [CurrencyExchangeController::class, 'storeReconciliation']);
    Route::get('fx-register', [CurrencyExchangeController::class, 'register']);
});
