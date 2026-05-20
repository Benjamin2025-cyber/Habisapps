<?php

declare(strict_types=1);

namespace App\Application\Staff;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\CreateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRequest;
use App\Http\Resources\StaffUserCollection;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Support\Otp\OtpService;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffUserProfileWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly SyncStaffUser $syncStaffUser,
        private readonly OtpService $otpService,
    ) {}

    public function index(Request $request): StaffUserCollection|JsonResponse
    {
        $actor = $request->user();
        $this->authorize('viewAny', User::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = User::query()->with(['agency', 'hrEmployee.supervisor'])->latest();

        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin')) {
            $agencyId = $actor->currentAgencyId();

            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $query->whereKey($this->staffAgencyScope->currentAgencyStaffIdList($agencyId));
        }

        return new StaffUserCollection(
            $query->paginate($perPage)
        );
    }

    public function store(CreateStaffUserRequest $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $this->authorize('create', User::class);

        $agencyAttributes = $this->syncStaffUser->resolveAgencyAttributes(
            $request->filled('agency_code') ? $request->string('agency_code')->toString() : null,
        );

        if (! $this->syncStaffUser->canAssignAgency($actor, $agencyAttributes['agency_id'], $actor->currentAgencyId() ?? -1)) {
            return $this->respondForbidden('Staff can only be created inside your agency scope.');
        }

        $user = $this->syncStaffUser->create($actor, [
            'name' => $request->string('name')->toString(),
            'phone_number' => $request->string('phone_number')->toString(),
            'email' => $request->filled('email') ? $request->string('email')->toString() : null,
            'matricule' => $request->filled('matricule') ? $request->string('matricule')->toString() : null,
            'job_title' => $request->filled('job_title') ? $request->string('job_title')->toString() : null,
            'gender' => $request->filled('gender') ? $request->string('gender')->toString() : null,
            'birth_date' => $request->filled('birth_date') ? $request->date('birth_date')?->toDateString() : null,
            'birth_place' => $request->filled('birth_place') ? $request->string('birth_place')->toString() : null,
            'service_name' => $request->filled('service_name') ? $request->string('service_name')->toString() : null,
            'supervisor_id' => $this->supervisorId($request->input('supervisor_public_id')),
            'portfolio_code' => $request->filled('portfolio_code') ? $request->string('portfolio_code')->toString() : null,
            'agency_id' => $agencyAttributes['agency_id'],
            'agency_code' => $agencyAttributes['agency_code'],
            'agency_name' => $agencyAttributes['agency_name'],
        ]);

        $this->otpService->issueActivationChallenge($user, $request);
        $this->securityAudit->record('staff.created', actor: $actor, subject: $user, request: $request);

        return $this->respondCreated(
            StaffUserResource::make($user->loadMissing(['agency', 'hrEmployee.supervisor'])),
            'Staff user created successfully'
        );
    }

    public function show(Request $request, User $staffUser): JsonResponse
    {
        $this->authorize('view', $staffUser);

        return $this->respondSuccess(
            StaffUserResource::make($staffUser->loadMissing(['agency', 'hrEmployee.supervisor']))
        );
    }

    public function update(UpdateStaffUserRequest $request, User $staffUser): JsonResponse
    {
        $this->authorize('update', $staffUser);

        $attributes = $request->safe()->only([
            'name',
            'phone_number',
            'email',
            'matricule',
            'job_title',
            'gender',
            'birth_date',
            'birth_place',
            'service_name',
            'portfolio_code',
            'agency_code',
        ]);

        if ($request->has('supervisor_public_id')) {
            $attributes['supervisor_id'] = $this->supervisorId($request->input('supervisor_public_id'));
        }

        if (array_key_exists('agency_code', $attributes)) {
            $agencyAttributes = $this->syncStaffUser->resolveAgencyAttributes(
                is_string($attributes['agency_code']) ? $attributes['agency_code'] : null,
            );

            $actor = $request->user();
            if (! $actor instanceof User || ! $this->syncStaffUser->canAssignAgency($actor, $agencyAttributes['agency_id'], $actor->currentAgencyId() ?? -1)) {
                return $this->respondForbidden('Staff can only be assigned inside your agency scope.');
            }

            $attributes = array_merge($attributes, $agencyAttributes);
        }

        if (array_key_exists('phone_number', $attributes)
            && $attributes['phone_number'] !== $staffUser->phone_number) {
            $attributes['phone_verified_at'] = null;
            $attributes['status'] = User::STATUS_PENDING_VERIFICATION;
            $this->revokeAllTokens($staffUser);
        }

        $this->syncStaffUser->update($staffUser, $attributes);

        $this->securityAudit->record('staff.updated', actor: $request->user() instanceof User ? $request->user() : null, subject: $staffUser, properties: [
            'changed_fields' => array_keys($attributes),
        ], request: $request);

        return $this->respondSuccess(
            StaffUserResource::make($staffUser->refresh()->loadMissing(['agency', 'hrEmployee.supervisor'])),
            'Staff user updated successfully'
        );
    }

    private function revokeAllTokens(User $user): void
    {
        foreach ($user->tokens()->get() as $token) {
            $token->delete();
        }
    }

    private function supervisorId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $id = User::query()->where('public_id', $publicId)->value('id');

        return is_int($id) ? $id : null;
    }
}
