<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\CustomerAccountSignatureCollection;
use App\Http\Resources\CustomerAccountSignatureResource;
use App\Models\ClientProxy;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountSignature;
use App\Models\Document;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CustomerAccountSignatureController extends BaseController
{
    /** @var array<int, string> */
    private const SIGNATURE_DOCUMENT_CATEGORIES = [
        'signature',
        'client_signature',
        'account_signature',
        'signature_card',
        'thumbprint',
    ];

    public function __construct(private readonly SecurityAudit $securityAudit) {}

    public function index(Request $request, CustomerAccount $customerAccount): CustomerAccountSignatureCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || Gate::forUser($actor)->denies('viewAny', [CustomerAccountSignature::class, $customerAccount])) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $query = CustomerAccountSignature::query()
            ->with(['agency', 'customerAccount', 'client', 'document', 'clientProxy', 'verifiedBy', 'revokedBy'])
            ->where('customer_account_id', $customerAccount->id);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('signature_type')) {
            $query->where('signature_type', $request->string('signature_type'));
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('signature_type', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('signer_name', 'ilike', '%'.$term.'%')
                    ->orWhere('signer_role', 'ilike', '%'.$term.'%');
            });
        }

        return new CustomerAccountSignatureCollection($query->latest()->paginate($perPage));
    }

    public function store(Request $request, CustomerAccount $customerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || Gate::forUser($actor)->denies('create', [CustomerAccountSignature::class, $customerAccount])) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'client_proxy_public_id' => ['nullable', 'string', 'exists:client_proxies,public_id'],
            'signature_type' => ['required', Rule::in([
                CustomerAccountSignature::TYPE_PRIMARY_HOLDER,
                CustomerAccountSignature::TYPE_JOINT_HOLDER,
                CustomerAccountSignature::TYPE_PROXY,
                CustomerAccountSignature::TYPE_MANDATE,
                CustomerAccountSignature::TYPE_THUMBPRINT,
            ])],
            'signer_name' => ['nullable', 'string', 'max:255'],
            'signer_role' => ['nullable', 'string', 'max:64'],
            'captured_on' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ])->validate();

        $document = Document::query()->where('public_id', $validated['document_public_id'])->first();
        if (! $document instanceof Document || ! $this->documentCanBackSignature($document, $customerAccount)) {
            return $this->respondUnprocessable(errors: ['document_public_id' => ['The selected document must be an active signature document in the same agency scope.']]);
        }

        if (DB::table('customer_account_signatures')->where('document_id', $document->id)->exists()) {
            return $this->respondUnprocessable(errors: ['document_public_id' => ['The selected document is already linked to an account signature.']]);
        }

        $proxy = $this->resolveProxy($validated['client_proxy_public_id'] ?? null, $customerAccount);
        if (($validated['client_proxy_public_id'] ?? null) !== null && ! $proxy instanceof ClientProxy) {
            return $this->respondUnprocessable(errors: ['client_proxy_public_id' => ['The selected proxy must be active, verified, and tied to this account or client scope.']]);
        }

        if (in_array($validated['signature_type'], [CustomerAccountSignature::TYPE_PROXY, CustomerAccountSignature::TYPE_MANDATE], true) && ! $proxy instanceof ClientProxy) {
            return $this->respondUnprocessable(errors: ['client_proxy_public_id' => ['Proxy and mandate signatures require a verified proxy mandate.']]);
        }

        if ($validated['signature_type'] === CustomerAccountSignature::TYPE_PRIMARY_HOLDER
            && DB::table('customer_account_signatures')
                ->where('customer_account_id', $customerAccount->id)
                ->where('signature_type', CustomerAccountSignature::TYPE_PRIMARY_HOLDER)
                ->where('status', CustomerAccountSignature::STATUS_ACTIVE)
                ->exists()) {
            return $this->respondUnprocessable(errors: ['signature_type' => ['This account already has an active primary-holder signature.']]);
        }

        $signature = DB::transaction(function () use ($actor, $customerAccount, $document, $proxy, $validated): CustomerAccountSignature {
            $signature = CustomerAccountSignature::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $customerAccount->agency_id,
                'customer_account_id' => $customerAccount->id,
                'client_id' => $customerAccount->client_id,
                'document_id' => $document->id,
                'client_proxy_id' => $proxy?->id,
                'signature_type' => $validated['signature_type'],
                'signer_name' => $validated['signer_name'] ?? null,
                'signer_role' => $validated['signer_role'] ?? null,
                'status' => CustomerAccountSignature::STATUS_ACTIVE,
                'captured_on' => $validated['captured_on'] ?? null,
                'metadata' => $validated['metadata'] ?? null,
            ]);

            $document->forceFill([
                'owner_type' => CustomerAccount::class,
                'owner_id' => $customerAccount->id,
            ])->save();

            $this->securityAudit->record('customer_account_signature.created', actor: $actor, subject: $signature, properties: [
                'customer_account_public_id' => $customerAccount->public_id,
                'document_public_id' => $document->public_id,
            ]);

            return $signature;
        });

        return $this->respondCreated(CustomerAccountSignatureResource::make($signature->loadMissing([
            'agency', 'customerAccount', 'client', 'document', 'clientProxy', 'verifiedBy', 'revokedBy',
        ])), 'Customer account signature created successfully');
    }

    public function show(Request $request, CustomerAccount $customerAccount, CustomerAccountSignature $signature): JsonResponse
    {
        if ($signature->customer_account_id !== $customerAccount->id) {
            return $this->respondNotFound();
        }

        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $signature)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(CustomerAccountSignatureResource::make($signature->loadMissing([
            'agency', 'customerAccount', 'client', 'document', 'clientProxy', 'verifiedBy', 'revokedBy',
        ])));
    }

    public function verify(Request $request, CustomerAccount $customerAccount, CustomerAccountSignature $signature): JsonResponse
    {
        if ($signature->customer_account_id !== $customerAccount->id) {
            return $this->respondNotFound();
        }

        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('verify', $signature)) {
            return $this->respondForbidden();
        }

        if ($signature->status !== CustomerAccountSignature::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['signature' => ['Only active signatures can be verified.']]);
        }

        $signature->forceFill([
            'verified_at' => now(),
            'verified_by_user_id' => $actor->id,
        ])->save();

        $this->securityAudit->record('customer_account_signature.verified', actor: $actor, subject: $signature, request: $request);

        return $this->respondSuccess(CustomerAccountSignatureResource::make($signature->refresh()->loadMissing([
            'agency', 'customerAccount', 'client', 'document', 'clientProxy', 'verifiedBy', 'revokedBy',
        ])), 'Customer account signature verified successfully');
    }

    public function revoke(Request $request, CustomerAccount $customerAccount, CustomerAccountSignature $signature): JsonResponse
    {
        if ($signature->customer_account_id !== $customerAccount->id) {
            return $this->respondNotFound();
        }

        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('revoke', $signature)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:255'],
        ])->validate();

        if ($signature->status === CustomerAccountSignature::STATUS_REVOKED) {
            return $this->respondUnprocessable(errors: ['signature' => ['This signature has already been revoked.']]);
        }

        $signature->forceFill([
            'status' => CustomerAccountSignature::STATUS_REVOKED,
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor->id,
            'revocation_reason' => $validated['reason'],
        ])->save();

        $this->securityAudit->record('customer_account_signature.revoked', actor: $actor, subject: $signature, request: $request);

        return $this->respondSuccess(CustomerAccountSignatureResource::make($signature->refresh()->loadMissing([
            'agency', 'customerAccount', 'client', 'document', 'clientProxy', 'verifiedBy', 'revokedBy',
        ])), 'Customer account signature revoked successfully');
    }

    private function documentCanBackSignature(Document $document, CustomerAccount $customerAccount): bool
    {
        return $document->agency_id === $customerAccount->agency_id
            && $document->status === Document::STATUS_ACTIVE
            && in_array($document->category, self::SIGNATURE_DOCUMENT_CATEGORIES, true);
    }

    private function resolveProxy(mixed $publicId, CustomerAccount $customerAccount): ?ClientProxy
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $proxy = ClientProxy::query()->where('public_id', $publicId)->first();
        if (! $proxy instanceof ClientProxy) {
            return null;
        }

        $accountMatches = $proxy->customer_account_id === null || $proxy->customer_account_id === $customerAccount->id;

        if ($proxy->agency_id !== $customerAccount->agency_id
            || $proxy->client_id !== $customerAccount->client_id
            || ! $accountMatches
            || $proxy->status !== ClientProxy::STATUS_ACTIVE
            || $proxy->verification_status !== ClientProxy::VERIFICATION_VERIFIED) {
            return null;
        }

        return $proxy;
    }
}
