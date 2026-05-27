<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicContractTemplateWorkflow;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicContractTemplateController extends BaseController
{
    public function __construct(private readonly IslamicContractTemplateWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        return $this->workflow->index($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->workflow->store($request);
    }

    public function show(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->workflow->show($request, $templatePublicId);
    }

    public function update(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->workflow->updateDraft($request, $templatePublicId);
    }

    public function submit(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->workflow->submit($request, $templatePublicId);
    }

    public function approve(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->workflow->approve($request, $templatePublicId);
    }

    public function suspend(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->workflow->suspend($request, $templatePublicId);
    }

    public function revoke(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->workflow->revoke($request, $templatePublicId);
    }

    public function retire(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->workflow->retire($request, $templatePublicId);
    }

    public function archive(Request $request, string $templatePublicId): JsonResponse
    {
        return $this->workflow->archive($request, $templatePublicId);
    }
}
