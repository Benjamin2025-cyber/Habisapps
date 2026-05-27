<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinanceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicFinanceController extends BaseController
{
    public function __construct(private readonly IslamicFinanceWorkflowControllerAdapter $islamic) {}

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->islamic->storeProduct($request);
    }

    public function indexProductFamilies(Request $request): JsonResponse
    {
        return $this->islamic->indexProductFamilies($request);
    }

    public function showProductFamily(Request $request, string $familyCode): JsonResponse
    {
        return $this->islamic->showProductFamily($request, $familyCode);
    }

    public function showProductReadiness(Request $request, string $productPublicId): JsonResponse
    {
        return $this->islamic->showProductReadiness($request, $productPublicId);
    }

    public function listProductReadinessSnapshots(Request $request, string $productPublicId): JsonResponse
    {
        return $this->islamic->listProductReadinessSnapshots($request, $productPublicId);
    }

    public function storeComplianceReview(Request $request, string $productPublicId): JsonResponse
    {
        return $this->islamic->storeComplianceReview($request, $productPublicId);
    }

    public function reviewCompliance(Request $request, string $reviewPublicId): JsonResponse
    {
        return $this->islamic->reviewCompliance($request, $reviewPublicId);
    }

    public function storeFinancing(Request $request): JsonResponse
    {
        return $this->islamic->storeFinancing($request);
    }

    public function storeInstallments(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->storeInstallments($request, $financingPublicId);
    }

    public function approveFinancing(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->islamic->approveFinancing($request, $financingPublicId);
    }

    public function listComplianceCases(Request $request): JsonResponse
    {
        return $this->islamic->listComplianceCases($request);
    }

    public function showComplianceCase(Request $request, string $casePublicId): JsonResponse
    {
        return $this->islamic->showComplianceCase($request, $casePublicId);
    }

    public function showComplianceCaseTimeline(Request $request, string $casePublicId): JsonResponse
    {
        return $this->islamic->showComplianceCaseTimeline($request, $casePublicId);
    }

    public function complianceCaseSummary(Request $request): JsonResponse
    {
        return $this->islamic->complianceCaseSummary($request);
    }

    public function indexScreeningPolicies(Request $request): JsonResponse
    {
        return $this->islamic->indexScreeningPolicies($request);
    }

    public function storeScreeningPolicy(Request $request): JsonResponse
    {
        return $this->islamic->storeScreeningPolicy($request);
    }

    public function showScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->islamic->showScreeningPolicy($request, $policyPublicId);
    }

    public function updateScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->islamic->updateScreeningPolicy($request, $policyPublicId);
    }

    public function storeScreeningPolicyRule(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->islamic->storeScreeningPolicyRule($request, $policyPublicId);
    }

    public function updateScreeningPolicyRule(Request $request, string $policyPublicId, string $rulePublicId): JsonResponse
    {
        return $this->islamic->updateScreeningPolicyRule($request, $policyPublicId, $rulePublicId);
    }

    public function deleteScreeningPolicyRule(Request $request, string $policyPublicId, string $rulePublicId): JsonResponse
    {
        return $this->islamic->deleteScreeningPolicyRule($request, $policyPublicId, $rulePublicId);
    }

    public function activateScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->islamic->activateScreeningPolicy($request, $policyPublicId);
    }

    public function suspendScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->islamic->suspendScreeningPolicy($request, $policyPublicId);
    }

    public function revokeScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->islamic->revokeScreeningPolicy($request, $policyPublicId);
    }

    public function archiveScreeningPolicy(Request $request, string $policyPublicId): JsonResponse
    {
        return $this->islamic->archiveScreeningPolicy($request, $policyPublicId);
    }

    public function evaluateScreening(Request $request): JsonResponse
    {
        return $this->islamic->evaluateScreening($request);
    }

    public function listScreeningResults(Request $request): JsonResponse
    {
        return $this->islamic->listScreeningResults($request);
    }

    public function showScreeningResult(Request $request, string $resultPublicId): JsonResponse
    {
        return $this->islamic->showScreeningResult($request, $resultPublicId);
    }
}
