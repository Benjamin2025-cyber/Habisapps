<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Insurance\InsuranceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InsuranceController extends BaseController
{
    public function __construct(
        private readonly InsuranceWorkflowControllerAdapter $insurance,
    ) {}

    public function storePartner(Request $request): JsonResponse
    {
        return $this->insurance->storePartner($request);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->insurance->storeProduct($request);
    }

    public function activateProduct(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insurance->activateProduct($request, $productPublicId);
    }

    public function storeProductRuleVersion(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insurance->storeProductRuleVersion($request, $productPublicId);
    }

    public function storeClaimEvidenceConfig(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insurance->storeClaimEvidenceConfig($request, $productPublicId);
    }

    public function approveProductRuleVersion(Request $request, string $versionPublicId): JsonResponse
    {
        return $this->insurance->approveProductRuleVersion($request, $versionPublicId);
    }

    public function storeSubscription(Request $request): JsonResponse
    {
        return $this->insurance->storeSubscription($request);
    }

    public function activateSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insurance->activateSubscription($request, $subscriptionPublicId);
    }

    public function renewSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insurance->renewSubscription($request, $subscriptionPublicId);
    }

    public function storeEndorsement(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insurance->storeEndorsement($request, $subscriptionPublicId);
    }

    public function cancelSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insurance->cancelSubscription($request, $subscriptionPublicId);
    }

    public function approveEndorsement(Request $request, string $endorsementPublicId): JsonResponse
    {
        return $this->insurance->approveEndorsement($request, $endorsementPublicId);
    }

    public function reviewCancellation(Request $request, string $cancellationPublicId): JsonResponse
    {
        return $this->insurance->reviewCancellation($request, $cancellationPublicId);
    }

    public function storePremiumAssessment(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insurance->storePremiumAssessment($request, $subscriptionPublicId);
    }

    public function collectPremiumFromAccount(Request $request, string $assessmentPublicId): JsonResponse
    {
        return $this->insurance->collectPremiumFromAccount($request, $assessmentPublicId);
    }

    public function collectPremiumCash(Request $request, string $assessmentPublicId): JsonResponse
    {
        return $this->insurance->collectPremiumCash($request, $assessmentPublicId);
    }

    public function reversePremiumPayment(Request $request, string $paymentPublicId): JsonResponse
    {
        return $this->insurance->reversePremiumPayment($request, $paymentPublicId);
    }

    public function generatePremiumBatch(Request $request): JsonResponse
    {
        return $this->insurance->generatePremiumBatch($request);
    }

    public function storeClaim(Request $request): JsonResponse
    {
        return $this->insurance->storeClaim($request);
    }

    public function attachClaimDocument(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insurance->attachClaimDocument($request, $claimPublicId);
    }

    public function decideClaim(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insurance->decideClaim($request, $claimPublicId);
    }

    public function requestClaimDecision(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insurance->requestClaimDecision($request, $claimPublicId);
    }

    public function reviewClaimDecision(Request $request, string $decisionPublicId): JsonResponse
    {
        return $this->insurance->reviewClaimDecision($request, $decisionPublicId);
    }

    public function postClaimSettlement(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insurance->postClaimSettlement($request, $claimPublicId);
    }

    public function reverseClaimSettlement(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insurance->reverseClaimSettlement($request, $claimPublicId);
    }

    public function storeRemittanceBatch(Request $request): JsonResponse
    {
        return $this->insurance->storeRemittanceBatch($request);
    }

    public function approveRemittanceBatch(Request $request, string $batchPublicId): JsonResponse
    {
        return $this->insurance->approveRemittanceBatch($request, $batchPublicId);
    }

    public function activeSubscriptionsReport(Request $request): JsonResponse
    {
        return $this->insurance->activeSubscriptionsReport($request);
    }

    public function premiumsReport(Request $request): JsonResponse
    {
        return $this->insurance->premiumsReport($request);
    }

    public function unpaidPremiumsReport(Request $request): JsonResponse
    {
        return $this->insurance->unpaidPremiumsReport($request);
    }

    public function claimsReport(Request $request): JsonResponse
    {
        return $this->insurance->claimsReport($request);
    }

    public function expiringCoverageReport(Request $request): JsonResponse
    {
        return $this->insurance->expiringCoverageReport($request);
    }

    public function commissionsReport(Request $request): JsonResponse
    {
        return $this->insurance->commissionsReport($request);
    }

    public function remittancesReport(Request $request): JsonResponse
    {
        return $this->insurance->remittancesReport($request);
    }

    public function lossRatioReport(Request $request): JsonResponse
    {
        return $this->insurance->lossRatioReport($request);
    }

    public function cancellationsRefundsReport(Request $request): JsonResponse
    {
        return $this->insurance->cancellationsRefundsReport($request);
    }

    public function exportSubscriptions(Request $request): JsonResponse
    {
        return $this->insurance->exportSubscriptions($request);
    }

    public function exportPremiums(Request $request): JsonResponse
    {
        return $this->insurance->exportPremiums($request);
    }

    public function exportClaims(Request $request): JsonResponse
    {
        return $this->insurance->exportClaims($request);
    }

    public function exportCommissions(Request $request): JsonResponse
    {
        return $this->insurance->exportCommissions($request);
    }

    public function exportRemittances(Request $request): JsonResponse
    {
        return $this->insurance->exportRemittances($request);
    }

    public function exportCancellationsRefunds(Request $request): JsonResponse
    {
        return $this->insurance->exportCancellationsRefunds($request);
    }
}
