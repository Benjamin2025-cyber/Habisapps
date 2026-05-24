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
        private readonly IslamicStandardWorkflow $standard,
        private readonly IslamicRegulatorySignoffWorkflow $signoff,
        private readonly IslamicShariaAuthorityWorkflow $authority,
    ) {}

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->product->storeProduct($request);
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

    public function storeFinancingAsset(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeFinancingAsset($request, $financingPublicId);
    }

    public function storeInstallments(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->storeInstallments($request, $financingPublicId);
    }

    public function approveFinancing(Request $request, string $financingPublicId): JsonResponse
    {
        return $this->financing->approveFinancing($request, $financingPublicId);
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
}
