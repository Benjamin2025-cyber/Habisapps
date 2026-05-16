<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreEmfRegulatoryAccountRequest;
use App\Http\Requests\UpdateEmfRegulatoryAccountRequest;
use App\Http\Resources\EmfRegulatoryAccountCollection;
use App\Http\Resources\EmfRegulatoryAccountResource;
use App\Models\EmfRegulatoryAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EmfRegulatoryAccountController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{emf_regulatory_accounts: array<int, \App\Http\Resources\EmfRegulatoryAccountResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): EmfRegulatoryAccountCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', EmfRegulatoryAccount::class)) {
            return $this->respondForbidden();
        }

        $query = EmfRegulatoryAccount::query()->with('parentAccount')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('account_class')) {
            $query->where('account_class', $request->string('account_class'));
        }

        return new EmfRegulatoryAccountCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{emf_regulatory_account: \App\Http\Resources\EmfRegulatoryAccountResource}, errors: null, meta: null}')]
    public function store(StoreEmfRegulatoryAccountRequest $request): JsonResponse
    {
        $parent = $this->resolveParent($request->input('parent_public_id'));
        if ($parent === false) {
            return $this->respondUnprocessable(errors: ['parent_public_id' => ['The selected parent account is invalid.']]);
        }

        $account = EmfRegulatoryAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'account_class' => $request->input('account_class'),
            'parent_emf_regulatory_account_id' => $parent instanceof EmfRegulatoryAccount ? $parent->id : null,
            'status' => $request->input('status', EmfRegulatoryAccount::STATUS_ACTIVE),
            'metadata' => $request->input('metadata'),
        ]);

        $this->securityAudit->record('emf.regulatory_account.created', actor: $request->user(), subject: $account, properties: [
            'code' => $account->code,
        ], request: $request);

        return $this->respondCreated(
            EmfRegulatoryAccountResource::make($account->loadMissing('parentAccount')),
            'EMF regulatory account created successfully'
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{emf_regulatory_account: \App\Http\Resources\EmfRegulatoryAccountResource}, errors: null, meta: null}')]
    public function show(Request $request, EmfRegulatoryAccount $emfRegulatoryAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $emfRegulatoryAccount)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(EmfRegulatoryAccountResource::make($emfRegulatoryAccount->loadMissing('parentAccount')));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{emf_regulatory_account: \App\Http\Resources\EmfRegulatoryAccountResource}, errors: null, meta: null}')]
    public function update(UpdateEmfRegulatoryAccountRequest $request, EmfRegulatoryAccount $emfRegulatoryAccount): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('parent_public_id', $validated)) {
            $parent = $this->resolveParent($validated['parent_public_id']);
            if ($parent === false || ($parent instanceof EmfRegulatoryAccount && $this->wouldCreateCycle($emfRegulatoryAccount, $parent))) {
                return $this->respondUnprocessable(errors: ['parent_public_id' => ['The selected parent account is invalid.']]);
            }

            $validated['parent_emf_regulatory_account_id'] = $parent instanceof EmfRegulatoryAccount ? $parent->id : null;
            unset($validated['parent_public_id']);
        }

        $emfRegulatoryAccount->fill($validated)->save();

        $this->securityAudit->record('emf.regulatory_account.updated', actor: $request->user(), subject: $emfRegulatoryAccount, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(
            EmfRegulatoryAccountResource::make($emfRegulatoryAccount->refresh()->loadMissing('parentAccount')),
            'EMF regulatory account updated successfully'
        );
    }

    public function destroy(Request $request, EmfRegulatoryAccount $emfRegulatoryAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $emfRegulatoryAccount)) {
            return $this->respondForbidden();
        }

        if (DB::table('emf_regulatory_accounts')->where('parent_emf_regulatory_account_id', $emfRegulatoryAccount->id)->exists()
            || DB::table('emf_ledger_account_mappings')->where('emf_regulatory_account_id', $emfRegulatoryAccount->id)->exists()) {
            return $this->respondUnprocessable('EMF regulatory account cannot be archived while child accounts or ledger mappings exist.');
        }

        $emfRegulatoryAccount->update(['status' => EmfRegulatoryAccount::STATUS_ARCHIVED]);
        $this->securityAudit->record('emf.regulatory_account.archived', actor: $actor, subject: $emfRegulatoryAccount, request: $request);

        return $this->respondSuccess(message: 'EMF regulatory account archived successfully');
    }

    private function resolveParent(mixed $publicId): EmfRegulatoryAccount|false|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $account = EmfRegulatoryAccount::query()->where('public_id', $publicId)->first();

        return $account instanceof EmfRegulatoryAccount ? $account : false;
    }

    private function wouldCreateCycle(EmfRegulatoryAccount $account, EmfRegulatoryAccount $parent): bool
    {
        if ($account->id === $parent->id) {
            return true;
        }

        $cursor = $parent->parentAccount;
        while ($cursor instanceof EmfRegulatoryAccount) {
            if ($cursor->id === $account->id) {
                return true;
            }

            $cursor = $cursor->parentAccount;
        }

        return false;
    }
}
