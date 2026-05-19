<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api', 'api.version'])->group(function (): void {
    Route::get('health', fn () => ApiResponse::success(
        data: [
            'status' => 'ok',
            'service' => config('app.name', 'habis-finance-api'),
            'version' => '1.0.0',
            'timestamp' => now()->toIso8601String(),
        ],
        message: 'Service is healthy',
    ));

    require __DIR__.'/api/v1/auth.php';
    require __DIR__.'/api/v1/accounting.php';
    require __DIR__.'/api/v1/credit.php';
    require __DIR__.'/api/v1/insurance.php';
    require __DIR__.'/api/v1/notifications.php';
});
