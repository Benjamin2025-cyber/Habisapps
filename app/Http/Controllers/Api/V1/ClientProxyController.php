<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreClientProxyRequest;
use App\Http\Requests\Api\V1\UpdateClientProxyRequest;
use App\Http\Requests\Api\V1\UpdateClientProxyStatusRequest;
use App\Http\Resources\ClientProxyCollection;
use App\Http\Resources\ClientProxyResource;
use App\Models\Client;
use App\Models\ClientProxy;
use App\Models\Document;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ClientProxyController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    /**
     * List client proxies/mandates.
     *
     * Supports optional `current_only` filtering for currently active mandates.
     *
     * @authenticated
     *
     * @response ClientProxyCollection
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{proxies: array<int, \App\Http\Resources\ClientProxyResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}'
    )]
    public function index(Request $request, Client $client): ClientProxyCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo('crm.proxies.view')
            || ! $this->canReadClient($actor, $client)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $onlyCurrent = $request->boolean('current_only', false);
        $today = now()->toDateString();

        return new ClientProxyCollection(
            ClientProxy::query()
                ->with(['client', 'document'])
                ->where('client_id', $client->id)
                ->where('agency_id', $client->agency_id)
                ->when($onlyCurrent, function (Builder $query) use ($today): void {
                    $query
                        ->where('status', ClientProxy::STATUS_ACTIVE)
                        ->where(function (Builder $activeQuery) use ($today): void {
                            $activeQuery->where('ends_on', null)->orWhere('ends_on', '>=', $today);
                        });
                })
                ->latest()
                ->paginate($perPage)
        );
    }

    /**
     * Create a client proxy/mandate record.
     *
     * @authenticated
     */
    #[Response(
        status: 201,
        type: 'array{success: bool, message: string, data: array{proxy: \App\Http\Resources\ClientProxyResource}, errors: null, meta: null}'
    )]
    public function store(StoreClientProxyRequest $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo('crm.proxies.create')
            || ! $this->canManageClient($actor, $client)) {
            return $this->respondForbidden();
        }

        $documentId = $this->resolveDocumentId($client, $request->input('document_public_id'));
        if ($documentId === false) {
            return $this->respondUnprocessable('Document attachment is invalid for this client.');
        }

        $record = ClientProxy::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $client->agency_id,
            'client_id' => $client->id,
            'proxy_full_name' => $request->string('proxy_full_name')->toString(),
            'proxy_phone_number' => $request->input('proxy_phone_number'),
            'proxy_email' => $request->input('proxy_email'),
            'proxy_id_document_type' => $request->input('proxy_id_document_type'),
            'proxy_id_document_number' => $request->input('proxy_id_document_number'),
            'mandate_type' => $request->string('mandate_type')->toString(),
            'starts_on' => $request->input('starts_on'),
            'ends_on' => $request->input('ends_on'),
            'status' => $this->deriveLifecycleStatus(
                $request->input('starts_on'),
                $request->input('ends_on'),
            ),
            'verification_status' => ClientProxy::VERIFICATION_PENDING,
            'document_id' => is_int($documentId) ? $documentId : null,
            'created_by_user_id' => $actor->id,
        ]);

        $this->securityAudit->record('crm.proxy.created', actor: $actor, subject: $record, properties: [
            'client_public_id' => $client->public_id,
        ], request: $request);

        return $this->respondCreated(
            ClientProxyResource::make($record->loadMissing(['client', 'document'])),
            'Client proxy created successfully'
        );
    }

    /**
     * Show a client proxy/mandate record.
     *
     * @authenticated
     *
     * @response ClientProxyResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{proxy: \App\Http\Resources\ClientProxyResource}, errors: null, meta: null}'
    )]
    public function show(Request $request, Client $client, ClientProxy $proxy): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo('crm.proxies.view')
            || ! $this->canReadClient($actor, $client)) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($proxy, $client)) {
            return $this->respondNotFound();
        }

        return $this->respondSuccess(
            ClientProxyResource::make($proxy->loadMissing(['client', 'document']))
        );
    }

    /**
     * Update a client proxy/mandate record.
     *
     * @authenticated
     *
     * @response ClientProxyResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{proxy: \App\Http\Resources\ClientProxyResource}, errors: null, meta: null}'
    )]
    public function update(UpdateClientProxyRequest $request, Client $client, ClientProxy $proxy): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->hasPermissionTo('crm.proxies.update')
            || ! $this->canManageClient($actor, $client)) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($proxy, $client)) {
            return $this->respondNotFound();
        }

        $attributes = $request->safe()->only([
            'proxy_full_name',
            'proxy_phone_number',
            'proxy_email',
            'proxy_id_document_type',
            'proxy_id_document_number',
            'mandate_type',
            'starts_on',
            'ends_on',
        ]);

        if ($request->has('document_public_id')) {
            $documentId = $this->resolveDocumentId($client, $request->input('document_public_id'));
            if ($documentId === false) {
                return $this->respondUnprocessable('Document attachment is invalid for this client.');
            }

            $attributes['document_id'] = is_int($documentId) ? $documentId : null;
        }

        if ($proxy->verification_status === ClientProxy::VERIFICATION_VERIFIED
            && array_intersect(array_keys($attributes), ['proxy_full_name', 'proxy_id_document_number', 'document_id']) !== []) {
            $attributes['verification_status'] = ClientProxy::VERIFICATION_PENDING_REVIEW;
            $attributes['verified_at'] = null;
            $attributes['verified_by_user_id'] = null;
        }

        if (array_key_exists('starts_on', $attributes) || array_key_exists('ends_on', $attributes)) {
            $attributes['status'] = $this->deriveLifecycleStatus(
                $attributes['starts_on'] ?? $proxy->starts_on,
                $attributes['ends_on'] ?? $proxy->ends_on,
                currentStatus: $proxy->status,
            );
        }

        $proxy->update($attributes);

        $this->securityAudit->record('crm.proxy.updated', actor: $actor, subject: $proxy, properties: [
            'changed_fields' => array_keys($attributes),
        ], request: $request);

        return $this->respondSuccess(
            ClientProxyResource::make($proxy->refresh()->loadMissing(['client', 'document'])),
            'Client proxy updated successfully'
        );
    }

    /**
     * Update client proxy lifecycle or verification status.
     *
     * Supported actions: submit, verify, reject, archive, deactivate, expire.
     *
     * @authenticated
     *
     * @response ClientProxyResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{proxy: \App\Http\Resources\ClientProxyResource}, errors: null, meta: null}'
    )]
    public function updateStatus(UpdateClientProxyStatusRequest $request, Client $client, ClientProxy $proxy): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($proxy, $client)) {
            return $this->respondNotFound();
        }

        $action = $request->string('action')->toString();
        if (! $this->canApplyStatusAction($actor, $client, $action)) {
            return $this->respondForbidden();
        }

        if ($action === 'verify'
            && ($proxy->created_by_user_id === $actor->id
                || $proxy->document?->uploaded_by_user_id === $actor->id)
            && ! $actor->hasPermissionTo('crm.kyc.override.self_verify')) {
            return $this->respondForbidden('Uploader cannot verify their own mandate evidence.');
        }

        if ($action === 'verify' && ! $this->hasLinkedDocumentEvidence($proxy)) {
            return $this->respondUnprocessable('Proxy verification requires linked KYC document evidence.');
        }

        $reason = $request->input('reason');
        $update = match ($action) {
            'submit' => [
                'verification_status' => ClientProxy::VERIFICATION_PENDING_REVIEW,
                'submitted_at' => now(),
            ],
            'verify' => [
                'verification_status' => ClientProxy::VERIFICATION_VERIFIED,
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
                'rejected_at' => null,
                'rejection_reason' => null,
            ],
            'reject' => [
                'verification_status' => ClientProxy::VERIFICATION_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => is_string($reason) ? $reason : null,
            ],
            'archive' => [
                'status' => ClientProxy::STATUS_ARCHIVED,
                'archived_at' => now(),
            ],
            'deactivate' => [
                'status' => ClientProxy::STATUS_INACTIVE,
            ],
            'expire' => [
                'status' => ClientProxy::STATUS_EXPIRED,
            ],
            default => null,
        };

        if (! is_array($update)) {
            return $this->respondUnprocessable('Unsupported status action.');
        }

        $proxy->update($update);

        if ($proxy->status === ClientProxy::STATUS_ACTIVE
            && $this->isPastDateValue($proxy->ends_on)) {
            $proxy->update(['status' => ClientProxy::STATUS_EXPIRED]);
        }

        $this->securityAudit->record('crm.proxy.status_changed', actor: $actor, subject: $proxy, properties: [
            'action' => $action,
            'status' => $proxy->status,
            'verification_status' => $proxy->verification_status,
        ], request: $request);

        return $this->respondSuccess(
            ClientProxyResource::make($proxy->refresh()->loadMissing(['client', 'document'])),
            'Client proxy status updated successfully'
        );
    }

    private function belongsToClient(ClientProxy $proxy, Client $client): bool
    {
        return $proxy->client_id === $client->id && $proxy->agency_id === $client->agency_id;
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
            'submit' => $actor->hasPermissionTo('crm.proxies.update') && $this->canManageClient($actor, $client),
            'verify' => $actor->hasPermissionTo('crm.proxies.verify') && $this->canReviewClient($actor, $client),
            'reject' => $actor->hasPermissionTo('crm.proxies.reject') && $this->canReviewClient($actor, $client),
            'archive', 'deactivate' => $actor->hasPermissionTo('crm.proxies.archive') && $this->canManageClient($actor, $client),
            'expire' => $actor->hasPermissionTo('crm.proxies.expire') && $this->canReviewClient($actor, $client),
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

    private function isPastDateValue(mixed $value): bool
    {
        if ($value instanceof \DateTimeInterface) {
            return $value < now();
        }

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);

            return is_int($timestamp) && $timestamp < now()->startOfDay()->getTimestamp();
        }

        return false;
    }

    private function deriveLifecycleStatus(mixed $startsOn, mixed $endsOn, ?string $currentStatus = null): string
    {
        if (in_array($currentStatus, [ClientProxy::STATUS_ARCHIVED, ClientProxy::STATUS_EXPIRED], true)) {
            return $currentStatus;
        }

        if ($this->isPastDateValue($endsOn)) {
            return ClientProxy::STATUS_EXPIRED;
        }

        if ($this->isFutureDateValue($startsOn)) {
            return ClientProxy::STATUS_INACTIVE;
        }

        return ClientProxy::STATUS_ACTIVE;
    }

    private function isFutureDateValue(mixed $value): bool
    {
        if ($value instanceof \DateTimeInterface) {
            return $value > now()->startOfDay();
        }

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);

            return is_int($timestamp) && $timestamp > now()->startOfDay()->getTimestamp();
        }

        return false;
    }

    private function hasLinkedDocumentEvidence(ClientProxy $proxy): bool
    {
        $document = $proxy->document;

        return $document instanceof Document
            && $document->status === Document::STATUS_ACTIVE
            && in_array($document->category, ['kyc', 'identity', 'proof_of_address'], true)
            && $document->hasMedia('kyc_documents');
    }
}
