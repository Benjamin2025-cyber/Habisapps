<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationConsentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'accounting.day.registration-lock'])->group(function (): void {
    Route::post('clients/{clientPublicId}/notification-consents', [NotificationConsentController::class, 'store']);
});
