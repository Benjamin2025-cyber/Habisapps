<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Dedoc\Scramble\Attributes\Response;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreClientGuarantorRequest;
use App\Http\Requests\Api\V1\UpdateClientGuarantorRequest;
use App\Http\Requests\Api\V1\UpdateClientGuarantorStatusRequest;
use App\Http\Resources\ClientGuarantorResource;
use App\Models\Client;
use App\Models\ClientGuarantor;
use App\Models\Document;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ClientGuarantorController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    /**
     * List client guarantors.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{guarantors: array<int, \App\Http\Resources\ClientGuarantorResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}'
    )]
    public function index(Request $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo('crm.guarantors.view')
            || ! $this->canReadClient($actor, $client)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $records = ClientGuarantor::query()
            ->with(['client', 'guarantorClient', 'document'])
            ->where('client_id', $client->id)
            ->where('agency_id', $client->agency_id)
            ->latest()
            ->paginate($perPage);

        return $this->respondSuccess([
            'guarantors' => ClientGuarantorResource::collection($records->getCollection())->resolve($request),
        ], meta: [
            'pagination' => [
                'current_page' => $records->currentPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
                'last_page' => $records->lastPage(),
            ],
        ]);
    }

    /**
     * Create a client guarantor.
     *
     * @authenticated
     */
    #[Response(
        status: 201,
        type: 'array{success: bool, message: string, data: array{guarantor: \App\Http\Resources\ClientGuarantorResource}, errors: null, meta: null}'
    )]
    public function store(StoreClientGuarantorRequest $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo('crm.guarantors.create')
            || ! $this->canManageClient($actor, $client)) {
            return $this->respondForbidden();
        }

        $guarantorClientId = $this->resolveGuarantorClientId($client, $request->input('guarantor_client_public_id'));
        if ($guarantorClientId === false) {
            return $this->respondUnprocessable('Guarantor client must be in the same agency and cannot match the client.');
        }

        $documentId = $this->resolveDocumentId($client, $request->input('document_public_id'));
        if ($documentId === false) {
            return $this->respondUnprocessable('Document attachment is invalid for this client.');
        }

        $record = ClientGuarantor::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $client->agency_id,
            'client_id' => $client->id,
            'guarantor_client_id' => is_int($guarantorClientId) ? $guarantorClientId : null,
            'guarantor_full_name' => $request->input('guarantor_full_name'),
            'guarantor_phone_number' => $request->input('guarantor_phone_number'),
            'relationship_type' => $request->input('relationship_type'),
            'status' => ClientGuarantor::STATUS_ACTIVE,
            'starts_on' => $request->input('starts_on'),
            'ends_on' => $request->input('ends_on'),
            'verification_status' => ClientGuarantor::VERIFICATION_PENDING,
            'document_id' => is_int($documentId) ? $documentId : null,
            'created_by_user_id' => $actor->id,
        ]);

        $this->securityAudit->record('crm.guarantor.created', actor: $actor, subject: $record, properties: [
            'client_public_id' => $client->public_id,
        ], request: $request);

        return $this->respondCreated([
            'guarantor' => ClientGuarantorResource::make($record->loadMissing(['client', 'guarantorClient', 'document']))->resolve($request),
        ], 'Client guarantor created successfully');
    }

    /**
     * Show a client guarantor.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{guarantor: \App\Http\Resources\ClientGuarantorResource}, errors: null, meta: null}'
    )]
    public function show(Request $request, Client $client, ClientGuarantor $guarantor): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo('crm.guarantors.view')
            || ! $this->canReadClient($actor, $client)) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($guarantor, $client)) {
            return $this->respondNotFound();
        }

        return $this->respondSuccess([
            'guarantor' => ClientGuarantorResource::make($guarantor->loadMissing(['client', 'guarantorClient', 'document']))->resolve($request),
        ]);
    }

    /**
     * Update a client guarantor.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{guarantor: \App\Http\Resources\ClientGuarantorResource}, errors: null, meta: null}'
    )]
    public function update(UpdateClientGuarantorRequest $request, Client $client, ClientGuarantor $guarantor): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo('crm.guarantors.update')
            || ! $this->canManageClient($actor, $client)) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($guarantor, $client)) {
            return $this->respondNotFound();
        }

        $attributes = $request->safe()->only([
            'guarantor_full_name',
            'guarantor_phone_number',
            'relationship_type',
            'starts_on',
            'ends_on',
        ]);

        if ($request->has('guarantor_client_public_id')) {
            $guarantorClientId = $this->resolveGuarantorClientId($client, $request->input('guarantor_client_public_id'));
            if ($guarantorClientId === false) {
                return $this->respondUnprocessable('Guarantor client must be in the same agency and cannot match the client.');
            }

            $attributes['guarantor_client_id'] = is_int($guarantorClientId) ? $guarantorClientId : null;
        }

        if ($request->has('document_public_id')) {
            $documentId = $this->resolveDocumentId($client, $request->input('document_public_id'));
            if ($documentId === false) {
                return $this->respondUnprocessable('Document attachment is invalid for this client.');
            }

            $attributes['document_id'] = is_int($documentId) ? $documentId : null;
        }

        if ($guarantor->verification_status === ClientGuarantor::VERIFICATION_VERIFIED
            && array_intersect(array_keys($attributes), ['guarantor_client_id', 'guarantor_full_name', 'guarantor_phone_number', 'document_id']) !== []) {
            $attributes['verification_status'] = ClientGuarantor::VERIFICATION_PENDING_REVIEW;
            $attributes['verified_at'] = null;
            $attributes['verified_by_user_id'] = null;
        }

        $guarantor->update($attributes);

        $this->securityAudit->record('crm.guarantor.updated', actor: $actor, subject: $guarantor, properties: [
            'changed_fields' => array_keys($attributes),
        ], request: $request);

        return $this->respondSuccess([
            'guarantor' => ClientGuarantorResource::make($guarantor->refresh()->loadMissing(['client', 'guarantorClient', 'document']))->resolve($request),
        ], 'Client guarantor updated successfully');
    }

    /**
     * Update client guarantor status.
     *
     * Supported actions: submit, verify, reject, archive, deactivate.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{guarantor: \App\Http\Resources\ClientGuarantorResource}, errors: null, meta: null}'
    )]
    public function updateStatus(UpdateClientGuarantorStatusRequest $request, Client $client, ClientGuarantor $guarantor): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($guarantor, $client)) {
            return $this->respondNotFound();
        }

        $action = $request->string('action')->toString();
        if (! $this->canApplyStatusAction($actor, $client, $action)) {
            return $this->respondForbidden();
        }

        if ($action === 'verify'
            && ($guarantor->created_by_user_id === $actor->id
                || $guarantor->document?->uploaded_by_user_id === $actor->id)
            && ! $actor->hasPermissionTo('crm.kyc.override.self_verify')) {
            return $this->respondForbidden('Uploader cannot verify their own guarantor evidence.');
        }

        if ($action === 'verify' && ! $this->hasLinkedDocumentEvidence($guarantor)) {
            return $this->respondUnprocessable('Guarantor verification requires linked KYC document evidence.');
        }

        $reason = $request->input('reason');
        $update = match ($action) {
            'submit' => [
                'verification_status' => ClientGuarantor::VERIFICATION_PENDING_REVIEW,
                'submitted_at' => now(),
            ],
            'verify' => [
                'verification_status' => ClientGuarantor::VERIFICATION_VERIFIED,
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
                'rejected_at' => null,
                'rejection_reason' => null,
            ],
            'reject' => [
                'verification_status' => ClientGuarantor::VERIFICATION_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => is_string($reason) ? $reason : null,
            ],
            'archive' => [
                'status' => ClientGuarantor::STATUS_ARCHIVED,
                'archived_at' => now(),
            ],
            'deactivate' => [
                'status' => ClientGuarantor::STATUS_INACTIVE,
            ],
            default => null,
        };

        if (! is_array($update)) {
            return $this->respondUnprocessable('Unsupported status action.');
        }

        $guarantor->update($update);

        $this->securityAudit->record('crm.guarantor.status_changed', actor: $actor, subject: $guarantor, properties: [
            'action' => $action,
            'status' => $guarantor->status,
            'verification_status' => $guarantor->verification_status,
        ], request: $request);

        return $this->respondSuccess([
            'guarantor' => ClientGuarantorResource::make($guarantor->refresh()->loadMissing(['client', 'guarantorClient', 'document']))->resolve($request),
        ], 'Client guarantor status updated successfully');
    }

    private function belongsToClient(ClientGuarantor $guarantor, Client $client): bool
    {
        return $guarantor->client_id === $client->id && $guarantor->agency_id === $client->agency_id;
    }

    private function resolveGuarantorClientId(Client $client, mixed $publicId): int|bool|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $guarantor = Client::query()->where('public_id', $publicId)->first();
        if (! $guarantor instanceof Client || $guarantor->agency_id !== $client->agency_id || $guarantor->id === $client->id) {
            return false;
        }

        return $guarantor->id;
    }

    private function resolveDocumentId(Client $client, mixed $publicId): int|bool|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $document = Document::query()
            ->where('public_id', $publicId)
            ->where('agency_id', $client->agency_id)
            ->where('status', Document::STATUS_ACTIVE)
            ->first();

        if (! $document instanceof Document
            || ! in_array($document->category, ['kyc', 'identity', 'proof_of_address'], true)
            || ! $document->hasMedia('kyc_documents')) {
            return false;
        }

        return $document->id;
    }

    private function canApplyStatusAction(User $actor, Client $client, string $action): bool
    {
        return match ($action) {
            'submit' => $actor->hasPermissionTo('crm.guarantors.update') && $this->canManageClient($actor, $client),
            'verify' => $actor->hasPermissionTo('crm.guarantors.verify') && $this->canReviewClient($actor, $client),
            'reject' => $actor->hasPermissionTo('crm.guarantors.reject') && $this->canReviewClient($actor, $client),
            'archive', 'deactivate' => $actor->hasPermissionTo('crm.guarantors.archive') && $this->canManageClient($actor, $client),
            default => false,
        };
    }

    private function canReadClient(User $actor, Client $client): bool
    {
        return $this->hasInstitutionReadScope($actor) || $actor->currentAgencyId() === $client->agency_id;
    }

    private function canReviewClient(User $actor, Client $client): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->hasPermissionTo('crm.scope.institution.review')
            || $actor->hasPermissionTo('crm.scope.institution.manage')
            || $actor->currentAgencyId() === $client->agency_id;
    }

    private function canManageClient(User $actor, Client $client): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->hasPermissionTo('crm.scope.institution.manage')
            || $actor->currentAgencyId() === $client->agency_id;
    }

    private function hasInstitutionReadScope(User $actor): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->hasPermissionTo('crm.scope.institution.read')
            || $actor->hasPermissionTo('crm.scope.institution.review')
            || $actor->hasPermissionTo('crm.scope.institution.manage');
    }

    private function hasLinkedDocumentEvidence(ClientGuarantor $guarantor): bool
    {
        $document = $guarantor->document;

        return $document instanceof Document
            && $document->status === Document::STATUS_ACTIVE
            && in_array($document->category, ['kyc', 'identity', 'proof_of_address'], true)
            && $document->hasMedia('kyc_documents');
    }
}
