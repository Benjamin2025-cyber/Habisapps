<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DatabaseBackupController;
use App\Http\Controllers\Api\V1\DatabaseRestoreController;
use App\Http\Controllers\Api\V1\DatabaseStorageController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'accounting.day.registration-lock'])->group(function (): void {
    // Storage-health visibility (read) and retention cleanup (maintenance).
    Route::get('database/storage', [DatabaseStorageController::class, 'show']);

    // Backups. Static sub-paths (retention) are declared before the wildcard
    // {databaseBackup} binding so they are never captured as a public id.
    Route::get('database/backups', [DatabaseBackupController::class, 'index']);
    Route::post('database/backups', [DatabaseBackupController::class, 'store'])
        ->defaults('accounting_day_classification', 'system_maintenance');
    Route::post('database/backups/retention/run', [DatabaseStorageController::class, 'runRetention'])
        ->defaults('accounting_day_classification', 'system_maintenance');
    Route::get('database/backups/{databaseBackup}', [DatabaseBackupController::class, 'show']);
    Route::get('database/backups/{databaseBackup}/download', [DatabaseBackupController::class, 'download']);
    Route::delete('database/backups/{databaseBackup}', [DatabaseBackupController::class, 'destroy'])
        ->defaults('accounting_day_classification', 'system_maintenance');
    Route::post('database/backups/{databaseBackup}/verify', [DatabaseBackupController::class, 'verify'])
        ->defaults('accounting_day_classification', 'system_maintenance');

    // Restores. Two-step: plan then execute; plus history and cancellation.
    Route::get('database/restores', [DatabaseRestoreController::class, 'index']);
    Route::post('database/restores/plan', [DatabaseRestoreController::class, 'plan'])
        ->defaults('accounting_day_classification', 'system_maintenance');
    Route::get('database/restores/{restoreOperation}', [DatabaseRestoreController::class, 'show']);
    Route::post('database/restores/{restoreOperation}/execute', [DatabaseRestoreController::class, 'execute'])
        ->defaults('accounting_day_classification', 'system_maintenance');
    Route::post('database/restores/{restoreOperation}/cancel', [DatabaseRestoreController::class, 'cancel'])
        ->defaults('accounting_day_classification', 'system_maintenance');
});
