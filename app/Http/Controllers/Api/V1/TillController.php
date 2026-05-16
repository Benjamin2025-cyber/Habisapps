<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreTillRequest;
use App\Http\Requests\UpdateTillRequest;
use App\Http\Resources\TillCollection;
use App\Http\Resources\TillResource;
use App\Models\Agency;
use App\Models\LedgerAccount;
use App\Models\Till;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TillController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{tills: array<int, \App\Http\Resources\TillResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): TillCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', Till::class)) {
            return $this->respondForbidden();
        }

        $query = Till::query()->with(['agency', 'assignedUser', 'ledgerAccount'])->latest();

        if (! $actor->hasRole('platform-admin')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $query->where('agency_id', $agencyId);
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new TillCollection($query->paginate($perPage));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{till: \App\Http\Resources\TillResource}, errors: null, meta: null}')]
    public function store(StoreTillRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $agency = $this->resolveAgency($actor, $request->input('agency_public_id'));
        if (! $agency instanceof Agency) {
            return $this->respondForbidden('Till can only be created within your agency scope.');
        }

        if ($this->codeExistsInAgency($agency->id, $request->string('code')->toString())) {
            return $this->respondUnprocessable(errors: ['code' => ['The code has already been taken for this agency.']]);
        }

        try {
            $assignedUserId = $this->resolveAssignedUserInAgency($agency->id, $request->input('assigned_user_public_id'));
        } catch (ValidationException $exception) {
            return $this->respondUnprocessable(errors: $exception->errors());
        }

        $ledgerAccount = $this->resolveLedgerAccount($request->input('ledger_account_public_id'));
        if ($ledgerAccount === false) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account is invalid.']]);
        }

        if ($ledgerAccount instanceof LedgerAccount && ! $this->ledgerAccountIsCompatible($ledgerAccount, $agency)) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be an active asset ledger account in the till agency.']]);
        }

        $status = $request->input('status', Till::STATUS_ACTIVE);
        if ($assignedUserId !== null && $status === Till::STATUS_ACTIVE && $this->activeTillAlreadyAssigned($agency->id, $assignedUserId)) {
            return $this->respondUnprocessable(errors: ['assigned_user_public_id' => ['The selected teller is already assigned to another active till.']]);
        }

        $till = Till::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency->id,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'type' => $request->input('type', Till::TYPE_COUNTER),
            'status' => $status,
            'daily_state' => $request->input('daily_state', Till::DAILY_STATE_CLOSED),
            'opening_balance_minor' => $request->input('opening_balance_minor'),
            'last_closing_balance_minor' => $request->input('last_closing_balance_minor'),
            'requires_denominations' => $request->boolean('requires_denominations', true),
            'nature' => $request->input('nature'),
            'is_central_till' => $request->boolean('is_central_till'),
            'max_balance_limit_minor' => $request->input('max_balance_limit_minor'),
            'max_withdrawal_limit_minor' => $request->input('max_withdrawal_limit_minor'),
            'currency' => $this->normalizedCurrency($request->input('currency')),
            'assigned_user_id' => $assignedUserId,
            'ledger_account_id' => $ledgerAccount instanceof LedgerAccount ? $ledgerAccount->id : null,
        ]);

        $this->securityAudit->record('cash.till.created', actor: $actor, subject: $till, properties: [
            'agency_public_id' => $agency->public_id,
            'code' => $till->code,
        ], request: $request);

        return $this->respondCreated(
            TillResource::make($till->loadMissing(['agency', 'assignedUser', 'ledgerAccount'])),
            'Till created successfully'
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{till: \App\Http\Resources\TillResource}, errors: null, meta: null}')]
    public function show(Request $request, Till $till): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $till)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(TillResource::make($till->loadMissing(['agency', 'assignedUser', 'ledgerAccount'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{till: \App\Http\Resources\TillResource}, errors: null, meta: null}')]
    public function update(UpdateTillRequest $request, Till $till): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = $request->validated();
        $agency = $till->agency;
        if (! $agency instanceof Agency) {
            return $this->respondForbidden('Till must belong to a valid agency.');
        }

        if (array_key_exists('agency_public_id', $validated)) {
            $agency = $this->resolveAgency($actor, $validated['agency_public_id']);
            if (! $agency instanceof Agency) {
                return $this->respondForbidden('Till can only be moved within your agency scope.');
            }
            $validated['agency_id'] = $agency->id;
            unset($validated['agency_public_id']);
        }

        if (array_key_exists('code', $validated)
            && is_string($validated['code'])
            && $this->codeExistsInAgency($agency->id, $validated['code'], $till->id)) {
            return $this->respondUnprocessable(errors: ['code' => ['The code has already been taken for this agency.']]);
        }

        if (array_key_exists('assigned_user_public_id', $validated)) {
            try {
                $validated['assigned_user_id'] = $this->resolveAssignedUserInAgency($agency->id, $validated['assigned_user_public_id']);
            } catch (ValidationException $exception) {
                return $this->respondUnprocessable(errors: $exception->errors());
            }
            unset($validated['assigned_user_public_id']);
        }

        if (array_key_exists('ledger_account_public_id', $validated)) {
            $ledgerAccount = $this->resolveLedgerAccount($validated['ledger_account_public_id']);
            if ($ledgerAccount === false) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account is invalid.']]);
            }

            if ($ledgerAccount instanceof LedgerAccount && ! $this->ledgerAccountIsCompatible($ledgerAccount, $agency)) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The selected ledger account must be an active asset ledger account in the till agency.']]);
            }

            $validated['ledger_account_id'] = $ledgerAccount instanceof LedgerAccount ? $ledgerAccount->id : null;
            unset($validated['ledger_account_public_id']);
        } elseif (array_key_exists('agency_id', $validated) && $till->ledger_account_id !== null) {
            $ledgerAccount = $till->ledgerAccount;
            if (! $ledgerAccount instanceof LedgerAccount || ! $this->ledgerAccountIsCompatible($ledgerAccount, $agency)) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => ['The current ledger account is not compatible with the selected agency.']]);
            }
        }

        if (array_key_exists('currency', $validated) && is_string($validated['currency'])) {
            $validated['currency'] = $this->normalizedCurrency($validated['currency']);
        }

        $effectiveStatus = is_string($validated['status'] ?? null) ? $validated['status'] : $till->status;
        $effectiveAssignedUserId = array_key_exists('assigned_user_id', $validated)
            ? $validated['assigned_user_id']
            : $till->assigned_user_id;
        if (is_int($effectiveAssignedUserId)
            && $effectiveStatus === Till::STATUS_ACTIVE
            && $this->activeTillAlreadyAssigned($agency->id, $effectiveAssignedUserId, $till->id)) {
            return $this->respondUnprocessable(errors: ['assigned_user_public_id' => ['The selected teller is already assigned to another active till.']]);
        }

        $till->fill($validated)->save();

        $this->securityAudit->record('cash.till.updated', actor: $actor, subject: $till, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(
            TillResource::make($till->refresh()->loadMissing(['agency', 'assignedUser', 'ledgerAccount'])),
            'Till updated successfully'
        );
    }

    private function resolveAgency(User $actor, mixed $agencyPublicId): ?Agency
    {
        if ($actor->hasRole('platform-admin')) {
            if (! is_string($agencyPublicId) || $agencyPublicId === '') {
                return null;
            }

            return Agency::query()->where('public_id', $agencyPublicId)->first();
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return null;
        }

        if (is_string($agencyPublicId) && $agencyPublicId !== '') {
            $agency = Agency::query()->where('public_id', $agencyPublicId)->first();

            return $agency instanceof Agency && $agency->id === $agencyId ? $agency : null;
        }

        return Agency::query()->whereKey($agencyId)->first();
    }

    private function resolveAssignedUserInAgency(int $agencyId, mixed $assignedUserPublicId): ?int
    {
        if ($assignedUserPublicId === null || $assignedUserPublicId === '') {
            return null;
        }

        if (! is_string($assignedUserPublicId)) {
            throw ValidationException::withMessages(['assigned_user_public_id' => ['The selected assigned user is invalid.']]);
        }

        $assignedUser = User::query()->where('public_id', $assignedUserPublicId)->first();
        if (! $assignedUser instanceof User || $assignedUser->status !== User::STATUS_ACTIVE || $this->staffAgencyScope->currentAgencyId($assignedUser) !== $agencyId) {
            throw ValidationException::withMessages(['assigned_user_public_id' => ['The selected assigned user must be active staff in the same agency.']]);
        }

        if (! $this->canBeAssignedToTill($assignedUser, $agencyId)) {
            throw ValidationException::withMessages(['assigned_user_public_id' => ['The selected assigned user must be an active teller or cashier in the same agency.']]);
        }

        return $assignedUser->id;
    }

    private function codeExistsInAgency(int $agencyId, string $code, ?int $ignoreTillId = null): bool
    {
        $query = Till::query()
            ->where('agency_id', $agencyId)
            ->where('code', $code);

        if ($ignoreTillId !== null) {
            $query->whereKeyNot($ignoreTillId);
        }

        return $query->first() instanceof Till;
    }

    private function resolveLedgerAccount(mixed $publicId): LedgerAccount|false|null
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        if (! is_string($publicId)) {
            return false;
        }

        $ledgerAccount = LedgerAccount::query()->where('public_id', $publicId)->first();

        return $ledgerAccount instanceof LedgerAccount ? $ledgerAccount : false;
    }

    private function ledgerAccountIsCompatible(LedgerAccount $ledgerAccount, Agency $agency): bool
    {
        return $ledgerAccount->status === LedgerAccount::STATUS_ACTIVE
            && $ledgerAccount->agency_id === $agency->id
            && $ledgerAccount->account_class === LedgerAccount::ACCOUNT_CLASS_ASSET;
    }

    private function normalizedCurrency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'XAF';
    }

    private function canBeAssignedToTill(User $user, int $agencyId): bool
    {
        if ($user->hasRole('teller') || $user->hasRole('cashier')) {
            return true;
        }

        return DB::table('staff_agency_assignments')
            ->where('user_id', $user->id)
            ->where('agency_id', $agencyId)
            ->where('status', 'active')
            ->where('starts_on', '<=', now()->toDateString())
            ->whereRaw('(ends_on IS NULL OR ends_on >= ?)', [now()->toDateString()])
            ->whereIn('role_at_agency', ['teller', 'cashier'])
            ->exists();
    }

    private function activeTillAlreadyAssigned(int $agencyId, int $assignedUserId, ?int $ignoreTillId = null): bool
    {
        $query = Till::query()
            ->where('agency_id', $agencyId)
            ->where('assigned_user_id', $assignedUserId)
            ->where('status', Till::STATUS_ACTIVE);

        if ($ignoreTillId !== null) {
            $query->whereKeyNot($ignoreTillId);
        }

        return $query->first() instanceof Till;
    }
}
