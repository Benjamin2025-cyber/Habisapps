<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\IslamicApprovalWorkflowController;
use App\Http\Controllers\Api\V1\IslamicContractTemplateController;
use App\Http\Controllers\Api\V1\IslamicFinanceController;
use App\Http\Controllers\Api\V1\IslamicFinancedAssetController;
use App\Http\Controllers\Api\V1\IslamicIjaraController;
use App\Http\Controllers\Api\V1\IslamicIstisnaaProjectController;
use App\Http\Controllers\Api\V1\IslamicMappingController;
use App\Http\Controllers\Api\V1\IslamicMourabahaOriginationController;
use App\Http\Controllers\Api\V1\IslamicPartnershipController;
use App\Http\Controllers\Api\V1\IslamicRegulatorySignoffController;
use App\Http\Controllers\Api\V1\IslamicSalamGoodsController;
use App\Http\Controllers\Api\V1\IslamicShariaAuthorityController;
use App\Http\Controllers\Api\V1\IslamicStandardController;
use App\Http\Controllers\Api\V1\IslamicTreatmentController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Products and Sharia compliance
    Route::get('islamic-product-families', [IslamicFinanceController::class, 'indexProductFamilies']);
    Route::get('islamic-product-families/{familyCode}', [IslamicFinanceController::class, 'showProductFamily']);
    Route::post('islamic-products', [IslamicFinanceController::class, 'storeProduct']);
    Route::get('islamic-products/{productPublicId}/readiness', [IslamicFinanceController::class, 'showProductReadiness']);
    Route::get('islamic-products/{productPublicId}/readiness-snapshots', [IslamicFinanceController::class, 'listProductReadinessSnapshots']);
    Route::post('islamic-products/{productPublicId}/compliance-reviews', [IslamicFinanceController::class, 'storeComplianceReview']);
    Route::post('islamic-compliance-reviews/{reviewPublicId}/review', [IslamicFinanceController::class, 'reviewCompliance']);
    Route::get('islamic-compliance-cases', [IslamicFinanceController::class, 'listComplianceCases']);
    Route::get('islamic-compliance-cases/{casePublicId}', [IslamicFinanceController::class, 'showComplianceCase']);
    Route::get('islamic-compliance-cases/{casePublicId}/timeline', [IslamicFinanceController::class, 'showComplianceCaseTimeline']);
    Route::get('islamic-compliance-cases/report/summary', [IslamicFinanceController::class, 'complianceCaseSummary']);
    Route::get('islamic-screening-policies', [IslamicFinanceController::class, 'indexScreeningPolicies']);
    Route::post('islamic-screening-policies', [IslamicFinanceController::class, 'storeScreeningPolicy']);
    Route::get('islamic-screening-policies/{policyPublicId}', [IslamicFinanceController::class, 'showScreeningPolicy']);
    Route::put('islamic-screening-policies/{policyPublicId}', [IslamicFinanceController::class, 'updateScreeningPolicy']);
    Route::post('islamic-screening-policies/{policyPublicId}/rules', [IslamicFinanceController::class, 'storeScreeningPolicyRule']);
    Route::put('islamic-screening-policies/{policyPublicId}/rules/{rulePublicId}', [IslamicFinanceController::class, 'updateScreeningPolicyRule']);
    Route::delete('islamic-screening-policies/{policyPublicId}/rules/{rulePublicId}', [IslamicFinanceController::class, 'deleteScreeningPolicyRule']);
    Route::post('islamic-screening-policies/{policyPublicId}/activate', [IslamicFinanceController::class, 'activateScreeningPolicy']);
    Route::post('islamic-screening-policies/{policyPublicId}/suspend', [IslamicFinanceController::class, 'suspendScreeningPolicy']);
    Route::post('islamic-screening-policies/{policyPublicId}/revoke', [IslamicFinanceController::class, 'revokeScreeningPolicy']);
    Route::post('islamic-screening-policies/{policyPublicId}/archive', [IslamicFinanceController::class, 'archiveScreeningPolicy']);
    Route::post('islamic-screening/evaluate', [IslamicFinanceController::class, 'evaluateScreening']);
    Route::get('islamic-screening-results', [IslamicFinanceController::class, 'listScreeningResults']);
    Route::get('islamic-screening-results/{resultPublicId}', [IslamicFinanceController::class, 'showScreeningResult']);

    // Murabaha financing
    Route::post('islamic-mourabaha-requests', [IslamicMourabahaOriginationController::class, 'storeMourabahaRequest']);
    Route::post('islamic-mourabaha-requests/{requestPublicId}/quotes', [IslamicMourabahaOriginationController::class, 'storeMourabahaQuote']);
    Route::post('islamic-mourabaha-requests/{requestPublicId}/purchase-approval', [IslamicMourabahaOriginationController::class, 'approveMourabahaPurchase']);
    Route::post('islamic-financings', [IslamicFinanceController::class, 'storeFinancing']);
    Route::post('islamic-financings/{financingPublicId}/assets', [IslamicFinancedAssetController::class, 'storeFinancingAsset']);
    Route::get('islamic-financed-assets/{assetPublicId}', [IslamicFinancedAssetController::class, 'showFinancedAsset']);
    Route::get('islamic-financed-assets/{assetPublicId}/timeline', [IslamicFinancedAssetController::class, 'showFinancedAssetTimeline']);
    Route::post('islamic-financed-assets/{assetPublicId}/transition', [IslamicFinancedAssetController::class, 'transitionFinancingAsset']);
    Route::post('islamic-financings/{financingPublicId}/installments', [IslamicFinanceController::class, 'storeInstallments']);
    Route::post('islamic-financings/{financingPublicId}/purchase-evidence', [IslamicMourabahaOriginationController::class, 'storePurchaseEvidence']);
    Route::post('islamic-financings/{financingPublicId}/cost-evidence', [IslamicMourabahaOriginationController::class, 'storeCostEvidence']);
    Route::post('islamic-financings/{financingPublicId}/collections', [IslamicMourabahaOriginationController::class, 'storeCollection']);
    Route::post('islamic-financings/{financingPublicId}/rebates', [IslamicMourabahaOriginationController::class, 'storeRebate']);
    Route::post('islamic-financings/{financingPublicId}/cancellations', [IslamicMourabahaOriginationController::class, 'storeCancellation']);
    Route::post('islamic-financings/{financingPublicId}/default-treatments', [IslamicMourabahaOriginationController::class, 'storeDefaultTreatment']);
    Route::post('islamic-financings/{financingPublicId}/reversals', [IslamicMourabahaOriginationController::class, 'storeReversal']);
    Route::post('islamic-financings/{financingPublicId}/corrections', [IslamicMourabahaOriginationController::class, 'storeCorrection']);
    Route::post('islamic-financings/{financingPublicId}/approve', [IslamicFinanceController::class, 'approveFinancing']);
    Route::get('islamic-financings/{financingPublicId}/origination-snapshot', [IslamicMourabahaOriginationController::class, 'showOriginationSnapshot']);
    Route::get('islamic-financings/{financingPublicId}/receivable-ledger', [IslamicMourabahaOriginationController::class, 'showReceivableLedger']);
    Route::post('islamic-financings/{financingPublicId}/lease-condition-report', [IslamicIjaraController::class, 'storeConditionReport']);
    Route::post('islamic-financings/{financingPublicId}/rental-schedules', [IslamicIjaraController::class, 'storeRentalSchedules']);
    Route::post('islamic-financings/{financingPublicId}/activate-lease', [IslamicIjaraController::class, 'activateLease']);
    Route::post('islamic-financings/{financingPublicId}/damage-events', [IslamicIjaraController::class, 'storeDamageEvent']);
    Route::post('islamic-financings/{financingPublicId}/suspensions', [IslamicIjaraController::class, 'storeSuspension']);
    Route::post('islamic-financings/{financingPublicId}/early-terminations', [IslamicIjaraController::class, 'storeEarlyTermination']);
    Route::post('islamic-financings/{financingPublicId}/assets/{assetPublicId}/transfer-requests', [IslamicIjaraController::class, 'requestTransfer']);
    Route::post('islamic-transfer-events/{transferEventPublicId}/approve', [IslamicIjaraController::class, 'approveTransfer']);
    Route::post('islamic-transfer-events/{transferEventPublicId}/post', [IslamicIjaraController::class, 'postTransfer']);
    Route::get('islamic-transfer-events/{transferEventPublicId}', [IslamicIjaraController::class, 'showTransferEvent']);

    // IF-052 Zakat/charity/non-compliant treatment governance
    Route::get('islamic-treatment-policies', [IslamicTreatmentController::class, 'indexPolicies']);
    Route::post('islamic-treatment-policies', [IslamicTreatmentController::class, 'storePolicy']);
    Route::post('islamic-treatment-policies/{policyPublicId}/approve', [IslamicTreatmentController::class, 'approvePolicy']);
    Route::post('islamic-treatment-events', [IslamicTreatmentController::class, 'storeEvent']);
    Route::post('islamic-treatment-events/{eventPublicId}/post', [IslamicTreatmentController::class, 'postEvent']);
    Route::get('islamic-treatment-reports/reconciliation', [IslamicTreatmentController::class, 'reconciliationReport']);

    // IF-041 Salam goods registry
    Route::post('islamic-salam-goods', [IslamicSalamGoodsController::class, 'store']);
    Route::get('islamic-salam-goods/{goodsPublicId}', [IslamicSalamGoodsController::class, 'show']);
    Route::get('islamic-salam-goods/{goodsPublicId}/timeline', [IslamicSalamGoodsController::class, 'timeline']);
    Route::post('islamic-salam-goods/{goodsPublicId}/transition', [IslamicSalamGoodsController::class, 'transition']);
    Route::post('islamic-salam-goods/{goodsPublicId}/deliveries', [IslamicSalamGoodsController::class, 'storeDelivery']);
    Route::post('islamic-financings/{financingPublicId}/salam-upfront-payments', [IslamicSalamGoodsController::class, 'storeUpfrontPayment']);

    // IF-042 Istisna'a project registry
    Route::post('islamic-istisnaa-projects', [IslamicIstisnaaProjectController::class, 'store']);
    Route::get('islamic-istisnaa-projects/{projectPublicId}', [IslamicIstisnaaProjectController::class, 'show']);
    Route::get('islamic-istisnaa-projects/{projectPublicId}/timeline', [IslamicIstisnaaProjectController::class, 'timeline']);
    Route::post('islamic-istisnaa-projects/{projectPublicId}/milestones', [IslamicIstisnaaProjectController::class, 'storeMilestone']);
    Route::post('islamic-istisnaa-projects/{projectPublicId}/variations', [IslamicIstisnaaProjectController::class, 'storeVariation']);
    Route::post('islamic-istisnaa-projects/{projectPublicId}/accept', [IslamicIstisnaaProjectController::class, 'accept']);
    Route::post('islamic-istisnaa-projects/{projectPublicId}/parallel-supplier/approve', [IslamicIstisnaaProjectController::class, 'approveParallelSupplier']);
    Route::post('islamic-istisnaa-milestones/{milestonePublicId}/inspection', [IslamicIstisnaaProjectController::class, 'storeInspection']);
    Route::post('islamic-istisnaa-milestones/{milestonePublicId}/payments', [IslamicIstisnaaProjectController::class, 'storePayment']);

    // IF-043 Partnership registry (Moudaraba & Moucharaka)
    Route::post('islamic-partnerships', [IslamicPartnershipController::class, 'store']);
    Route::get('islamic-partnerships/{partnershipPublicId}', [IslamicPartnershipController::class, 'show']);
    Route::post('islamic-partnerships/{partnershipPublicId}/partners', [IslamicPartnershipController::class, 'addPartner']);
    Route::post('islamic-partnerships/{partnershipPublicId}/contributions', [IslamicPartnershipController::class, 'storeContribution']);
    Route::post('islamic-partnerships/{partnershipPublicId}/activate', [IslamicPartnershipController::class, 'activate']);
    Route::post('islamic-partnerships/{partnershipPublicId}/reports', [IslamicPartnershipController::class, 'storeReport']);
    Route::post('islamic-partnerships/{partnershipPublicId}/profit-declarations', [IslamicPartnershipController::class, 'storeProfitDeclaration']);
    Route::post('islamic-partnerships/{partnershipPublicId}/losses', [IslamicPartnershipController::class, 'storeLoss']);
    Route::post('islamic-partnerships/{partnershipPublicId}/valuations', [IslamicPartnershipController::class, 'storeValuation']);
    Route::post('islamic-partnerships/{partnershipPublicId}/buyouts', [IslamicPartnershipController::class, 'storeBuyout']);
    Route::post('islamic-partnerships/{partnershipPublicId}/liquidate', [IslamicPartnershipController::class, 'liquidate']);
    Route::get('islamic-partnerships/{partnershipPublicId}/timeline', [IslamicPartnershipController::class, 'timeline']);

    // IF-051 Approved mapping workflow
    Route::get('islamic-mappings', [IslamicMappingController::class, 'index']);
    Route::post('islamic-mappings', [IslamicMappingController::class, 'store']);
    Route::get('islamic-mappings/{mappingPublicId}', [IslamicMappingController::class, 'show']);
    Route::put('islamic-mappings/{mappingPublicId}', [IslamicMappingController::class, 'update']);
    Route::post('islamic-mappings/{mappingPublicId}/submit', [IslamicMappingController::class, 'submit']);
    Route::post('islamic-mappings/{mappingPublicId}/approve', [IslamicMappingController::class, 'approve']);
    Route::post('islamic-mappings/{mappingPublicId}/reject', [IslamicMappingController::class, 'reject']);
    Route::post('islamic-mappings/{mappingPublicId}/suspend', [IslamicMappingController::class, 'suspend']);
    Route::post('islamic-mappings/{mappingPublicId}/revoke', [IslamicMappingController::class, 'revoke']);
    Route::post('islamic-mappings/{mappingPublicId}/archive', [IslamicMappingController::class, 'archive']);

    // IF-032 Contract template registry
    Route::get('islamic-contract-templates', [IslamicContractTemplateController::class, 'index']);
    Route::post('islamic-contract-templates', [IslamicContractTemplateController::class, 'store']);
    Route::get('islamic-contract-templates/{templatePublicId}', [IslamicContractTemplateController::class, 'show']);
    Route::put('islamic-contract-templates/{templatePublicId}', [IslamicContractTemplateController::class, 'update']);
    Route::post('islamic-contract-templates/{templatePublicId}/submit', [IslamicContractTemplateController::class, 'submit']);
    Route::post('islamic-contract-templates/{templatePublicId}/approve', [IslamicContractTemplateController::class, 'approve']);
    Route::post('islamic-contract-templates/{templatePublicId}/suspend', [IslamicContractTemplateController::class, 'suspend']);
    Route::post('islamic-contract-templates/{templatePublicId}/revoke', [IslamicContractTemplateController::class, 'revoke']);
    Route::post('islamic-contract-templates/{templatePublicId}/retire', [IslamicContractTemplateController::class, 'retire']);
    Route::post('islamic-contract-templates/{templatePublicId}/archive', [IslamicContractTemplateController::class, 'archive']);

    // IF-001 Standards registry
    Route::get('islamic-standards', [IslamicStandardController::class, 'index']);
    Route::post('islamic-standards', [IslamicStandardController::class, 'store']);
    Route::post('islamic-standards/lifecycle-upkeep', [IslamicStandardController::class, 'lifecycleUpkeep']);
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
