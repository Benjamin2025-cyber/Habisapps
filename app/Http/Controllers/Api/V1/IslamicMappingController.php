<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\IslamicFinance\IslamicMappingApprovalWorkflow;
use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IslamicMappingController extends BaseController
{
    public function __construct(private readonly IslamicMappingApprovalWorkflow $workflow) {}

    public function index(Request $request): JsonResponse
    {
        return $this->workflow->index($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->workflow->store($request);
    }

    public function show(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->workflow->show($request, $mappingPublicId);
    }

    public function update(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->workflow->updateDraft($request, $mappingPublicId);
    }

    public function submit(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->workflow->submit($request, $mappingPublicId);
    }

    public function approve(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->workflow->approve($request, $mappingPublicId);
    }

    public function reject(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->workflow->reject($request, $mappingPublicId);
    }

    public function suspend(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->workflow->suspend($request, $mappingPublicId);
    }

    public function revoke(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->workflow->revoke($request, $mappingPublicId);
    }

    public function archive(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->workflow->archive($request, $mappingPublicId);
    }
}
