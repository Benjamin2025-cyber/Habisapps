<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('api')->group(function (): void {
    Route::get('health', fn () => response()->json([
        'status' => 'ok',
        'service' => config('app.name'),
        'version' => '1.0.0',
    ]));

    require __DIR__ . '/auth.php';
});
