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
use App\Models\CustomerAccount;
use App\Models\Document;
use App\Models\User;
use App\Support\Crm\IdentityDocumentTypeCatalog;
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
            || $actor->cannot('viewAny', ClientProxy::class)
            || $actor->cannot('view', $client)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $onlyCurrent = $request->boolean('current_only', false);
        $today = now()->toDateString();

        $query = ClientProxy::query()
            ->with(['client', 'document', 'backDocument'])
            ->with('customerAccount')
            ->where('client_id', $client->id)
            ->where('agency_id', $client->agency_id)
            ->when($onlyCurrent, function (Builder $query) use ($today): void {
                $query
                    ->where('status', ClientProxy::STATUS_ACTIVE)
                    ->where(function (Builder $activeQuery) use ($today): void {
                        $activeQuery->where('ends_on', null)->orWhere('ends_on', '>=', $today);
                    });
            });

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('proxy_full_name', 'ilike', '%'.$term.'%')
                    ->orWhere('proxy_phone_number', 'ilike', '%'.$term.'%')
                    ->orWhere('proxy_email', 'ilike', '%'.$term.'%')
                    ->orWhere('proxy_id_document_type', 'ilike', '%'.$term.'%')
                    ->orWhere('proxy_id_document_number', 'ilike', '%'.$term.'%')
                    ->orWhere('mandate_type', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('verification_status', 'ilike', '%'.$term.'%');
            });
        }

        return new ClientProxyCollection($query->latest()->paginate($perPage));
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
            || $actor->cannot('createForClient', [ClientProxy::class, $client])) {
            return $this->respondForbidden();
        }

        $documentId = $this->resolveDocumentId($client, $request->input('document_public_id'));
        if ($documentId === false) {
            return $this->respondUnprocessable('Document attachment is invalid for this client.');
        }
        $backDocumentId = $this->resolveDocumentId($client, $request->input('back_document_public_id'));
        if ($backDocumentId === false) {
            return $this->respondUnprocessable('Back document attachment is invalid for this client.');
        }
        if (is_int($documentId) && is_int($backDocumentId) && $documentId === $backDocumentId) {
            return $this->respondUnprocessable(errors: [
                'back_document_public_id' => ['The back face must be a different document from the front face.'],
            ]);
        }
        $accountId = $this->resolveCustomerAccountId($client, $request->input('customer_account_public_id'));
        if ($accountId === false) {
            return $this->respondUnprocessable('Customer account mandate scope is invalid for this client.');
        }

        $record = ClientProxy::query()->create(array_merge($this->identityAttributes($request), [
            'public_id' => (string) Str::ulid(),
            'agency_id' => $client->agency_id,
            'client_id' => $client->id,
            'customer_account_id' => is_int($accountId) ? $accountId : null,
            'proxy_full_name' => $request->string('proxy_full_name')->toString(),
            'proxy_phone_number' => $request->input('proxy_phone_number'),
            'proxy_email' => $request->input('proxy_email'),
            'proxy_id_document_type' => $request->input('proxy_id_document_type'),
            'proxy_id_document_number' => $request->input('proxy_id_document_number'),
            'mandate_type' => $request->string('mandate_type')->toString(),
            'operation_types' => $request->input('operation_types'),
            'max_amount_minor' => $request->input('max_amount_minor'),
            'limit_currency' => $request->input('limit_currency'),
            'starts_on' => $request->input('starts_on'),
            'ends_on' => $request->input('ends_on'),
            'status' => $this->deriveLifecycleStatus(
                $request->input('starts_on'),
                $request->input('ends_on'),
            ),
            'verification_status' => ClientProxy::VERIFICATION_PENDING,
            'document_id' => is_int($documentId) ? $documentId : null,
            'back_document_id' => is_int($backDocumentId) ? $backDocumentId : null,
            'created_by_user_id' => $actor->id,
        ]));

        $this->securityAudit->record('crm.proxy.created', actor: $actor, subject: $record, properties: [
            'client_public_id' => $client->public_id,
        ], request: $request);

        return $this->respondCreated(
            ClientProxyResource::make($record->loadMissing(['client', 'customerAccount', 'document', 'backDocument'])),
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
            || $actor->cannot('view', $client)
            || $actor->cannot('view', $proxy)) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($proxy, $client)) {
            return $this->respondNotFound();
        }

        return $this->respondSuccess(
            ClientProxyResource::make($proxy->loadMissing(['client', 'customerAccount', 'document', 'backDocument']))
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
            || $actor->cannot('update', $proxy)) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($proxy, $client)) {
            return $this->respondNotFound();
        }

        $attributes = $request->safe()->only([
            'proxy_full_name',
            'proxy_first_name',
            'proxy_last_name',
            'proxy_middle_name',
            'proxy_date_of_birth',
            'proxy_place_of_birth',
            'proxy_identity_issued_on',
            'proxy_identity_issued_at',
            'proxy_father_name',
            'proxy_mother_name',
            'proxy_address_line_1',
            'proxy_address_line_2',
            'proxy_business_address_line_1',
            'proxy_business_address_line_2',
            'proxy_phone_number',
            'proxy_email',
            'proxy_id_document_type',
            'proxy_id_document_number',
            'mandate_type',
            'operation_types',
            'max_amount_minor',
            'limit_currency',
            'starts_on',
            'ends_on',
        ]);

        if ($request->has('customer_account_public_id')) {
            $accountId = $this->resolveCustomerAccountId($client, $request->input('customer_account_public_id'));
            if ($accountId === false) {
                return $this->respondUnprocessable('Customer account mandate scope is invalid for this client.');
            }

            $attributes['customer_account_id'] = is_int($accountId) ? $accountId : null;
        }

        if ($request->has('document_public_id')) {
            $documentId = $this->resolveDocumentId($client, $request->input('document_public_id'));
            if ($documentId === false) {
                return $this->respondUnprocessable('Document attachment is invalid for this client.');
            }

            $attributes['document_id'] = is_int($documentId) ? $documentId : null;
        }

        if ($request->has('back_document_public_id')) {
            $backDocumentId = $this->resolveDocumentId($client, $request->input('back_document_public_id'));
            if ($backDocumentId === false) {
                return $this->respondUnprocessable('Back document attachment is invalid for this client.');
            }

            $attributes['back_document_id'] = is_int($backDocumentId) ? $backDocumentId : null;
        }

        // Guard front == back regardless of which side this request changes.
        $effectiveFrontId = array_key_exists('document_id', $attributes) ? $attributes['document_id'] : $proxy->document_id;
        $effectiveBackId = array_key_exists('back_document_id', $attributes) ? $attributes['back_document_id'] : $proxy->back_document_id;
        if ($effectiveFrontId !== null && $effectiveBackId !== null && $effectiveFrontId === $effectiveBackId) {
            return $this->respondUnprocessable(errors: [
                'back_document_public_id' => ['The back face must be a different document from the front face.'],
            ]);
        }

        if ($proxy->verification_status === ClientProxy::VERIFICATION_VERIFIED
            && array_intersect(array_keys($attributes), [
                'proxy_full_name',
                'proxy_first_name',
                'proxy_last_name',
                'proxy_middle_name',
                'proxy_date_of_birth',
                'proxy_place_of_birth',
                'proxy_identity_issued_on',
                'proxy_identity_issued_at',
                'proxy_father_name',
                'proxy_mother_name',
                'proxy_id_document_type',
                'proxy_id_document_number',
                'document_id',
                'back_document_id',
                'customer_account_id',
                'operation_types',
                'max_amount_minor',
            ]) !== []) {
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
            ClientProxyResource::make($proxy->refresh()->loadMissing(['client', 'customerAccount', 'document', 'backDocument'])),
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
        if (! $this->canApplyStatusAction($actor, $proxy, $action)) {
            return $this->respondForbidden();
        }

        $selfVerification = $proxy->created_by_user_id === $actor->id
            || $proxy->document?->uploaded_by_user_id === $actor->id;

        if ($action === 'verify'
            && $selfVerification
            && (! $request->boolean('allow_self_verify')
                || ! $actor->hasPermissionTo('crm.kyc.override.self_verify'))) {
            return $this->respondUnprocessable(errors: [
                'allow_self_verify' => ['Self-verification requires an explicit override flag and dedicated permission.'],
            ]);
        }

        if ($action === 'verify' && ! $this->hasLinkedDocumentEvidence($proxy)) {
            return $this->respondUnprocessable('Proxy verification requires linked KYC document evidence.');
        }

        if ($action === 'verify' && is_string($proxy->proxy_id_document_type) && $proxy->proxy_id_document_type !== '') {
            $requiredFaces = IdentityDocumentTypeCatalog::requiredFaces($proxy->proxy_id_document_type);
            if ($requiredFaces !== null && $requiredFaces >= 2 && ! $this->hasBackDocumentEvidence($proxy)) {
                return $this->respondUnprocessable(errors: [
                    'back_document_public_id' => [__('crm.document_requires_both_faces', ['type' => $proxy->proxy_id_document_type])],
                ]);
            }
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

        $selfVerificationOverrideUsed = $action === 'verify'
            && $selfVerification
            && $request->boolean('allow_self_verify')
            && $actor->hasPermissionTo('crm.kyc.override.self_verify');

        $proxy->update($update);

        if ($proxy->status === ClientProxy::STATUS_ACTIVE
            && $this->isPastDateValue($proxy->ends_on)) {
            $proxy->update(['status' => ClientProxy::STATUS_EXPIRED]);
        }

        $this->securityAudit->record('crm.proxy.status_changed', actor: $actor, subject: $proxy, properties: [
            'action' => $action,
            'status' => $proxy->status,
            'verification_status' => $proxy->verification_status,
            'self_verification_override_used' => $selfVerificationOverrideUsed,
            'override_surface' => $selfVerificationOverrideUsed ? 'proxy_kyc' : null,
            'override_reason' => $selfVerificationOverrideUsed && is_string($reason) ? $reason : null,
        ], request: $request);

        return $this->respondSuccess(
            ClientProxyResource::make($proxy->refresh()->loadMissing(['client', 'customerAccount', 'document', 'backDocument'])),
            'Client proxy status updated successfully'
        );
    }

    private function belongsToClient(ClientProxy $proxy, Client $client): bool
    {
        return $proxy->client_id === $client->id && $proxy->agency_id === $client->agency_id;
    }

    /**
     * Proxy personal identity columns sourced from the request (GHI-010C).
     *
     * @return array<string, mixed>
     */
    private function identityAttributes(Request $request): array
    {
        return [
            'proxy_first_name' => $request->input('proxy_first_name'),
            'proxy_last_name' => $request->input('proxy_last_name'),
            'proxy_middle_name' => $request->input('proxy_middle_name'),
            'proxy_date_of_birth' => $request->input('proxy_date_of_birth'),
            'proxy_place_of_birth' => $request->input('proxy_place_of_birth'),
            'proxy_identity_issued_on' => $request->input('proxy_identity_issued_on'),
            'proxy_identity_issued_at' => $request->input('proxy_identity_issued_at'),
            'proxy_father_name' => $request->input('proxy_father_name'),
            'proxy_mother_name' => $request->input('proxy_mother_name'),
            'proxy_address_line_1' => $request->input('proxy_address_line_1'),
            'proxy_address_line_2' => $request->input('proxy_address_line_2'),
            'proxy_business_address_line_1' => $request->input('proxy_business_address_line_1'),
            'proxy_business_address_line_2' => $request->input('proxy_business_address_line_2'),
        ];
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

    private function resolveCustomerAccountId(Client $client, mixed $publicId): int|bool|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $account = CustomerAccount::query()
            ->where('public_id', $publicId)
            ->where('client_id', $client->id)
            ->where('agency_id', $client->agency_id)
            ->where('status', CustomerAccount::STATUS_ACTIVE)
            ->first();

        return $account instanceof CustomerAccount ? $account->id : false;
    }

    private function canApplyStatusAction(User $actor, ClientProxy $proxy, string $action): bool
    {
        return match ($action) {
            'submit' => $actor->can('update', $proxy),
            'verify' => $actor->can('verify', $proxy),
            'reject' => $actor->can('reject', $proxy),
            'archive', 'deactivate' => $actor->can('archive', $proxy),
            'expire' => $actor->can('expire', $proxy),
            default => false,
        };
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
        return $this->isUsableEvidence($proxy->document);
    }

    private function hasBackDocumentEvidence(ClientProxy $proxy): bool
    {
        return $this->isUsableEvidence($proxy->backDocument);
    }

    private function isUsableEvidence(?Document $document): bool
    {
        return $document instanceof Document
            && $document->status === Document::STATUS_ACTIVE
            && in_array($document->category, ['kyc', 'identity', 'proof_of_address'], true)
            && $document->hasMedia('kyc_documents');
    }
}
