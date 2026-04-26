<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\StaffUserController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth.login');
Route::post('activate', [AuthController::class, 'activate'])->middleware('throttle:auth.activation');
Route::post('activation/resend', [AuthController::class, 'resendActivationOtp'])->middleware('throttle:auth.activation');
Route::post('password/otp', [AuthController::class, 'requestPasswordResetOtp'])->middleware('throttle:auth.activation');
Route::post('password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth.activation');
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('staff-users', [StaffUserController::class, 'index']);
    Route::post('staff-users', [StaffUserController::class, 'store']);
    Route::get('staff-users/{staffUser}', [StaffUserController::class, 'show']);
    Route::patch('staff-users/{staffUser}', [StaffUserController::class, 'update']);
    Route::patch('staff-users/{staffUser}/status', [StaffUserController::class, 'updateStatus']);
    Route::put('staff-users/{staffUser}/roles', [StaffUserController::class, 'updateRoles']);
});
