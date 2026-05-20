<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Crm\ClientWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreClientRequest;
use App\Http\Requests\Api\V1\UpdateClientKycStatusRequest;
use App\Http\Requests\Api\V1\UpdateClientRequest;
use App\Http\Resources\ClientCollection;
use App\Models\Client;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClientController extends BaseController
{
    public function __construct(
        private readonly ClientWorkflowControllerAdapter $client,
    ) {}

    /**
     * List CRM clients.
     *
     * Returns a paginated client list scoped by agency permissions.
     *
     * @authenticated
     *
     * @response ClientCollection
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{clients: array<int, \App\Http\Resources\ClientResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}'
    )]
    public function index(Request $request): ClientCollection|JsonResponse
    {
        return $this->client->index($request);
    }

    /**
     * Create a CRM client.
     *
     * Creates a client profile inside the caller agency scope and reserves a client reference.
     *
     * @authenticated
     */
    #[Response(
        status: 201,
        type: 'array{success: bool, message: string, data: array{client: \App\Http\Resources\ClientResource}, errors: null, meta: null}'
    )]
    public function store(StoreClientRequest $request): JsonResponse
    {
        return $this->client->store($request);
    }

    /**
     * Show a CRM client.
     *
     * @authenticated
     *
     * @response ClientResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{client: \App\Http\Resources\ClientResource}, errors: null, meta: null}'
    )]
    public function show(Request $request, Client $client): JsonResponse
    {
        return $this->client->show($request, $client);
    }

    /**
     * Update a CRM client.
     *
     * @authenticated
     *
     * @response ClientResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{client: \App\Http\Resources\ClientResource}, errors: null, meta: null}'
    )]
    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        return $this->client->update($request, $client);
    }

    /**
     * Transition client KYC status.
     *
     * Applies controlled KYC actions: submit, verify, reject, suspend, archive.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{client: \App\Http\Resources\ClientResource}, errors: null, meta: null}'
    )]
    public function updateKycStatus(UpdateClientKycStatusRequest $request, Client $client): JsonResponse
    {
        return $this->client->updateKycStatus($request, $client);
    }

    /**
     * List client KYC review history.
     *
     * @authenticated
     *
     * @response array<int, ClientKycReviewResource>
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{reviews: array<int, \App\Http\Resources\ClientKycReviewResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}'
    )]
    public function kycReviews(Request $request, Client $client): JsonResponse
    {
        return $this->client->kycReviews($request, $client);
    }
}
