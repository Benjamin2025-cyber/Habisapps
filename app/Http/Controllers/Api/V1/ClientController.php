<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use Dedoc\Scramble\Attributes\Response;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreClientRequest;
use App\Http\Requests\Api\V1\UpdateClientKycStatusRequest;
use App\Http\Requests\Api\V1\UpdateClientRequest;
use App\Http\Resources\ClientKycReviewResource;
use App\Http\Resources\ClientResource;
use App\Models\Agency;
use App\Models\Client;
use App\Models\ClientIdentityDocument;
use App\Models\ClientKycReview;
use App\Models\Document;
use App\Models\User;
use App\Support\References\ReferenceNumberGenerator;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ClientController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly ReferenceNumberGenerator $referenceNumberGenerator,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    /**
     * List CRM clients.
     *
     * Returns a paginated client list scoped by agency permissions.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{clients: array<int, \App\Http\Resources\ClientResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}'
    )]
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('crm.clients.view')) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = Client::query()
            ->with(['agency', 'prospector', 'collectionAgent'])
            ->latest();

        if (! $this->shouldUseInstitutionScope($actor, $request)) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $query->where('agency_id', $agencyId);
        }

        $this->applySafeFilters($query, $request, $actor);
        $clients = $query->paginate($perPage);

        if ($actor->hasPermissionTo('crm.pii.view')) {
            $this->securityAudit->record('crm.client.pii_list_viewed', actor: $actor, properties: [
                'scope' => $this->shouldUseInstitutionScope($actor, $request) ? 'institution' : 'agency',
                'results' => $clients->total(),
            ], request: $request);
        }

        return $this->respondSuccess([
            'clients' => ClientResource::collection($clients->getCollection())->resolve($request),
        ], meta: [
            'pagination' => [
                'current_page' => $clients->currentPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
                'last_page' => $clients->lastPage(),
            ],
        ]);
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
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $agency = $this->resolveCreateAgency($actor, $request->input('agency_public_id'));
        if (! $agency instanceof Agency) {
            return $this->respondForbidden('Client can only be created within your agency scope.');
        }

        try {
            $prospectorId = $this->resolveStaffReferenceInAgency(
                $agency->id,
                $request->input('prospector_public_id'),
                'prospector_public_id',
            );
            $collectionAgentId = $this->resolveStaffReferenceInAgency(
                $agency->id,
                $request->input('collection_agent_public_id'),
                'collection_agent_public_id',
            );
        } catch (ValidationException $exception) {
            return $this->respondUnprocessable(errors: $exception->errors());
        }

        $client = DB::transaction(function () use ($request, $agency, $prospectorId, $collectionAgentId): Client {
            $clientReference = $this->referenceNumberGenerator->reserve('client');

            return Client::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agency->id,
                'prospector_id' => $prospectorId,
                'collection_agent_id' => $collectionAgentId,
                'client_reference' => $clientReference,
                'first_name' => $request->string('first_name')->toString(),
                'last_name' => $request->string('last_name')->toString(),
                'middle_name' => $request->input('middle_name'),
                'date_of_birth' => $request->input('date_of_birth'),
                'place_of_birth' => $request->input('place_of_birth'),
                'gender' => $request->input('gender'),
                'phone_number' => $request->input('phone_number'),
                'email' => $request->input('email'),
                'address_line_1' => $request->input('address_line_1'),
                'address_line_2' => $request->input('address_line_2'),
                'city' => $request->input('city'),
                'region' => $request->input('region'),
                'occupation' => $request->input('occupation'),
                'employer_name' => $request->input('employer_name'),
                'collection_type' => $request->input('collection_type'),
                'collection_frequency' => $request->input('collection_frequency'),
                'collection_target_amount' => $request->input('collection_target_amount'),
                'status' => $request->input('status', Client::STATUS_ACTIVE),
                'kyc_status' => Client::KYC_STATUS_DRAFT,
                'onboarded_on' => $request->input('onboarded_on'),
            ]);
        });

        $this->securityAudit->record('crm.client.created', actor: $actor, subject: $client, properties: [
            'agency_public_id' => $agency->public_id,
            'client_reference' => $client->client_reference,
        ], request: $request);

        return $this->respondCreated([
            'client' => ClientResource::make($client->loadMissing(['agency', 'prospector', 'collectionAgent']))->resolve($request),
        ], 'Client created successfully');
    }

    /**
     * Show a CRM client.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{client: \App\Http\Resources\ClientResource}, errors: null, meta: null}'
    )]
    public function show(Request $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('crm.clients.view') || ! $this->canReadClient($actor, $client)) {
            return $this->respondForbidden();
        }

        if ($actor->hasPermissionTo('crm.pii.view')) {
            $this->securityAudit->record('crm.client.pii_viewed', actor: $actor, subject: $client, properties: [
                'client_public_id' => $client->public_id,
            ], request: $request);
        }

        return $this->respondSuccess([
            'client' => ClientResource::make($client->loadMissing(['agency', 'prospector', 'collectionAgent']))->resolve($request),
        ]);
    }

    /**
     * Update a CRM client.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{client: \App\Http\Resources\ClientResource}, errors: null, meta: null}'
    )]
    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('crm.clients.update') || ! $this->canManageClient($actor, $client)) {
            return $this->respondForbidden();
        }

        try {
            $prospectorId = $this->resolveStaffReferenceInAgency(
                $client->agency_id,
                $request->input('prospector_public_id'),
                'prospector_public_id',
                true,
            );
            $collectionAgentId = $this->resolveStaffReferenceInAgency(
                $client->agency_id,
                $request->input('collection_agent_public_id'),
                'collection_agent_public_id',
                true,
            );
        } catch (ValidationException $exception) {
            return $this->respondUnprocessable(errors: $exception->errors());
        }

        $safe = $request->safe();
        $attributes = $safe->only([
            'first_name',
            'last_name',
            'middle_name',
            'date_of_birth',
            'place_of_birth',
            'gender',
            'phone_number',
            'email',
            'address_line_1',
            'address_line_2',
            'city',
            'region',
            'occupation',
            'employer_name',
            'collection_type',
            'collection_frequency',
            'collection_target_amount',
            'status',
            'onboarded_on',
        ]);

        if (array_key_exists('prospector_public_id', $request->all())) {
            $attributes['prospector_id'] = $prospectorId;
        }

        if (array_key_exists('collection_agent_public_id', $request->all())) {
            $attributes['collection_agent_id'] = $collectionAgentId;
        }

        $client->update($attributes);

        $this->securityAudit->record('crm.client.updated', actor: $actor, subject: $client, properties: [
            'changed_fields' => array_keys($attributes),
        ], request: $request);

        return $this->respondSuccess([
            'client' => ClientResource::make($client->refresh()->loadMissing(['agency', 'prospector', 'collectionAgent']))->resolve($request),
        ], 'Client updated successfully');
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
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $action = $request->string('action')->toString();
        if (! $this->canApplyKycAction($actor, $client, $action)) {
            return $this->respondForbidden();
        }

        $allowExpiredIdentityOverride = $request->boolean('force_override_expired_identity', false);
        if ($allowExpiredIdentityOverride && ! $actor->hasPermissionTo('crm.kyc.override.expired_identity')) {
            return $this->respondForbidden('Expired identity override requires explicit permission.');
        }

        $transition = $this->kycTransitionTarget($action);
        if ($transition === null) {
            return $this->respondUnprocessable('Unsupported KYC action.');
        }

        if ($client->kyc_status === $transition) {
            return $this->respondSuccess([
                'client' => ClientResource::make($client->loadMissing(['agency', 'prospector', 'collectionAgent']))->resolve($request),
            ], 'KYC status already applied.');
        }

        if (! $this->isKycTransitionAllowed($client->kyc_status, $transition)) {
            return $this->respondUnprocessable('Invalid KYC transition.');
        }

        if ($transition === Client::KYC_STATUS_VERIFIED
            && ! $this->hasVerifiedIdentityEvidence($client, $allowExpiredIdentityOverride)) {
            return $this->respondUnprocessable('Client must have at least one active verified identity document before KYC verification.');
        }

        $previousStatus = $client->kyc_status;
        $reason = $request->input('reason');
        $comment = $request->input('comment');

        DB::transaction(function () use ($client, $transition, $actor, $reason, $comment, $previousStatus): void {
            $update = [
                'kyc_status' => $transition,
            ];

            if ($transition === Client::KYC_STATUS_PENDING_REVIEW) {
                $update['kyc_submitted_at'] = now();
            }

            if ($transition === Client::KYC_STATUS_VERIFIED) {
                $update['kyc_verified_at'] = now();
                $update['kyc_verified_by_user_id'] = $actor->id;
                $update['kyc_rejected_at'] = null;
                $update['kyc_rejection_reason'] = null;
            }

            if ($transition === Client::KYC_STATUS_REJECTED) {
                $update['kyc_rejected_at'] = now();
                $update['kyc_rejection_reason'] = is_string($reason) ? $reason : null;
            }

            if ($transition === Client::KYC_STATUS_SUSPENDED) {
                $update['kyc_suspended_at'] = now();
            }

            if ($transition === Client::KYC_STATUS_ARCHIVED) {
                $update['kyc_archived_at'] = now();
                $update['status'] = Client::STATUS_ARCHIVED;
            }

            $client->update($update);

            ClientKycReview::query()->create([
                'public_id' => (string) Str::ulid(),
                'client_id' => $client->id,
                'agency_id' => $client->agency_id,
                'previous_kyc_status' => $previousStatus,
                'new_kyc_status' => $transition,
                'reason' => is_string($reason) ? $reason : null,
                'comment' => is_string($comment) ? $comment : null,
                'acted_by_user_id' => $actor->id,
            ]);
        });

        $this->securityAudit->record('crm.client.kyc_status_changed', actor: $actor, subject: $client, properties: [
            'previous_kyc_status' => $previousStatus,
            'new_kyc_status' => $transition,
        ], request: $request);

        return $this->respondSuccess([
            'client' => ClientResource::make($client->refresh()->loadMissing(['agency', 'prospector', 'collectionAgent']))->resolve($request),
        ], 'Client KYC status updated successfully');
    }

    /**
     * List client KYC review history.
     *
     * @authenticated
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{reviews: array<int, \App\Http\Resources\ClientKycReviewResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}'
    )]
    public function kycReviews(Request $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('crm.reviews.view') || ! $this->canReadClient($actor, $client)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $reviews = ClientKycReview::query()
            ->with(['client', 'agency', 'actedBy'])
            ->where('client_id', $client->id)
            ->latest('created_at')
            ->paginate($perPage);

        return $this->respondSuccess([
            'reviews' => ClientKycReviewResource::collection($reviews->getCollection())->resolve($request),
        ], meta: [
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
            ],
        ]);
    }

    private function shouldUseInstitutionScope(User $actor, Request $request): bool
    {
        return $request->query('scope') === 'all' && $this->hasInstitutionReadScope($actor);
    }

    /**
     * @param Builder<Client> $query
     */
    private function applySafeFilters(Builder $query, Request $request, User $actor): void
    {
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $kycStatus = $request->query('kyc_status');
        if (is_string($kycStatus) && $kycStatus !== '') {
            $query->where('kyc_status', $kycStatus);
        }

        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return;
        }

        $term = trim($search);
        $query->where(function (Builder $builder) use ($actor, $term): void {
            $builder
                ->where('client_reference', 'ilike', '%'.$term.'%')
                ->orWhere('first_name', 'ilike', '%'.$term.'%')
                ->orWhere('last_name', 'ilike', '%'.$term.'%');

            if ($actor->hasPermissionTo('crm.pii.view')) {
                $builder
                    ->orWhere('phone_number', 'ilike', '%'.$term.'%')
                    ->orWhere('email', 'ilike', '%'.$term.'%');
            }
        });
    }

    private function resolveCreateAgency(User $actor, mixed $requestedAgencyPublicId): ?Agency
    {
        if ($actor->hasRole('platform-admin')) {
            if (! is_string($requestedAgencyPublicId) || $requestedAgencyPublicId === '') {
                return null;
            }

            return Agency::query()->where('public_id', $requestedAgencyPublicId)->first();
        }

        if ($actor->hasPermissionTo('crm.scope.institution.manage')) {
            if (! is_string($requestedAgencyPublicId) || $requestedAgencyPublicId === '') {
                return null;
            }

            return Agency::query()->where('public_id', $requestedAgencyPublicId)->first();
        }

        $actorAgencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($actorAgencyId === null) {
            return null;
        }

        if (is_string($requestedAgencyPublicId) && $requestedAgencyPublicId !== '') {
            $agency = Agency::query()->where('public_id', $requestedAgencyPublicId)->first();
            if (! $agency instanceof Agency || $agency->id !== $actorAgencyId) {
                return null;
            }
        }

        return Agency::query()->whereKey($actorAgencyId)->first();
    }

    private function resolveStaffReferenceInAgency(int $agencyId, mixed $publicId, string $field, bool $allowNull = false): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return $allowNull ? null : null;
        }

        $user = User::query()->where('public_id', $publicId)->first();
        if (! $user instanceof User) {
            throw ValidationException::withMessages([$field => 'Selected staff reference is invalid.']);
        }

        if ($user->status !== User::STATUS_ACTIVE || $this->staffAgencyScope->currentAgencyId($user) !== $agencyId) {
            throw ValidationException::withMessages([$field => 'Selected staff must be active in the same agency.']);
        }

        return $user->id;
    }

    private function canApplyKycAction(User $actor, Client $client, string $action): bool
    {
        return match ($action) {
            'submit' => $actor->hasPermissionTo('crm.kyc.submit') && $this->canManageClient($actor, $client),
            'verify' => $actor->hasPermissionTo('crm.kyc.verify') && $this->canReviewClient($actor, $client),
            'reject' => $actor->hasPermissionTo('crm.kyc.reject') && $this->canReviewClient($actor, $client),
            'suspend' => $actor->hasPermissionTo('crm.kyc.review') && $this->canReviewClient($actor, $client),
            'archive' => $actor->hasPermissionTo('crm.clients.archive') && $this->canManageClient($actor, $client),
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

    private function kycTransitionTarget(string $action): ?string
    {
        return match ($action) {
            'submit' => Client::KYC_STATUS_PENDING_REVIEW,
            'verify' => Client::KYC_STATUS_VERIFIED,
            'reject' => Client::KYC_STATUS_REJECTED,
            'suspend' => Client::KYC_STATUS_SUSPENDED,
            'archive' => Client::KYC_STATUS_ARCHIVED,
            default => null,
        };
    }

    private function isKycTransitionAllowed(string $current, string $target): bool
    {
        $allowed = [
            Client::KYC_STATUS_DRAFT => [
                Client::KYC_STATUS_PENDING_REVIEW,
                Client::KYC_STATUS_ARCHIVED,
            ],
            Client::KYC_STATUS_PENDING_REVIEW => [
                Client::KYC_STATUS_VERIFIED,
                Client::KYC_STATUS_REJECTED,
                Client::KYC_STATUS_ARCHIVED,
                Client::KYC_STATUS_SUSPENDED,
            ],
            Client::KYC_STATUS_REJECTED => [
                Client::KYC_STATUS_PENDING_REVIEW,
                Client::KYC_STATUS_ARCHIVED,
            ],
            Client::KYC_STATUS_VERIFIED => [
                Client::KYC_STATUS_SUSPENDED,
                Client::KYC_STATUS_ARCHIVED,
            ],
            Client::KYC_STATUS_SUSPENDED => [
                Client::KYC_STATUS_PENDING_REVIEW,
                Client::KYC_STATUS_ARCHIVED,
            ],
            Client::KYC_STATUS_ARCHIVED => [],
        ];

        return in_array($target, $allowed[$current] ?? [], true);
    }

    private function hasVerifiedIdentityEvidence(Client $client, bool $forceOverrideExpiredIdentity): bool
    {
        $query = DB::table('client_identity_documents')
            ->join('documents', 'documents.id', '=', 'client_identity_documents.document_id')
            ->where('client_id', $client->id)
            ->where('client_identity_documents.agency_id', $client->agency_id)
            ->where('client_identity_documents.status', ClientIdentityDocument::STATUS_ACTIVE)
            ->where('client_identity_documents.verification_status', ClientIdentityDocument::VERIFICATION_VERIFIED)
            ->whereNull('client_identity_documents.archived_at')
            ->where('documents.agency_id', $client->agency_id)
            ->where('documents.status', 'active')
            ->whereIn('documents.category', ['kyc', 'identity', 'proof_of_address'])
            ->whereExists(function ($mediaQuery): void {
                $mediaQuery
                    ->selectRaw('1')
                    ->from('media')
                    ->whereColumn('media.model_id', 'documents.id')
                    ->where('media.model_type', Document::class)
                    ->where('media.collection_name', 'kyc_documents');
            });

        if (! $forceOverrideExpiredIdentity) {
            $today = now()->toDateString();
            $query->where(function ($builder) use ($today): void {
                $builder->whereNull('client_identity_documents.expires_on')
                    ->orWhere('client_identity_documents.expires_on', '>=', $today);
            });
        }

        return $query->exists();
    }
}
