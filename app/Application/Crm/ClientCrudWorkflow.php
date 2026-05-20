<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreClientRequest;
use App\Http\Requests\Api\V1\UpdateClientRequest;
use App\Http\Resources\ClientCollection;
use App\Http\Resources\ClientResource;
use App\Models\Agency;
use App\Models\Client;
use App\Models\Document;
use App\Models\Sector;
use App\Models\SubSector;
use App\Models\User;
use App\Support\References\ReferenceNumberGenerator;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ClientCrudWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly ReferenceNumberGenerator $referenceNumberGenerator,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly CreateClient $createClient,
    ) {}

    public function index(Request $request): ClientCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', Client::class)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = Client::query()
            ->with(['agency', 'profilePhotoDocument', 'prospector', 'collectionAgent', 'sector', 'subSector'])
            ->latest();

        if (! $this->shouldUseInstitutionScope($actor, $request)) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $query->where('agency_id', $agencyId);
        }

        $this->applySafeFilters($query, $request, $actor);

        if ($actor->hasPermissionTo('crm.pii.view')) {
            $this->securityAudit->record('crm.client.pii_list_viewed', actor: $actor, properties: [
                'scope' => $this->shouldUseInstitutionScope($actor, $request) ? 'institution' : 'agency',
                'results' => 0,
            ], request: $request);
        }

        return new ClientCollection(
            $query->paginate($perPage)
        );
    }

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
            $profilePhotoDocumentId = $this->resolveProfilePhotoDocumentId(
                $agency->id,
                $request->input('profile_photo_document_public_id'),
            );
            [$sectorId, $subSectorId] = $this->resolveSectorClassification(
                $request->input('sector_public_id'),
                $request->input('sub_sector_public_id'),
            );
        } catch (ValidationException $exception) {
            return $this->respondUnprocessable(errors: $exception->errors());
        }

        $client = $this->createClient->execute($agency->id, [
            'profile_photo_document_id' => $profilePhotoDocumentId,
            'prospector_id' => $prospectorId,
            'collection_agent_id' => $collectionAgentId,
            'sector_id' => $sectorId,
            'sub_sector_id' => $subSectorId,
            'first_name' => $request->string('first_name')->toString(),
            'last_name' => $request->string('last_name')->toString(),
            'middle_name' => $request->input('middle_name'),
            'father_name' => $request->input('father_name'),
            'mother_name' => $request->input('mother_name'),
            'date_of_birth' => $request->input('date_of_birth'),
            'place_of_birth' => $request->input('place_of_birth'),
            'gender' => $request->input('gender'),
            'phone_number' => $request->input('phone_number'),
            'home_phone_number' => $request->input('home_phone_number'),
            'email' => $request->input('email'),
            'address_line_1' => $request->input('address_line_1'),
            'address_line_2' => $request->input('address_line_2'),
            'city' => $request->input('city'),
            'region' => $request->input('region'),
            'occupation' => $request->input('occupation'),
            'employer_name' => $request->input('employer_name'),
            'business_started_on' => $request->input('business_started_on'),
            'business_activity_started_on' => $request->input('business_activity_started_on'),
            'business_address_line_1' => $request->input('business_address_line_1'),
            'business_address_line_2' => $request->input('business_address_line_2'),
            'business_city' => $request->input('business_city'),
            'business_region' => $request->input('business_region'),
            'collection_type' => $request->input('collection_type'),
            'collection_frequency' => $request->input('collection_frequency'),
            'collection_target_amount' => $request->input('collection_target_amount'),
            'status' => $request->input('status', Client::STATUS_ACTIVE),
            'onboarded_on' => $request->input('onboarded_on'),
        ], $this->referenceNumberGenerator);

        $this->securityAudit->record('crm.client.created', actor: $actor, subject: $client, properties: [
            'agency_public_id' => $agency->public_id,
            'client_reference' => $client->client_reference,
        ], request: $request);

        return $this->respondCreated(
            ClientResource::make($client->loadMissing(['agency', 'profilePhotoDocument', 'prospector', 'collectionAgent', 'sector', 'subSector'])),
            'Client created successfully'
        );
    }

    public function show(Request $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $client)) {
            return $this->respondForbidden();
        }

        if ($actor->hasPermissionTo('crm.pii.view')) {
            $this->securityAudit->record('crm.client.pii_viewed', actor: $actor, subject: $client, properties: [
                'client_public_id' => $client->public_id,
            ], request: $request);
        }

        return $this->respondSuccess(
            ClientResource::make($client->loadMissing(['agency', 'profilePhotoDocument', 'prospector', 'collectionAgent', 'sector', 'subSector']))
        );
    }

    public function update(UpdateClientRequest $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $client)) {
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
            $profilePhotoDocumentId = $this->resolveProfilePhotoDocumentId(
                $client->agency_id,
                $request->input('profile_photo_document_public_id'),
                true,
            );
            [$sectorId, $subSectorId] = $this->resolveSectorClassification(
                $request->input('sector_public_id'),
                $request->input('sub_sector_public_id'),
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
            'father_name',
            'mother_name',
            'date_of_birth',
            'place_of_birth',
            'gender',
            'phone_number',
            'home_phone_number',
            'email',
            'address_line_1',
            'address_line_2',
            'city',
            'region',
            'occupation',
            'employer_name',
            'business_started_on',
            'business_activity_started_on',
            'business_address_line_1',
            'business_address_line_2',
            'business_city',
            'business_region',
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

        if (array_key_exists('profile_photo_document_public_id', $request->all())) {
            $attributes['profile_photo_document_id'] = $profilePhotoDocumentId;
        }

        if (array_key_exists('sector_public_id', $request->all())) {
            $attributes['sector_id'] = $sectorId;
            if (! array_key_exists('sub_sector_public_id', $request->all())) {
                $attributes['sub_sector_id'] = null;
            }
        }

        if (array_key_exists('sub_sector_public_id', $request->all())) {
            $attributes['sub_sector_id'] = $subSectorId;
            if ($subSectorId !== null) {
                $attributes['sector_id'] = $sectorId;
            }
        }

        $client->update($attributes);

        $this->securityAudit->record('crm.client.updated', actor: $actor, subject: $client, properties: [
            'changed_fields' => array_keys($attributes),
        ], request: $request);

        return $this->respondSuccess(
            ClientResource::make($client->refresh()->loadMissing(['agency', 'profilePhotoDocument', 'prospector', 'collectionAgent', 'sector', 'subSector'])),
            'Client updated successfully'
        );
    }

    private function shouldUseInstitutionScope(User $actor, Request $request): bool
    {
        return $request->query('scope') === 'all' && $this->hasInstitutionReadScope($actor);
    }

    /**
     * @param  Builder<Client>  $query
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

    private function resolveProfilePhotoDocumentId(int $agencyId, mixed $publicId, bool $allowNull = false): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return $allowNull ? null : null;
        }

        $document = Document::query()
            ->where('public_id', $publicId)
            ->where('agency_id', $agencyId)
            ->where('status', Document::STATUS_ACTIVE)
            ->first();

        if (! $document instanceof Document
            || $document->category !== 'profile_photo'
            || ! $document->hasMedia('kyc_documents')) {
            throw ValidationException::withMessages([
                'profile_photo_document_public_id' => 'Selected profile photo document must be an active profile_photo document in the same agency.',
            ]);
        }

        return $document->id;
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveSectorClassification(mixed $sectorPublicId, mixed $subSectorPublicId, bool $allowNull = false): array
    {
        $sector = null;
        if (is_string($sectorPublicId) && $sectorPublicId !== '') {
            $sector = Sector::query()
                ->where('public_id', $sectorPublicId)
                ->where('status', Sector::STATUS_ACTIVE)
                ->first();

            if (! $sector instanceof Sector) {
                throw ValidationException::withMessages([
                    'sector_public_id' => 'Selected sector must be active.',
                ]);
            }
        }

        $subSector = null;
        if (is_string($subSectorPublicId) && $subSectorPublicId !== '') {
            $subSector = SubSector::query()
                ->with('sector')
                ->where('public_id', $subSectorPublicId)
                ->where('status', SubSector::STATUS_ACTIVE)
                ->first();

            if (! $subSector instanceof SubSector || ! $subSector->sector instanceof Sector || $subSector->sector->status !== Sector::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'sub_sector_public_id' => 'Selected sub-sector must be active and belong to an active sector.',
                ]);
            }

            if ($sector instanceof Sector && $subSector->sector_id !== $sector->id) {
                throw ValidationException::withMessages([
                    'sub_sector_public_id' => 'Selected sub-sector must belong to the selected sector.',
                ]);
            }

            $sector = $subSector->sector;
        }

        if (! $sector instanceof Sector && ! $allowNull) {
            return [null, null];
        }

        return [$sector?->id, $subSector?->id];
    }

    private function hasInstitutionReadScope(User $actor): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->hasPermissionTo('crm.scope.institution.read')
            || $actor->hasPermissionTo('crm.scope.institution.review')
            || $actor->hasPermissionTo('crm.scope.institution.manage');
    }
}
