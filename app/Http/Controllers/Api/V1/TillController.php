<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreTillRequest;
use App\Http\Requests\UpdateTillRequest;
use App\Http\Resources\TillCollection;
use App\Http\Resources\TillResource;
use App\Models\Agency;
use App\Models\Till;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $query = Till::query()->with(['agency', 'assignedUser'])->latest();

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

        $till = Till::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency->id,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'type' => $request->input('type', Till::TYPE_COUNTER),
            'status' => $request->input('status', Till::STATUS_ACTIVE),
            'assigned_user_id' => $assignedUserId,
        ]);

        $this->securityAudit->record('cash.till.created', actor: $actor, subject: $till, properties: [
            'agency_public_id' => $agency->public_id,
            'code' => $till->code,
        ], request: $request);

        return $this->respondCreated(
            TillResource::make($till->loadMissing(['agency', 'assignedUser'])),
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

        return $this->respondSuccess(TillResource::make($till->loadMissing(['agency', 'assignedUser'])));
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

        $till->fill($validated)->save();

        $this->securityAudit->record('cash.till.updated', actor: $actor, subject: $till, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(
            TillResource::make($till->refresh()->loadMissing(['agency', 'assignedUser'])),
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
}
