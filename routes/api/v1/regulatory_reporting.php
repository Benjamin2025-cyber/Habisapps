<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\RegulatoryReportingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('regulatory-sources', [RegulatoryReportingController::class, 'storeSource']);
    Route::post('regulatory-sources/{sourcePublicId}/emf-accounts', [RegulatoryReportingController::class, 'loadEmfAccounts']);
    Route::post('report-definitions', [RegulatoryReportingController::class, 'storeReportDefinitionVersion']);
    Route::post('report-runs/{runPublicId}/review', [RegulatoryReportingController::class, 'reviewReportRun']);
    Route::post('report-runs/{runPublicId}/submit', [RegulatoryReportingController::class, 'submitReportRun']);
    Route::get('regulatory-mapping-inspection/{operationCode}', [RegulatoryReportingController::class, 'inspectMapping']);
});
