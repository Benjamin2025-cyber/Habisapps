<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationConsentController;
use App\Http\Controllers\Api\V1\UserNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'accounting.day.registration-lock'])->group(function (): void {
    Route::get('notifications', [UserNotificationController::class, 'index']);
    Route::post('notifications/read-all', [UserNotificationController::class, 'readAll']);
    Route::post('notifications/{notification}/read', [UserNotificationController::class, 'read']);
    Route::post('clients/{clientPublicId}/notification-consents', [NotificationConsentController::class, 'store']);
});
