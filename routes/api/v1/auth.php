<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AgencyController;
use App\Http\Controllers\Api\V1\AuditEventController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BatchProcedureController;
use App\Http\Controllers\Api\V1\BatchRunController;
use App\Http\Controllers\Api\V1\ClientController;
use App\Http\Controllers\Api\V1\ClientGuarantorController;
use App\Http\Controllers\Api\V1\ClientIdentityDocumentController;
use App\Http\Controllers\Api\V1\ClientProxyController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\ReferenceNumberController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StaffAssignmentController;
use App\Http\Controllers\Api\V1\StaffUserController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth.login');
Route::post('activate', [AuthController::class, 'activate'])->middleware('throttle:auth.activation');
Route::post('activation/resend', [AuthController::class, 'resendActivationOtp'])->middleware('throttle:auth.activation');
Route::post('password/otp', [AuthController::class, 'requestPasswordResetOtp'])->middleware('throttle:auth.activation');
Route::post('password/reset', [AuthController::class, 'resetPassword'])->middleware('throttle:auth.activation');
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('agencies', [AgencyController::class, 'index']);
    Route::post('agencies', [AgencyController::class, 'store']);
    Route::get('agencies/{agency}', [AgencyController::class, 'show']);
    Route::patch('agencies/{agency}', [AgencyController::class, 'update']);
    Route::patch('agencies/{agency}/status', [AgencyController::class, 'updateStatus']);
    Route::delete('agencies/{agency}', [AgencyController::class, 'destroy']);
    Route::put('agencies/{agency}/manager', [AgencyController::class, 'updateManager']);

    Route::get('staff-users', [StaffUserController::class, 'index']);
    Route::post('staff-users', [StaffUserController::class, 'store']);
    Route::get('staff-users/{staffUser}', [StaffUserController::class, 'show']);
    Route::patch('staff-users/{staffUser}', [StaffUserController::class, 'update']);
    Route::patch('staff-users/{staffUser}/status', [StaffUserController::class, 'updateStatus']);
    Route::put('staff-users/{staffUser}/roles', [StaffUserController::class, 'updateRoles']);
    Route::get('staff-users/{staffUser}/assignments', [StaffAssignmentController::class, 'index']);
    Route::post('staff-users/{staffUser}/assignments', [StaffAssignmentController::class, 'store']);
    Route::patch('staff-users/{staffUser}/assignments/{assignment}', [StaffAssignmentController::class, 'update']);

    Route::get('documents', [DocumentController::class, 'index']);
    Route::post('documents', [DocumentController::class, 'store'])->middleware('throttle:document.upload');
    Route::get('documents/{document}', [DocumentController::class, 'show']);
    Route::patch('documents/{document}/archive', [DocumentController::class, 'archive']);

    Route::get('clients', [ClientController::class, 'index']);
    Route::post('clients', [ClientController::class, 'store'])->middleware('throttle:client.create');
    Route::get('clients/{client}', [ClientController::class, 'show']);
    Route::patch('clients/{client}', [ClientController::class, 'update']);
    Route::patch('clients/{client}/kyc-status', [ClientController::class, 'updateKycStatus']);
    Route::get('clients/{client}/kyc-reviews', [ClientController::class, 'kycReviews']);

    Route::scopeBindings()->group(function (): void {
        Route::get('clients/{client}/identity-documents', [ClientIdentityDocumentController::class, 'index']);
        Route::post('clients/{client}/identity-documents', [ClientIdentityDocumentController::class, 'store']);
        Route::get('clients/{client}/identity-documents/{identityDocument}', [ClientIdentityDocumentController::class, 'show']);
        Route::patch('clients/{client}/identity-documents/{identityDocument}', [ClientIdentityDocumentController::class, 'update']);
        Route::patch('clients/{client}/identity-documents/{identityDocument}/status', [ClientIdentityDocumentController::class, 'updateStatus']);

        Route::get('clients/{client}/guarantors', [ClientGuarantorController::class, 'index']);
        Route::post('clients/{client}/guarantors', [ClientGuarantorController::class, 'store']);
        Route::get('clients/{client}/guarantors/{guarantor}', [ClientGuarantorController::class, 'show']);
        Route::patch('clients/{client}/guarantors/{guarantor}', [ClientGuarantorController::class, 'update']);
        Route::patch('clients/{client}/guarantors/{guarantor}/status', [ClientGuarantorController::class, 'updateStatus']);

        Route::get('clients/{client}/proxies', [ClientProxyController::class, 'index']);
        Route::post('clients/{client}/proxies', [ClientProxyController::class, 'store']);
        Route::get('clients/{client}/proxies/{proxy}', [ClientProxyController::class, 'show']);
        Route::patch('clients/{client}/proxies/{proxy}', [ClientProxyController::class, 'update']);
        Route::patch('clients/{client}/proxies/{proxy}/status', [ClientProxyController::class, 'updateStatus']);
    });

    Route::get('roles', [RoleController::class, 'index']);
    Route::put('roles/{role}/permissions', [RoleController::class, 'updatePermissions']);

    Route::get('batch-procedures', [BatchProcedureController::class, 'index']);
    Route::post('batch-procedures', [BatchProcedureController::class, 'store']);
    Route::get('batch-procedures/{batchProcedure}', [BatchProcedureController::class, 'show']);
    Route::patch('batch-procedures/{batchProcedure}', [BatchProcedureController::class, 'update']);
    Route::patch('batch-procedures/{batchProcedure}/status', [BatchProcedureController::class, 'updateStatus']);

    Route::get('batch-runs', [BatchRunController::class, 'index']);
    Route::post('batch-runs', [BatchRunController::class, 'store']);
    Route::get('batch-runs/{batchRun}', [BatchRunController::class, 'show']);
    Route::patch('batch-runs/{batchRun}/status', [BatchRunController::class, 'updateStatus']);

    Route::post('reference-numbers', [ReferenceNumberController::class, 'store'])->middleware('throttle:reference.reserve');

    Route::get('audit-events', [AuditEventController::class, 'index'])->middleware('throttle:audit.browse');
});
