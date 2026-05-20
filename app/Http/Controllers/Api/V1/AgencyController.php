<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Agencies\AgencyWorkflow;
use App\Http\Controllers\BaseController;
use App\Http\Resources\AgencyCollection;
use App\Models\Agency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgencyController extends BaseController
{
    public function __construct(
        private readonly AgencyWorkflow $workflow,
    ) {}

    public function index(Request $request): AgencyCollection|JsonResponse
    {
        return $this->workflow->index($request);
    }

    public function store(Request $request): JsonResponse
    {
        return $this->workflow->store($request);
    }

    public function show(Request $request, Agency $agency): JsonResponse
    {
        return $this->workflow->show($request, $agency);
    }

    public function update(Request $request, Agency $agency): JsonResponse
    {
        return $this->workflow->update($request, $agency);
    }

    public function updateStatus(Request $request, Agency $agency): JsonResponse
    {
        return $this->workflow->updateStatus($request, $agency);
    }

    public function destroy(Request $request, Agency $agency): JsonResponse
    {
        return $this->workflow->destroy($request, $agency);
    }

    public function updateManager(Request $request, Agency $agency): JsonResponse
    {
        return $this->workflow->updateManager($request, $agency);
    }
}
