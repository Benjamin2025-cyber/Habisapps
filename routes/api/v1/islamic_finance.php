<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\IslamicApprovalWorkflowController;
use App\Http\Controllers\Api\V1\IslamicFinanceController;
use App\Http\Controllers\Api\V1\IslamicRegulatorySignoffController;
use App\Http\Controllers\Api\V1\IslamicShariaAuthorityController;
use App\Http\Controllers\Api\V1\IslamicStandardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Products and Sharia compliance
    Route::post('islamic-products', [IslamicFinanceController::class, 'storeProduct']);
    Route::post('islamic-products/{productPublicId}/compliance-reviews', [IslamicFinanceController::class, 'storeComplianceReview']);
    Route::post('islamic-compliance-reviews/{reviewPublicId}/review', [IslamicFinanceController::class, 'reviewCompliance']);

    // Murabaha financing
    Route::post('islamic-financings', [IslamicFinanceController::class, 'storeFinancing']);
    Route::post('islamic-financings/{financingPublicId}/assets', [IslamicFinanceController::class, 'storeFinancingAsset']);
    Route::post('islamic-financings/{financingPublicId}/installments', [IslamicFinanceController::class, 'storeInstallments']);
    Route::post('islamic-financings/{financingPublicId}/approve', [IslamicFinanceController::class, 'approveFinancing']);

    // IF-001 Standards registry
    Route::get('islamic-standards', [IslamicStandardController::class, 'index']);
    Route::post('islamic-standards', [IslamicStandardController::class, 'store']);
    Route::get('islamic-standards/{standardPublicId}', [IslamicStandardController::class, 'show']);
    Route::put('islamic-standards/{standardPublicId}', [IslamicStandardController::class, 'update']);
    Route::post('islamic-standards/{standardPublicId}/amend', [IslamicStandardController::class, 'amend']);
    Route::post('islamic-standards/{standardPublicId}/activate', [IslamicStandardController::class, 'activate']);
    Route::post('islamic-standards/{standardPublicId}/retire', [IslamicStandardController::class, 'retire']);
    Route::post('islamic-standards/{standardPublicId}/links', [IslamicStandardController::class, 'link']);
    Route::delete('islamic-standards/{standardPublicId}/links', [IslamicStandardController::class, 'unlink']);

    // IF-002 Regulatory sign-off registry
    Route::get('islamic-regulatory-signoffs', [IslamicRegulatorySignoffController::class, 'index']);
    Route::post('islamic-regulatory-signoffs', [IslamicRegulatorySignoffController::class, 'store']);
    Route::get('islamic-regulatory-signoffs/{signoffPublicId}', [IslamicRegulatorySignoffController::class, 'show']);
    Route::put('islamic-regulatory-signoffs/{signoffPublicId}', [IslamicRegulatorySignoffController::class, 'update']);
    Route::post('islamic-regulatory-signoffs/{signoffPublicId}/activate', [IslamicRegulatorySignoffController::class, 'activate']);
    Route::post('islamic-regulatory-signoffs/{signoffPublicId}/suspend', [IslamicRegulatorySignoffController::class, 'suspend']);
    Route::post('islamic-regulatory-signoffs/{signoffPublicId}/revoke', [IslamicRegulatorySignoffController::class, 'revoke']);
    Route::post('islamic-regulatory-signoffs/{signoffPublicId}/retire', [IslamicRegulatorySignoffController::class, 'retire']);
    Route::post('islamic-regulatory-signoffs/{signoffPublicId}/links', [IslamicRegulatorySignoffController::class, 'link']);
    Route::delete('islamic-regulatory-signoffs/{signoffPublicId}/links', [IslamicRegulatorySignoffController::class, 'unlink']);

    // IF-010 Sharia authority registry
    Route::get('islamic-sharia-authorities', [IslamicShariaAuthorityController::class, 'index']);
    Route::post('islamic-sharia-authorities', [IslamicShariaAuthorityController::class, 'store']);
    Route::get('islamic-sharia-authorities/{authorityPublicId}', [IslamicShariaAuthorityController::class, 'show']);
    Route::put('islamic-sharia-authorities/{authorityPublicId}', [IslamicShariaAuthorityController::class, 'update']);
    Route::post('islamic-sharia-authorities/{authorityPublicId}/activate', [IslamicShariaAuthorityController::class, 'activate']);
    Route::post('islamic-sharia-authorities/{authorityPublicId}/suspend', [IslamicShariaAuthorityController::class, 'suspend']);
    Route::post('islamic-sharia-authorities/{authorityPublicId}/revoke', [IslamicShariaAuthorityController::class, 'revoke']);
    Route::post('islamic-sharia-authorities/{authorityPublicId}/retire', [IslamicShariaAuthorityController::class, 'retire']);
    Route::post('islamic-sharia-authorities/{authorityPublicId}/members', [IslamicShariaAuthorityController::class, 'storeMember']);
    Route::put('islamic-sharia-authorities/{authorityPublicId}/members/{memberPublicId}', [IslamicShariaAuthorityController::class, 'updateMember']);
    Route::post('islamic-sharia-authorities/{authorityPublicId}/members/{memberPublicId}/suspend', [IslamicShariaAuthorityController::class, 'suspendMember']);
    Route::post('islamic-sharia-authorities/{authorityPublicId}/members/{memberPublicId}/revoke', [IslamicShariaAuthorityController::class, 'revokeMember']);

    // IF-011 Reusable approval workflow
    Route::get('islamic-approval-workflows/{subjectType}/{subjectPublicId}', [IslamicApprovalWorkflowController::class, 'show']);
    Route::post('islamic-approval-workflows/{subjectType}/{subjectPublicId}/submit', [IslamicApprovalWorkflowController::class, 'submit']);
    Route::post('islamic-approval-workflows/{subjectType}/{subjectPublicId}/approve', [IslamicApprovalWorkflowController::class, 'approve']);
    Route::post('islamic-approval-workflows/{subjectType}/{subjectPublicId}/reject', [IslamicApprovalWorkflowController::class, 'reject']);
    Route::post('islamic-approval-workflows/{subjectType}/{subjectPublicId}/suspend', [IslamicApprovalWorkflowController::class, 'suspend']);
    Route::post('islamic-approval-workflows/{subjectType}/{subjectPublicId}/revoke', [IslamicApprovalWorkflowController::class, 'revoke']);
    Route::post('islamic-approval-workflows/{subjectType}/{subjectPublicId}/expire', [IslamicApprovalWorkflowController::class, 'expire']);
    Route::post('islamic-approval-workflows/{subjectType}/{subjectPublicId}/archive', [IslamicApprovalWorkflowController::class, 'archive']);
});
