<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::post('login', fn () => response()->json([
    'success' => false,
    'message' => 'Not yet implemented.',
], 501));
Route::post('register', fn () => response()->json([
    'success' => false,
    'message' => 'Not yet implemented.',
], 501));
Route::post('logout', fn () => response()->json([
    'success' => false,
    'message' => 'Not yet implemented.',
], 501))->middleware('auth:sanctum');
