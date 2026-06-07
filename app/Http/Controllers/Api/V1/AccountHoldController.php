<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Accounting\ReleaseAccountHold;
use App\Http\Controllers\BaseController;
use App\Http\Requests\ReleaseAccountHoldRequest;
use App\Http\Requests\StoreAccountHoldRequest;
use App\Http\Requests\UpdateAccountHoldRequest;
use App\Http\Resources\AccountHoldCollection;
use App\Http\Resources\AccountHoldResource;
use App\Models\AccountHold;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class AccountHoldController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly ReleaseAccountHold $releaseAccountHold,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{account_holds: array<int, \App\Http\Resources\AccountHoldResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): AccountHoldCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', AccountHold::class)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = AccountHold::query()->with('customerAccount')->latest();

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('public_id', 'ilike', '%'.$term.'%')
                    ->orWhere('reason_type', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('reference', 'ilike', '%'.$term.'%')
                    ->orWhereHas('customerAccount', function (Builder $accountQuery) use ($term): void {
                        $accountQuery
                            ->where('account_number', 'ilike', '%'.$term.'%')
                            ->orWhere('account_title', 'ilike', '%'.$term.'%');
                    });
            });
        }

        return new AccountHoldCollection($query->paginate($perPage));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{account_hold: \App\Http\Resources\AccountHoldResource}, errors: null, meta: null}')]
    public function store(StoreAccountHoldRequest $request): JsonResponse
    {
        $customerAccount = CustomerAccount::query()->where('public_id', $request->string('customer_account_public_id'))->first();
        if (! $customerAccount instanceof CustomerAccount) {
            return $this->respondUnprocessable(errors: ['customer_account_public_id' => [__('The selected customer account is invalid.')]]);
        }
        if ($customerAccount->status === CustomerAccount::STATUS_CLOSED || $customerAccount->status === CustomerAccount::STATUS_ARCHIVED) {
            return $this->respondUnprocessable(errors: ['customer_account_public_id' => [__('Holds cannot be placed on closed or archived customer accounts.')]]);
        }

        $hold = AccountHold::query()->create([
            'public_id' => (string) Str::ulid(),
            'customer_account_id' => $customerAccount->id,
            'amount_minor' => $request->integer('amount_minor'),
            'currency' => $request->string('currency')->toString(),
            'reason_type' => $request->string('reason_type')->toString(),
            'source_type' => $request->input('source_type'),
            'source_public_id' => $request->input('source_public_id'),
            'status' => AccountHold::STATUS_ACTIVE,
            'placed_at' => now(),
            'expires_at' => $request->input('expires_at'),
            'placed_by_user_id' => $request->user()?->id,
            'reference' => $request->input('reference'),
        ]);

        $this->securityAudit->record('account_hold.created', actor: $request->user(), subject: $hold, request: $request);

        return $this->respondCreated(AccountHoldResource::make($hold->loadMissing('customerAccount')), 'Account hold created successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{account_hold: \App\Http\Resources\AccountHoldResource}, errors: null, meta: null}')]
    public function show(Request $request, AccountHold $accountHold): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $accountHold)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(AccountHoldResource::make($accountHold->loadMissing('customerAccount')));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{account_hold: \App\Http\Resources\AccountHoldResource}, errors: null, meta: null}')]
    public function update(UpdateAccountHoldRequest $request, AccountHold $accountHold): JsonResponse
    {
        if ($accountHold->status !== AccountHold::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['account_hold' => [__('Only active holds can be updated.')]]);
        }

        $accountHold->fill($request->validated())->save();

        $this->securityAudit->record('account_hold.updated', actor: $request->user(), subject: $accountHold, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(AccountHoldResource::make($accountHold->loadMissing('customerAccount')), 'Account hold updated successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{account_hold: \App\Http\Resources\AccountHoldResource}, errors: null, meta: null}')]
    public function release(ReleaseAccountHoldRequest $request, AccountHold $accountHold): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $accountHold = $this->releaseAccountHold->handle(
            $accountHold,
            $actor,
            is_string($request->input('reference')) ? $request->string('reference')->toString() : null,
            is_string($request->input('release_reason')) ? $request->string('release_reason')->toString() : null,
        );

        $this->securityAudit->record('account_hold.released', actor: $actor, subject: $accountHold, request: $request);

        return $this->respondSuccess(AccountHoldResource::make($accountHold->loadMissing('customerAccount')), 'Account hold released successfully');
    }

    public function destroy(Request $request, AccountHold $accountHold): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $accountHold)) {
            return $this->respondForbidden();
        }

        $accountHold->update(['status' => AccountHold::STATUS_ARCHIVED]);
        $this->securityAudit->record('account_hold.archived', actor: $request->user(), subject: $accountHold, request: $request);

        return $this->respondSuccess(message: 'Account hold archived successfully');
    }
}
