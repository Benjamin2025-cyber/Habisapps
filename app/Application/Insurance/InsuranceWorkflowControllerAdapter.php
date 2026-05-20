<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InsuranceWorkflowControllerAdapter extends BaseController
{
    public function __construct(
        private readonly InsurancePremiumWorkflow $insurancePremiumWorkflow,
        private readonly InsuranceClaimWorkflow $insuranceClaimWorkflow,
        private readonly InsurancePolicyChangeWorkflow $insurancePolicyChangeWorkflow,
        private readonly InsuranceProductWorkflow $insuranceProductWorkflow,
        private readonly InsuranceSubscriptionWorkflow $insuranceSubscriptionWorkflow,
        private readonly InsuranceRemittanceWorkflow $insuranceRemittanceWorkflow,
        private readonly InsuranceReportWorkflow $insuranceReportWorkflow,
        private readonly InsuranceExportWorkflow $insuranceExportWorkflow,
    ) {}

    public function storePartner(Request $request): JsonResponse
    {
        return $this->insuranceProductWorkflow->storePartner($request);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->insuranceProductWorkflow->storeProduct($request);
    }

    public function storeClaim(Request $request): JsonResponse
    {
        return $this->insuranceClaimWorkflow->store($request);
    }

    public function storeSubscription(Request $request): JsonResponse
    {
        return $this->insuranceSubscriptionWorkflow->store($request);
    }

    public function storePremiumAssessment(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insurancePremiumWorkflow->storeAssessment($request, $subscriptionPublicId);
    }

    public function collectPremiumFromAccount(Request $request, string $assessmentPublicId): JsonResponse
    {
        return $this->insurancePremiumWorkflow->collectFromAccount($request, $assessmentPublicId);
    }

    public function collectPremiumCash(Request $request, string $assessmentPublicId): JsonResponse
    {
        return $this->insurancePremiumWorkflow->collectCash($request, $assessmentPublicId);
    }

    public function attachClaimDocument(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insuranceClaimWorkflow->attachDocument($request, $claimPublicId);
    }

    public function decideClaim(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insuranceClaimWorkflow->blockDirectDecision($request, $claimPublicId);
    }

    public function postClaimSettlement(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insuranceClaimWorkflow->postSettlement($request, $claimPublicId);
    }

    public function activeSubscriptionsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->activeSubscriptions($request);
    }

    public function premiumsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->premiums($request);
    }

    public function unpaidPremiumsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->unpaidPremiums($request);
    }

    public function claimsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->claims($request);
    }

    public function expiringCoverageReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->expiringCoverage($request);
    }

    public function requestClaimDecision(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insuranceClaimWorkflow->requestDecision($request, $claimPublicId);
    }

    public function reviewClaimDecision(Request $request, string $decisionPublicId): JsonResponse
    {
        return $this->insuranceClaimWorkflow->reviewDecision($request, $decisionPublicId);
    }

    // -------------------------------------------------------------------------
    // A8: Insurance Product Rule Versioning
    // -------------------------------------------------------------------------

    public function storeProductRuleVersion(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insuranceProductWorkflow->storeRuleVersion($request, $productPublicId);
    }

    public function approveProductRuleVersion(Request $request, string $versionPublicId): JsonResponse
    {
        return $this->insuranceProductWorkflow->approveRuleVersion($request, $versionPublicId);
    }

    public function activateProduct(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insuranceProductWorkflow->activateProduct($request, $productPublicId);
    }

    public function storeClaimEvidenceConfig(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insuranceProductWorkflow->storeEvidenceConfig($request, $productPublicId);
    }

    // -------------------------------------------------------------------------
    // A9: Recurring Premium Schedules & Renewal Lifecycle
    // -------------------------------------------------------------------------

    public function activateSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insuranceSubscriptionWorkflow->activate($request, $subscriptionPublicId);
    }

    public function generatePremiumBatch(Request $request): JsonResponse
    {
        return $this->insuranceSubscriptionWorkflow->generatePremiumBatch($request);
    }

    public function renewSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insuranceSubscriptionWorkflow->renew($request, $subscriptionPublicId);
    }

    // -------------------------------------------------------------------------
    // A10: Endorsements, Cancellations, Refunds, Reversals
    // -------------------------------------------------------------------------

    public function storeEndorsement(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insurancePolicyChangeWorkflow->storeEndorsement($request, $subscriptionPublicId);
    }

    public function approveEndorsement(Request $request, string $endorsementPublicId): JsonResponse
    {
        return $this->insurancePolicyChangeWorkflow->approveEndorsement($request, $endorsementPublicId);
    }

    public function cancelSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insurancePolicyChangeWorkflow->cancelSubscription($request, $subscriptionPublicId);
    }

    public function reviewCancellation(Request $request, string $cancellationPublicId): JsonResponse
    {
        return $this->insurancePolicyChangeWorkflow->reviewCancellation($request, $cancellationPublicId);
    }

    public function reversePremiumPayment(Request $request, string $paymentPublicId): JsonResponse
    {
        return $this->insurancePremiumWorkflow->reversePayment($request, $paymentPublicId);
    }

    public function reverseClaimSettlement(Request $request, string $claimPublicId): JsonResponse
    {
        return $this->insuranceClaimWorkflow->reverseSettlement($request, $claimPublicId);
    }

    // -------------------------------------------------------------------------
    // A11: Insurer Remittance & Commission Accounting
    // -------------------------------------------------------------------------

    public function storeRemittanceBatch(Request $request): JsonResponse
    {
        return $this->insuranceRemittanceWorkflow->storeBatch($request);
    }

    public function approveRemittanceBatch(Request $request, string $batchPublicId): JsonResponse
    {
        return $this->insuranceRemittanceWorkflow->approveBatch($request, $batchPublicId);
    }

    public function commissionsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->commissions($request);
    }

    public function remittancesReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->remittances($request);
    }

    public function lossRatioReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->lossRatio($request);
    }

    public function cancellationsRefundsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->cancellationsRefunds($request);
    }

    // -------------------------------------------------------------------------
    // A13: Insurance Exports
    // -------------------------------------------------------------------------

    public function exportSubscriptions(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->subscriptions($request);
    }

    public function exportPremiums(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->premiums($request);
    }

    public function exportClaims(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->claims($request);
    }

    public function exportCommissions(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->commissions($request);
    }

    public function exportRemittances(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->remittances($request);
    }

    public function exportCancellationsRefunds(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->cancellationsRefunds($request);
    }
}
