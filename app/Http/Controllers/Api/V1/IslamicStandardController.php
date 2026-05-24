<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicFinanceWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicStandardController extends BaseController
{
    public function __construct(
        private readonly IslamicFinanceWorkflowControllerAdapter $islamic,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return $this->islamic->indexStandards($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->islamic->storeStandard($request);
    }

    public function show(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->showStandard($request, $standardPublicId);
    }

    public function update(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->updateStandard($request, $standardPublicId);
    }

    public function amend(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->amendStandard($request, $standardPublicId);
    }

    public function activate(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->activateStandard($request, $standardPublicId);
    }

    public function retire(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->retireStandard($request, $standardPublicId);
    }

    public function link(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->linkStandard($request, $standardPublicId);
    }

    public function unlink(Request $request, string $standardPublicId): JsonResponse
    {
        return $this->islamic->unlinkStandard($request, $standardPublicId);
    }
}
