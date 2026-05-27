<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicFinanceWorkflowControllerAdapter
{
    public function __construct(
        private readonly IslamicProductWorkflow $product,
        private readonly IslamicFinancingWorkflow $financing,
        private readonly IslamicScreeningPolicyWorkflow $screeningPolicy,
        private readonly IslamicStandardWorkflow $standard,
        private readonly IslamicRegulatorySignoffWorkflow $signoff,
        private readonly IslamicShariaAuthorityWorkflow $authority,
        private readonly IslamicApprovalWorkflowApiWorkflow $approvalWorkflow,
    ) {}

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->product->storeProduct($request);
    }

    public function indexProductFamilies(Request $request): JsonResponse
    {
        return $this->product->indexProductFamilies($request);
    }

    public function showProductFamily(Request $request, string $familyCode): JsonResponse
    {
        return $this->product->showProductFamily($request, $familyCode);
    }

    public function showProductReadiness(Request $request, string $productPublicId): JsonResponse
    {
        return $this->product->showProductReadiness($request, $productPublicId);
    }

    public function listProductReadinessSnapshots(Request $request, string $productPublicId): JsonResponse
    {
        return $this->product->listProductReadinessSnapshots($request, $productPublicId);
    }

    public function storeComplianceReview(Request $request, string $productPublicId): JsonResponse
    {
        return $this->product->storeComplianceReview($request, $productPublicId);
    }

    public function reviewCompliance(Request $request, string $reviewPublicId): JsonResponse
    {
        return $this->product->reviewCompliance($request, $reviewPublicId);
    }

    public function storeFinancing(Request $request): JsonResponse
    {
        return $this->financing->storeFinancing($request);
    }

    public function storeMourabahaRequest(Request $request): JsonResponse
    {
        return $this->financing->storeMourabahaRequest($request);
    }

    public function storeMourabahaQuote(Request $request, string $requestPublicId): JsonResponse
    {
        return $this->financing->storeMourabahaQuote($request, $requestPublicId);
    }

    public function approveMourabahaPurchase(Request $request, string $requestPublicId): JsonResponse
    {
        return $this->financing->approveMourabahaPurchase($request, $requestPublicId);
    }

    public function storeFinancingAsset(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeFinancingAsset($request, $financingPublicId);
    }

    public function transitionFinancingAsset(Request $request, string $assetPublicId): JsonResponse
    {
        return $this->financing->transitionFinancingAsset($request, $assetPublicId);
    }

    public function showFinancedAsset(Request $request, string $assetPublicId): JsonResponse
    {
        return $this->financing->showFinancedAsset($request, $assetPublicId);
    }

    public function showFinancedAssetTimeline(Request $request, string $assetPublicId): JsonResponse
    {
        return $this->financing->showFinancedAssetTimeline($request, $assetPublicId);
    }

    public function storeInstallments(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeInstallments($request, $financingPublicId);
    }

    public function approveFinancing(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->approveFinancing($request, $financingPublicId);
    }

    public function storeIjaraConditionReport(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeIjaraConditionReport($request, $financingPublicId);
    }

    public function storeIjaraRentalSchedules(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeIjaraRentalSchedules($request, $financingPublicId);
    }

    public function activateIjaraLease(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->activateIjaraLease($request, $financingPublicId);
    }

    public function storeIjaraDamageEvent(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeIjaraDamageEvent($request, $financingPublicId);
    }

    public function storeIjaraSuspension(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeIjaraSuspension($request, $financingPublicId);
    }

    public function storeIjaraEarlyTermination(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeIjaraEarlyTermination($request, $financingPublicId);
    }

    public function requestIjaraTransfer(Request $request, string $financingPublicId, string $assetPublicId): JsonResponse
    {
        return $this->financing->requestIjaraTransfer($request, $financingPublicId, $assetPublicId);
    }

    public function approveIjaraTransfer(Request $request, string $transferEventPublicId): JsonResponse
    {
        return $this->financing->approveIjaraTransfer($request, $transferEventPublicId);
    }

    public function postIjaraTransfer(Request $request, string $transferEventPublicId): JsonResponse
    {
        return $this->financing->postIjaraTransfer($request, $transferEventPublicId);
    }

    public function showIjaraTransferEvent(Request $request, string $transferEventPublicId): JsonResponse
    {
        return $this->financing->showIjaraTransferEvent($request, $transferEventPublicId);
    }

    public function storePurchaseEvidence(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storePurchaseEvidence($request, $financingPublicId);
    }

    public function storeCostEvidence(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeCostEvidence($request, $financingPublicId);
    }

    public function showOriginationSnapshot(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->showOriginationSnapshot($request, $financingPublicId);
    }

    public function storeCollection(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeCollection($request, $financingPublicId);
    }

    public function storeRebate(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeRebate($request, $financingPublicId);
    }

    public function storeCancellation(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeCancellation($request, $financingPublicId);
    }

    public function storeDefaultTreatment(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeDefaultTreatment($request, $financingPublicId);
    }

    public function storeReversal(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeReversal($request, $financingPublicId);
    }

    public function storeCorrection(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeCorrection($request, $financingPublicId);
    }

    public function showReceivableLedger(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->showReceivableLedger($request, $financingPublicId);
    }

    public function listComplianceCases(Request $request): JsonResponse
    {
        return $this->product->listComplianceCases($request);
    }

    public function showComplianceCase(Request $request, string $casePublicId): JsonResponse
    {
        return $this->product->showComplianceCase($request, $casePublicId);
    }

    public function showComplianceCaseTimeline(Request $request, string $casePublicId): JsonResponse
    {
        return $this->product->showComplianceCaseTimeline($request, $casePublicId);
    }

    public function complianceCaseSummary(Request $request): JsonResponse
    {
        return $this->product->complianceCaseSummary($request);
    }

    public function indexScreeningPolicies(Request $request): JsonResponse
    {
        return $this->screeningPolicy->index($request);
    }

    public function storeScreeningPolicy(Request $request): JsonResponse
    {
        return $this->screeningPolicy->store($request);
    }

    public function showScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->screeningPolicy->show($request, $policyPublicId);
    }

    public function updateScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->screeningPolicy->updateDraft($request, $policyPublicId);
    }

    public function storeScreeningPolicyRule(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->screeningPolicy->storeRule($request, $policyPublicId);
    }

    public function updateScreeningPolicyRule(Request $request, string $policyPublicId, string $rulePublicId): JsonResponse
    {
        return $this->screeningPolicy->updateRule($request, $policyPublicId, $rulePublicId);
    }

    public function deleteScreeningPolicyRule(Request $request, string $policyPublicId, string $rulePublicId): JsonResponse
    {
        return $this->screeningPolicy->deleteRule($request, $policyPublicId, $rulePublicId);
    }

    public function activateScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->screeningPolicy->activate($request, $policyPublicId);
    }

    public function suspendScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->screeningPolicy->suspend($request, $policyPublicId);
    }

    public function revokeScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->screeningPolicy->revoke($request, $policyPublicId);
    }

    public function archiveScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->screeningPolicy->archive($request, $policyPublicId);
    }

    public function evaluateScreening(Request $request): JsonResponse
    {
        return $this->screeningPolicy->evaluate($request);
    }

    public function listScreeningResults(Request $request): JsonResponse
    {
        return $this->screeningPolicy->listResults($request);
    }

    public function showScreeningResult(Request $request, string $resultPublicId): JsonResponse
    {
        return $this->screeningPolicy->showResult($request, $resultPublicId);
    }

    public function indexStandards(Request $request): JsonResponse
    {
        return $this->standard->index($request);
    }

    public function storeStandard(Request $request): JsonResponse
    {
        return $this->standard->store($request);
    }

    public function showStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->show($request, $standardPublicId);
    }

    public function updateStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->updateDraft($request, $standardPublicId);
    }

    public function amendStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->amend($request, $standardPublicId);
    }

    public function activateStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->activate($request, $standardPublicId);
    }

    public function retireStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->retire($request, $standardPublicId);
    }

    public function lifecycleUpkeepStandards(Request $request): JsonResponse
    {
        return $this->standard->lifecycleUpkeep($request);
    }

    public function linkStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->link($request, $standardPublicId);
    }

    public function unlinkStandard(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->standard->unlink($request, $standardPublicId);
    }

    public function indexSignoffs(Request $request): JsonResponse
    {
        return $this->signoff->index($request);
    }

    public function storeSignoff(Request $request): JsonResponse
    {
        return $this->signoff->store($request);
    }

    public function showSignoff(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->signoff->show($request, $signoffPublicId);
    }

    public function updateSignoff(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->signoff->updateDraft($request, $signoffPublicId);
    }

    public function activateSignoff(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->signoff->activate($request, $signoffPublicId);
    }

    public function suspendSignoff(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->signoff->suspend($request, $signoffPublicId);
    }

    public function revokeSignoff(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->signoff->revoke($request, $signoffPublicId);
    }

    public function retireSignoff(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->signoff->retire($request, $signoffPublicId);
    }

    public function linkSignoff(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->signoff->link($request, $signoffPublicId);
    }

    public function unlinkSignoff(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->signoff->unlink($request, $signoffPublicId);
    }

    public function indexAuthorities(Request $request): JsonResponse
    {
        return $this->authority->index($request);
    }

    public function storeAuthority(Request $request): JsonResponse
    {
        return $this->authority->store($request);
    }

    public function showAuthority(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->authority->show($request, $authorityPublicId);
    }

    public function updateAuthority(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->authority->updateDraft($request, $authorityPublicId);
    }

    public function activateAuthority(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->authority->activate($request, $authorityPublicId);
    }

    public function suspendAuthority(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->authority->suspend($request, $authorityPublicId);
    }

    public function revokeAuthority(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->authority->revoke($request, $authorityPublicId);
    }

    public function retireAuthority(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->authority->retire($request, $authorityPublicId);
    }

    public function storeAuthorityMember(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->authority->storeMember($request, $authorityPublicId);
    }

    public function updateAuthorityMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        return $this->authority->updateMember($request, $authorityPublicId, $memberPublicId);
    }

    public function suspendAuthorityMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        return $this->authority->suspendMember($request, $authorityPublicId, $memberPublicId);
    }

    public function revokeAuthorityMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        return $this->authority->revokeMember($request, $authorityPublicId, $memberPublicId);
    }

    public function showApprovalWorkflow(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->approvalWorkflow->show($request, $subjectType, $subjectPublicId);
    }

    public function submitApprovalWorkflow(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->approvalWorkflow->submit($request, $subjectType, $subjectPublicId);
    }

    public function approveApprovalWorkflow(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->approvalWorkflow->approve($request, $subjectType, $subjectPublicId);
    }

    public function rejectApprovalWorkflow(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->approvalWorkflow->reject($request, $subjectType, $subjectPublicId);
    }

    public function suspendApprovalWorkflow(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->approvalWorkflow->suspend($request, $subjectType, $subjectPublicId);
    }

    public function revokeApprovalWorkflow(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->approvalWorkflow->revoke($request, $subjectType, $subjectPublicId);
    }

    public function expireApprovalWorkflow(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->approvalWorkflow->expire($request, $subjectType, $subjectPublicId);
    }

    public function archiveApprovalWorkflow(Request $request, string $subjectType, string $subjectPublicId): JsonResponse
    {
        return $this->approvalWorkflow->archive($request, $subjectType, $subjectPublicId);
    }
}
