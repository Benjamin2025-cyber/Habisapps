<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\StoreClientIdentityDocumentRequest;
use App\Http\Requests\Api\V1\UpdateClientIdentityDocumentRequest;
use App\Http\Requests\Api\V1\UpdateClientIdentityDocumentStatusRequest;
use App\Http\Resources\ClientIdentityDocumentCollection;
use App\Http\Resources\ClientIdentityDocumentResource;
use App\Models\Client;
use App\Models\ClientIdentityDocument;
use App\Models\Document;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ClientIdentityDocumentController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    /**
     * List client identity documents.
     *
     * @authenticated
     *
     * @response ClientIdentityDocumentCollection
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{identity_documents: array<int, \App\Http\Resources\ClientIdentityDocumentResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}'
    )]
    public function index(Request $request, Client $client): ClientIdentityDocumentCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || $actor->cannot('viewAny', ClientIdentityDocument::class)
            || $actor->cannot('view', $client)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new ClientIdentityDocumentCollection(
            ClientIdentityDocument::query()
                ->with(['client', 'document'])
                ->where('client_id', $client->id)
                ->where('agency_id', $client->agency_id)
                ->latest()
                ->paginate($perPage)
        );
    }

    /**
     * Create a client identity document record.
     *
     * Links optional private KYC document evidence by public ID.
     *
     * @authenticated
     */
    #[Response(
        status: 201,
        type: 'array{success: bool, message: string, data: array{identity_document: \App\Http\Resources\ClientIdentityDocumentResource}, errors: null, meta: null}'
    )]
    public function store(StoreClientIdentityDocumentRequest $request, Client $client): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || $actor->cannot('createForClient', [ClientIdentityDocument::class, $client])) {
            return $this->respondForbidden();
        }

        $documentModel = null;
        if (is_string($request->input('document_public_id'))) {
            $documentModel = $this->resolveLinkableDocument(
                $client,
                $request->string('document_public_id')->toString(),
                null,
            );

            if (! $documentModel instanceof Document) {
                return $this->respondUnprocessable('Document attachment is invalid for this client.');
            }
        }

        try {
            $normalizedNumber = ClientIdentityDocument::normalizeDocumentNumber($request->string('document_number')->toString());
            if ($this->hasDuplicateDocumentNumber(
                clientId: $client->id,
                documentType: $request->string('document_type')->toString(),
                normalizedNumber: $normalizedNumber,
            )) {
                return $this->respondUnprocessable('Identity document already exists or conflicts with an existing record.');
            }

            $record = ClientIdentityDocument::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $client->agency_id,
                'client_id' => $client->id,
                'document_type' => $request->string('document_type')->toString(),
                'document_number' => $normalizedNumber,
                'document_number_hash' => ClientIdentityDocument::documentNumberHash($normalizedNumber),
                'issuing_authority' => $request->input('issuing_authority'),
                'issued_on' => $request->input('issued_on'),
                'expires_on' => $request->input('expires_on'),
                'verification_status' => ClientIdentityDocument::VERIFICATION_PENDING,
                'document_id' => $documentModel?->id,
                'created_by_user_id' => $actor->id,
                'status' => ClientIdentityDocument::STATUS_ACTIVE,
            ]);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->respondUnprocessable('Identity document already exists or conflicts with an existing record.');
            }

            throw $exception;
        }

        $this->securityAudit->record('crm.identity_document.created', actor: $actor, subject: $record, properties: [
            'client_public_id' => $client->public_id,
            'document_type' => $record->document_type,
        ], request: $request);

        return $this->respondCreated(
            ClientIdentityDocumentResource::make($record->loadMissing(['client', 'document'])),
            'Client identity document created successfully'
        );
    }

    /**
     * Show a client identity document record.
     *
     * @authenticated
     *
     * @response ClientIdentityDocumentResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{identity_document: \App\Http\Resources\ClientIdentityDocumentResource}, errors: null, meta: null}'
    )]
    public function show(Request $request, Client $client, ClientIdentityDocument $identityDocument): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || $actor->cannot('view', $client)
            || $actor->cannot('view', $identityDocument)) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($identityDocument, $client)) {
            return $this->respondNotFound();
        }

        return $this->respondSuccess(
            ClientIdentityDocumentResource::make($identityDocument->loadMissing(['client', 'document']))
        );
    }

    /**
     * Update a client identity document record.
     *
     * @authenticated
     *
     * @response ClientIdentityDocumentResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{identity_document: \App\Http\Resources\ClientIdentityDocumentResource}, errors: null, meta: null}'
    )]
    public function update(UpdateClientIdentityDocumentRequest $request, Client $client, ClientIdentityDocument $identityDocument): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || $actor->cannot('update', $identityDocument)) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($identityDocument, $client)) {
            return $this->respondNotFound();
        }

        if ($identityDocument->verification_status === ClientIdentityDocument::VERIFICATION_VERIFIED
            && ($request->has('document_number') || $request->has('document_type'))) {
            $identityDocument->forceFill([
                'verification_status' => ClientIdentityDocument::VERIFICATION_PENDING_REVIEW,
                'verified_at' => null,
                'verified_by_user_id' => null,
            ])->save();
        }

        $attributes = $request->safe()->only([
            'document_type',
            'issuing_authority',
            'issued_on',
            'expires_on',
        ]);

        if ($request->has('document_number')) {
            $attributes['document_number'] = ClientIdentityDocument::normalizeDocumentNumber($request->string('document_number')->toString());
            $attributes['document_number_hash'] = ClientIdentityDocument::documentNumberHash($attributes['document_number']);
        }

        $type = array_key_exists('document_type', $attributes)
            ? (string) $attributes['document_type']
            : $identityDocument->document_type;
        $number = array_key_exists('document_number', $attributes)
            ? (string) $attributes['document_number']
            : $identityDocument->document_number;
        if ($this->hasDuplicateDocumentNumber(
            clientId: $client->id,
            documentType: $type,
            normalizedNumber: $number,
            exceptId: $identityDocument->id,
        )) {
            return $this->respondUnprocessable('Identity document already exists or conflicts with an existing record.');
        }

        if ($request->has('document_public_id')) {
            $linked = null;
            if (is_string($request->input('document_public_id')) && $request->input('document_public_id') !== '') {
                $linked = $this->resolveLinkableDocument(
                    $client,
                    $request->string('document_public_id')->toString(),
                    $identityDocument->id,
                );

                if (! $linked instanceof Document) {
                    return $this->respondUnprocessable('Document attachment is invalid for this client.');
                }
            }

            $attributes['document_id'] = $linked?->id;
        }

        try {
            $identityDocument->update($attributes);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->respondUnprocessable('Identity document already exists or conflicts with an existing record.');
            }

            throw $exception;
        }

        $this->securityAudit->record('crm.identity_document.updated', actor: $actor, subject: $identityDocument, properties: [
            'changed_fields' => array_keys($attributes),
        ], request: $request);

        return $this->respondSuccess(
            ClientIdentityDocumentResource::make($identityDocument->refresh()->loadMissing(['client', 'document'])),
            'Client identity document updated successfully'
        );
    }

    /**
     * Update client identity document lifecycle or verification status.
     *
     * Supported actions: submit, verify, reject, archive.
     *
     * @authenticated
     *
     * @response ClientIdentityDocumentResource
     */
    #[Response(
        status: 200,
        type: 'array{success: bool, message: string, data: array{identity_document: \App\Http\Resources\ClientIdentityDocumentResource}, errors: null, meta: null}'
    )]
    public function updateStatus(UpdateClientIdentityDocumentStatusRequest $request, Client $client, ClientIdentityDocument $identityDocument): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $this->belongsToClient($identityDocument, $client)) {
            return $this->respondNotFound();
        }

        $action = $request->string('action')->toString();
        if (! $this->canApplyStatusAction($actor, $identityDocument, $action)) {
            return $this->respondForbidden();
        }

        if ($action === 'verify') {
            if (($identityDocument->created_by_user_id === $actor->id
                || $identityDocument->document?->uploaded_by_user_id === $actor->id)
                && ! $actor->hasPermissionTo('crm.kyc.override.self_verify')) {
                return $this->respondForbidden('Uploader cannot verify their own identity document.');
            }

            if (! $this->hasLinkedDocumentEvidence($identityDocument)) {
                return $this->respondUnprocessable('Identity document verification requires linked KYC document evidence.');
            }

            if ($this->isPastDateValue($identityDocument->expires_on)) {
                return $this->respondUnprocessable('Expired identity document cannot be verified.');
            }
        }

        $reason = $request->input('reason');
        $update = match ($action) {
            'submit' => [
                'verification_status' => ClientIdentityDocument::VERIFICATION_PENDING_REVIEW,
                'submitted_at' => now(),
            ],
            'verify' => [
                'verification_status' => ClientIdentityDocument::VERIFICATION_VERIFIED,
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
                'rejected_at' => null,
                'rejection_reason' => null,
            ],
            'reject' => [
                'verification_status' => ClientIdentityDocument::VERIFICATION_REJECTED,
                'rejected_at' => now(),
                'rejection_reason' => is_string($reason) ? $reason : null,
            ],
            'archive' => [
                'status' => ClientIdentityDocument::STATUS_ARCHIVED,
                'archived_at' => now(),
            ],
            default => null,
        };

        if (! is_array($update)) {
            return $this->respondUnprocessable('Unsupported status action.');
        }

        $identityDocument->update($update);

        $this->securityAudit->record('crm.identity_document.status_changed', actor: $actor, subject: $identityDocument, properties: [
            'action' => $action,
            'verification_status' => $identityDocument->verification_status,
            'status' => $identityDocument->status,
        ], request: $request);

        return $this->respondSuccess(
            ClientIdentityDocumentResource::make($identityDocument->refresh()->loadMissing(['client', 'document'])),
            'Client identity document status updated successfully'
        );
    }

    private function belongsToClient(ClientIdentityDocument $identityDocument, Client $client): bool
    {
        return $identityDocument->client_id === $client->id && $identityDocument->agency_id === $client->agency_id;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return $sqlState === '23505';
    }

    private function hasDuplicateDocumentNumber(int $clientId, string $documentType, string $normalizedNumber, ?int $exceptId = null): bool
    {
        $query = DB::table('client_identity_documents')
            ->where('client_id', '!=', $clientId)
            ->where('document_type', $documentType)
            ->where('document_number_hash', ClientIdentityDocument::documentNumberHash($normalizedNumber));

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->exists();
    }

    private function isPastDateValue(mixed $value): bool
    {
        if ($value instanceof \DateTimeInterface) {
            return $value < now();
        }

        return false;
    }

    private function canApplyStatusAction(User $actor, ClientIdentityDocument $identityDocument, string $action): bool
    {
        return match ($action) {
            'submit' => $actor->can('update', $identityDocument),
            'verify' => $actor->can('verify', $identityDocument),
            'reject' => $actor->can('reject', $identityDocument),
            'archive' => $actor->can('archive', $identityDocument),
            default => false,
        };
    }

    private function resolveLinkableDocument(Client $client, string $documentPublicId, ?int $currentIdentityDocumentId): ?Document
    {
        $document = Document::query()
            ->where('public_id', $documentPublicId)
            ->where('agency_id', $client->agency_id)
            ->where('status', Document::STATUS_ACTIVE)
            ->first();

        if (! $document instanceof Document) {
            return null;
        }

        if (! in_array($document->category, ['kyc', 'identity', 'proof_of_address'], true)) {
            return null;
        }

        if (! $document->hasMedia('kyc_documents')) {
            return null;
        }

        $existingLink = ClientIdentityDocument::query()
            ->where('document_id', $document->id)
            ->where('agency_id', $client->agency_id)
            ->when($currentIdentityDocumentId !== null, fn (Builder $query): Builder => $query->where('id', '!=', $currentIdentityDocumentId))
            ->first();

        if ($existingLink instanceof ClientIdentityDocument && $existingLink->client_id !== $client->id) {
            return null;
        }

        return $document;
    }

    private function hasLinkedDocumentEvidence(ClientIdentityDocument $identityDocument): bool
    {
        $document = $identityDocument->document;

        return $document instanceof Document
            && $document->status === Document::STATUS_ACTIVE
            && in_array($document->category, ['kyc', 'identity', 'proof_of_address'], true)
            && $document->hasMedia('kyc_documents');
    }
}
