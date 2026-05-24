<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinanceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicRegulatorySignoffController extends BaseController
{
    public function __construct(
        private readonly IslamicFinanceWorkflowControllerAdapter $islamic,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->islamic->indexSignoffs($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->islamic->storeSignoff($request);
    }

    public function show(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->islamic->showSignoff($request, $signoffPublicId);
    }

    public function update(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->islamic->updateSignoff($request, $signoffPublicId);
    }

    public function activate(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->islamic->activateSignoff($request, $signoffPublicId);
    }

    public function suspend(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->islamic->suspendSignoff($request, $signoffPublicId);
    }

    public function revoke(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->islamic->revokeSignoff($request, $signoffPublicId);
    }

    public function retire(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->islamic->retireSignoff($request, $signoffPublicId);
    }

    public function link(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->islamic->linkSignoff($request, $signoffPublicId);
    }

    public function unlink(Request $request, string $signoffPublicId): JsonResponse
    {
        return $this->islamic->unlinkSignoff($request, $signoffPublicId);
    }
}
