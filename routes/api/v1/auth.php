<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth.login');
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth.register');
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
