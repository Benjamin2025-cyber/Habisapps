<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinanceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicShariaAuthorityController extends BaseController
{
    public function __construct(
        private readonly IslamicFinanceWorkflowControllerAdapter $islamic,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->islamic->indexAuthorities($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->islamic->storeAuthority($request);
    }

    public function show(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->islamic->showAuthority($request, $authorityPublicId);
    }

    public function update(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->islamic->updateAuthority($request, $authorityPublicId);
    }

    public function activate(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->islamic->activateAuthority($request, $authorityPublicId);
    }

    public function suspend(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->islamic->suspendAuthority($request, $authorityPublicId);
    }

    public function revoke(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->islamic->revokeAuthority($request, $authorityPublicId);
    }

    public function retire(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->islamic->retireAuthority($request, $authorityPublicId);
    }

    public function storeMember(Request $request, string $authorityPublicId): JsonResponse
    {
        return $this->islamic->storeAuthorityMember($request, $authorityPublicId);
    }

    public function updateMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        return $this->islamic->updateAuthorityMember($request, $authorityPublicId, $memberPublicId);
    }

    public function suspendMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        return $this->islamic->suspendAuthorityMember($request, $authorityPublicId, $memberPublicId);
    }

    public function revokeMember(Request $request, string $authorityPublicId, string $memberPublicId): JsonResponse
    {
        return $this->islamic->revokeAuthorityMember($request, $authorityPublicId, $memberPublicId);
    }
}
