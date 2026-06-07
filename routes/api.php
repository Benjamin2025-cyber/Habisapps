<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['api', 'api.version'])->group(function (): void {
    Route::get('health', function (Request $request): JsonResponse {
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max($request->integer('per_page', 1), 1), 1);

        return ApiResponse::success(
            data: [
                'status' => 'ok',
                'service' => config('app.name', 'habis-finance-api'),
                'version' => '1.0.0',
                'timestamp' => now()->toIso8601String(),
            ],
            message: __('api.service_healthy'),
            meta: [
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => 1,
                    'last_page' => 1,
                ],
            ],
        );
    });

    require __DIR__.'/api/v1/auth.php';
    require __DIR__.'/api/v1/accounting.php';
    require __DIR__.'/api/v1/credit.php';
    require __DIR__.'/api/v1/insurance.php';
    require __DIR__.'/api/v1/notifications.php';
    require __DIR__.'/api/v1/currency_exchange.php';
    require __DIR__.'/api/v1/regulatory_reporting.php';
    require __DIR__.'/api/v1/dashboards.php';
    require __DIR__.'/api/v1/hr_payroll.php';
    require __DIR__.'/api/v1/islamic_finance.php';
    require __DIR__.'/api/v1/database_management.php';
});
