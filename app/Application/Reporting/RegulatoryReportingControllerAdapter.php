<?php

declare(strict_types=1);

namespace App\Application\Reporting;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RegulatoryReportingControllerAdapter
{
    public function __construct(
        private readonly RegulatorySourceWorkflow $source,
        private readonly RegulatoryReportingWorkflow $reporting,
    ) {}

    public function storeSource(Request $request): JsonResponse
    {
        return $this->source->storeSource($request);
    }

    public function loadEmfAccounts(Request $request, string $sourcePublicId): JsonResponse
    {
        return $this->source->loadEmfAccounts($request, $sourcePublicId);
    }

    public function storeReportDefinitionVersion(Request $request): JsonResponse
    {
        return $this->reporting->storeReportDefinitionVersion($request);
    }

    public function reviewReportRun(Request $request, string $runPublicId): JsonResponse
    {
        return $this->reporting->reviewReportRun($request, $runPublicId);
    }

    public function submitReportRun(Request $request, string $runPublicId): JsonResponse
    {
        return $this->reporting->submitReportRun($request, $runPublicId);
    }

    public function inspectMapping(Request $request, string $operationCode): JsonResponse
    {
        return $this->reporting->inspectMapping($request, $operationCode);
    }
}
