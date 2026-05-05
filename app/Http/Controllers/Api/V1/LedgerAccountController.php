<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreLedgerAccountRequest;
use App\Http\Requests\UpdateLedgerAccountRequest;
use App\Http\Resources\LedgerAccountCollection;
use App\Http\Resources\LedgerAccountResource;
use App\Models\Agency;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class LedgerAccountController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{ledger_accounts: array<int, \App\Http\Resources\LedgerAccountResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): LedgerAccountCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', LedgerAccount::class)) {
            return $this->respondForbidden();
        }

        $query = LedgerAccount::query()->with(['agency', 'parentAccount'])->latest();

        if (! $actor->hasRole('platform-admin') && ! $actor->can('ledger.scope.institution.read')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $query->where(function ($builder) use ($agencyId): void {
                $builder->where('agency_id', $agencyId)->orWhere('agency_id', null);
            });
        }

        return new LedgerAccountCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{ledger_account: \App\Http\Resources\LedgerAccountResource}, errors: null, meta: null}')]
    public function store(StoreLedgerAccountRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $agency = null;
        if ($request->filled('agency_public_id')) {
            $agency = Agency::query()->where('public_id', $request->string('agency_public_id'))->first();
            if (! $agency instanceof Agency) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency is invalid.']]);
            }
        } elseif (! $actor->hasRole('platform-admin')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            $agency = $agencyId !== null ? Agency::query()->find($agencyId) : null;
        }

        if (! $agency instanceof Agency) {
            return $this->respondUnprocessable(errors: ['agency_public_id' => ['Ledger accounts must be attached to an agency in this safe slice.']]);
        }

        $parent = null;
        if ($request->filled('parent_account_public_id')) {
            $parent = LedgerAccount::query()->where('public_id', $request->string('parent_account_public_id'))->first();
            if (! $parent instanceof LedgerAccount) {
                return $this->respondUnprocessable(errors: ['parent_account_public_id' => ['The selected parent account is invalid.']]);
            }
            if ($parent->agency_id !== null && $agency->id !== $parent->agency_id) {
                return $this->respondUnprocessable(errors: ['parent_account_public_id' => ['The selected parent account must belong to the same agency scope.']]);
            }
        }

        $ledgerAccount = LedgerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency->id,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'account_class' => $request->string('account_class')->toString(),
            'account_type' => $request->input('account_type'),
            'parent_account_id' => $parent?->id,
            'normal_balance_side' => $request->string('normal_balance_side')->toString(),
            'status' => $request->input('status', LedgerAccount::STATUS_ACTIVE),
        ]);

        $this->securityAudit->record('ledger.account.created', actor: $actor, subject: $ledgerAccount, properties: [
            'code' => $ledgerAccount->code,
            'agency_public_id' => $agency->public_id,
        ], request: $request);

        return $this->respondCreated(
            LedgerAccountResource::make($ledgerAccount->loadMissing(['agency', 'parentAccount'])),
            'Ledger account created successfully'
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{ledger_account: \App\Http\Resources\LedgerAccountResource}, errors: null, meta: null}')]
    public function show(Request $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $ledgerAccount)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(LedgerAccountResource::make($ledgerAccount->loadMissing(['agency', 'parentAccount'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{ledger_account: \App\Http\Resources\LedgerAccountResource}, errors: null, meta: null}')]
    public function update(UpdateLedgerAccountRequest $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('parent_account_public_id', $validated)) {
            $parent = null;
            if ($validated['parent_account_public_id'] !== null) {
                $parent = LedgerAccount::query()->where('public_id', $validated['parent_account_public_id'])->first();
                if (! $parent instanceof LedgerAccount) {
                    return $this->respondUnprocessable(errors: ['parent_account_public_id' => ['The selected parent account is invalid.']]);
                }
                if ($parent->id === $ledgerAccount->id) {
                    return $this->respondUnprocessable(errors: ['parent_account_public_id' => ['The parent account cannot reference itself.']]);
                }
                if ($ledgerAccount->agency_id !== null && $parent->agency_id !== null && $ledgerAccount->agency_id !== $parent->agency_id) {
                    return $this->respondUnprocessable(errors: ['parent_account_public_id' => ['The selected parent account must belong to the same agency scope.']]);
                }

                $ancestor = $parent->parentAccount;
                while ($ancestor instanceof LedgerAccount) {
                    if ($ancestor->id === $ledgerAccount->id) {
                        return $this->respondUnprocessable(errors: ['parent_account_public_id' => ['The selected parent account would create a cycle.']]);
                    }
                    $ancestor = $ancestor->parentAccount;
                }
            }

            $ledgerAccount->parent_account_id = $parent?->id;
            unset($validated['parent_account_public_id']);
        }

        $ledgerAccount->fill($validated);
        $ledgerAccount->save();

        return $this->respondSuccess(
            LedgerAccountResource::make($ledgerAccount->loadMissing(['agency', 'parentAccount'])),
            'Ledger account updated successfully'
        );
    }

    public function destroy(Request $request, LedgerAccount $ledgerAccount): JsonResponse
    {
        $actor = $request->user();
        if ($actor instanceof User && $actor->can('delete', $ledgerAccount)) {
            $ledgerAccount->update(['status' => LedgerAccount::STATUS_ARCHIVED]);

            return $this->respondSuccess(message: 'Ledger account archived successfully');
        }

        return $this->respondForbidden();
    }
}
