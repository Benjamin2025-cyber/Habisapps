<?php

declare(strict_types=1);

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api')->group(function (): void {
    Route::get('health', function (Request $request): JsonResponse {
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max($request->integer('per_page', 1), 1), 1);

        return ApiResponse::success([
            'status' => 'ok',
            'service' => config('app.name'),
            'version' => '1.0.0',
        ], __('api.service_healthy'), [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => 1,
                'last_page' => 1,
            ],
        ]);
    });

    require __DIR__.'/auth.php';
});
