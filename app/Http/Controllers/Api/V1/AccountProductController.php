<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreAccountProductRequest;
use App\Http\Requests\UpdateAccountProductRequest;
use App\Http\Resources\AccountProductCollection;
use App\Http\Resources\AccountProductResource;
use App\Models\AccountProduct;
use App\Models\Agency;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AccountProductController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{account_products: array<int, \App\Http\Resources\AccountProductResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): AccountProductCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', AccountProduct::class)) {
            return $this->respondForbidden();
        }

        $query = AccountProduct::query()->with(['agency', 'ledgerAccount'])->latest();

        if (! $actor->hasRole('platform-admin') && ! $actor->can('ledger.scope.institution.read')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $query->getQuery()->where(function (QueryBuilder $builder) use ($agencyId): void {
                $builder->where('agency_id', $agencyId)->orWhereNull('agency_id');
            });
        }

        if ($request->filled('account_family')) {
            $query->where('account_family', $request->string('account_family'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('code', 'ilike', '%'.$term.'%')
                    ->orWhere('name', 'ilike', '%'.$term.'%')
                    ->orWhere('account_family', 'ilike', '%'.$term.'%')
                    ->orWhere('currency', 'ilike', '%'.$term.'%');
            });
        }

        return new AccountProductCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{account_product: \App\Http\Resources\AccountProductResource}, errors: null, meta: null}')]
    public function store(StoreAccountProductRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $agency = $this->resolveAgency($request, $actor);
        if ($agency === false) {
            return $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency is invalid.']]);
        }

        $ledgerAccount = $this->resolveLedgerAccount($request->input('ledger_account_public_id'));
        if ($ledgerAccount === false) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account is invalid.']]);
        }

        if ($ledgerAccount instanceof LedgerAccount && ! $this->ledgerAccountIsCompatible($ledgerAccount, $agency)) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be active and match the account product agency scope.']]);
        }

        $agencyId = $agency instanceof Agency ? $agency->id : null;
        $duplicateExists = DB::table('account_products')
            ->where('code', $request->string('code')->toString())
            ->where('agency_id', $agencyId)
            ->exists();
        if ($duplicateExists) {
            return $this->respondUnprocessable('Account product code already exists for this agency scope.');
        }

        try {
            $product = AccountProduct::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'ledger_account_id' => $ledgerAccount instanceof LedgerAccount ? $ledgerAccount->id : null,
                'code' => $request->string('code')->toString(),
                'name' => $request->string('name')->toString(),
                'account_family' => $request->string('account_family')->toString(),
                'minimum_balance_minor' => $request->integer('minimum_balance_minor', 0),
                'currency' => strtoupper($request->string('currency', 'XAF')->toString()),
                'allows_recovery_debit' => $request->boolean('allows_recovery_debit'),
                'is_recovery_account' => $request->boolean('is_recovery_account'),
                'is_ordinary_savings' => $request->boolean('is_ordinary_savings'),
                'allows_overdraft' => $request->boolean('allows_overdraft'),
                'overdraft_limit_minor' => $request->integer('overdraft_limit_minor', 0),
                'status' => $request->input('status', AccountProduct::STATUS_ACTIVE),
                'rules' => $request->input('rules'),
            ]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                return $this->respondUnprocessable('Account product code already exists for this agency scope.');
            }

            throw $exception;
        }

        $this->securityAudit->record('account.product.created', actor: $actor, subject: $product, properties: [
            'code' => $product->code,
            'agency_public_id' => $agency instanceof Agency ? $agency->public_id : null,
        ], request: $request);

        return $this->respondCreated(
            AccountProductResource::make($product->loadMissing(['agency', 'ledgerAccount'])),
            'Account product created successfully'
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{account_product: \App\Http\Resources\AccountProductResource}, errors: null, meta: null}')]
    public function show(Request $request, AccountProduct $accountProduct): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $accountProduct)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(AccountProductResource::make($accountProduct->loadMissing(['agency', 'ledgerAccount'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{account_product: \App\Http\Resources\AccountProductResource}, errors: null, meta: null}')]
    public function update(UpdateAccountProductRequest $request, AccountProduct $accountProduct): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('ledger_account_public_id', $validated)) {
            $ledgerAccount = $this->resolveLedgerAccount($validated['ledger_account_public_id']);
            if ($ledgerAccount === false) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account is invalid.']]);
            }

            if ($ledgerAccount instanceof LedgerAccount && ! $this->ledgerAccountIsCompatible($ledgerAccount, $accountProduct->agency)) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be active and match the account product agency scope.']]);
            }

            $validated['ledger_account_id'] = $ledgerAccount instanceof LedgerAccount ? $ledgerAccount->id : null;
            unset($validated['ledger_account_public_id']);
        }

        if (array_key_exists('currency', $validated) && is_string($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        $accountProduct->fill($validated)->save();

        $this->securityAudit->record('account.product.updated', actor: $request->user(), subject: $accountProduct, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(
            AccountProductResource::make($accountProduct->refresh()->loadMissing(['agency', 'ledgerAccount'])),
            'Account product updated successfully'
        );
    }

    public function destroy(Request $request, AccountProduct $accountProduct): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $accountProduct)) {
            return $this->respondForbidden();
        }

        $accountProduct->update(['status' => AccountProduct::STATUS_ARCHIVED]);
        $this->securityAudit->record('account.product.archived', actor: $actor, subject: $accountProduct, request: $request);

        return $this->respondSuccess(message: 'Account product archived successfully');
    }

    private function resolveAgency(Request $request, User $actor): Agency|false|null
    {
        if ($request->filled('agency_public_id')) {
            $agency = Agency::query()->where('public_id', $request->string('agency_public_id'))->first();

            return $agency instanceof Agency ? $agency : false;
        }

        if ($actor->hasRole('platform-admin')) {
            return null;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);

        return $agencyId !== null ? Agency::query()->find($agencyId) : false;
    }

    private function resolveLedgerAccount(mixed $publicId): LedgerAccount|false|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $ledgerAccount = LedgerAccount::query()->where('public_id', $publicId)->first();

        return $ledgerAccount instanceof LedgerAccount ? $ledgerAccount : false;
    }

    private function ledgerAccountIsCompatible(LedgerAccount $ledgerAccount, ?Agency $agency): bool
    {
        return $ledgerAccount->status === LedgerAccount::STATUS_ACTIVE
            && $agency instanceof Agency
            && $ledgerAccount->agency_id === $agency->id;
    }
}
