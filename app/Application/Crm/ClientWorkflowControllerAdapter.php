<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Http\Requests\Api\V1\StoreClientRequest;
use App\Http\Requests\Api\V1\UpdateClientKycStatusRequest;
use App\Http\Requests\Api\V1\UpdateClientRequest;
use App\Http\Resources\ClientCollection;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClientWorkflowControllerAdapter
{
    public function __construct(
        private readonly ClientCrudWorkflow $crud,
        private readonly ClientKycWorkflow $kyc,
        private readonly ClientStatsWorkflow $clientStats,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        return $this->clientStats->stats($request);
    }

    public function index(Request $request): ClientCollection|JsonResponse
    {
        return $this->crud->index($request);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        return $this->crud->store($request);
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        return $this->crud->show($request, $client);
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        return $this->crud->update($request, $client);
    }

    public function updateKycStatus(UpdateClientKycStatusRequest $request, Client $client): JsonResponse
    {
        return $this->kyc->updateKycStatus($request, $client);
    }

    public function kycReviews(Request $request, Client $client): JsonResponse
    {
        return $this->kyc->kycReviews($request, $client);
    }
}
