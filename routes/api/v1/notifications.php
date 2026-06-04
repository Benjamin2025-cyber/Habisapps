<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\NotificationConsentController;
use App\Http\Controllers\Api\V1\UserNotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'accounting.day.registration-lock'])->group(function (): void {
    Route::get('notifications', [UserNotificationController::class, 'index']);

    // User self-service read-state: marking one's own notifications read is part
    // of consulting data and carries no accounting-day stake, so it is
    // allowlisted out of the registration lock (stays available in
    // consultation-only mode after a day closes).
    Route::post('notifications/read-all', [UserNotificationController::class, 'readAll'])
        ->defaults('accounting_day_classification', 'administration');
    Route::post('notifications/{notification}/read', [UserNotificationController::class, 'read'])
        ->defaults('accounting_day_classification', 'administration');

    // Notification consents create a customer-facing record, so they remain a
    // registration write gated by the accounting-day lock.
    Route::post('clients/{clientPublicId}/notification-consents', [NotificationConsentController::class, 'store']);
});
