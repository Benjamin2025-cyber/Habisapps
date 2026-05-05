<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Staff\SyncStaffUser;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\CreateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRolesRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserStatusRequest;
use App\Http\Resources\StaffUserCollection;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Support\Otp\OtpService;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffUserController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly SyncStaffUser $syncStaffUser,
    ) {}

    /**
     * List staff users
     *
     * @authenticated
     *
     * @response StaffUserCollection
     */
    public function index(Request $request): StaffUserCollection|JsonResponse
    {
        $actor = $request->user();
        $this->authorize('viewAny', User::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = User::query()->with('agency')->latest();

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

    /**
     * Create staff user
     *
     * @authenticated
     *
     * @response 201 StaffUserResource
     */
    public function store(CreateStaffUserRequest $request, OtpService $otpService): JsonResponse
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
            'agency_id' => $agencyAttributes['agency_id'],
            'agency_code' => $agencyAttributes['agency_code'],
            'agency_name' => $agencyAttributes['agency_name'],
        ]);

        $otpService->issueActivationChallenge($user, $request);
        $this->securityAudit->record('staff.created', actor: $actor, subject: $user, request: $request);

        return $this->respondCreated(
            StaffUserResource::make($user->loadMissing('agency')),
            'Staff user created successfully'
        );
    }

    /**
     * Get staff user
     *
     * @authenticated
     *
     * @response StaffUserResource
     */
    public function show(Request $request, User $staffUser): JsonResponse
    {
        $this->authorize('view', $staffUser);

        return $this->respondSuccess(
            StaffUserResource::make($staffUser->loadMissing('agency'))
        );
    }

    /**
     * Update staff user
     *
     * @authenticated
     *
     * @response StaffUserResource
     */
    public function update(UpdateStaffUserRequest $request, User $staffUser): JsonResponse
    {
        $this->authorize('update', $staffUser);

        $attributes = $request->safe()->only([
            'name',
            'phone_number',
            'email',
            'matricule',
            'job_title',
            'agency_code',
            'agency_name',
        ]);

        unset($attributes['agency_name']);

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
            StaffUserResource::make($staffUser->refresh()->loadMissing('agency')),
            'Staff user updated successfully'
        );
    }

    /**
     * Update staff user status
     *
     * @authenticated
     *
     * @response StaffUserResource
     */
    public function updateStatus(UpdateStaffUserStatusRequest $request, User $staffUser): JsonResponse
    {
        $this->authorize('updateStatus', $staffUser);

        $status = $request->string('status')->toString();

        if ($status === User::STATUS_ACTIVE && $staffUser->phone_verified_at === null) {
            return $this->respondUnprocessable('Staff user must verify their phone number before activation.');
        }

        if (in_array($status, [User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED], true)
            && $this->syncStaffUser->wouldRemoveLastActivePlatformAdmin($staffUser)) {
            return $this->respondUnprocessable('At least one active platform administrator must remain.');
        }

        $staffUser->forceFill(['status' => $status])->save();

        if (in_array($status, [User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED], true)) {
            $this->revokeAllTokens($staffUser);
        }
        $this->securityAudit->record('staff.status_changed', actor: $request->user() instanceof User ? $request->user() : null, subject: $staffUser, properties: [
            'status' => $status,
        ], request: $request);

        return $this->respondSuccess(
            StaffUserResource::make($staffUser->refresh()->loadMissing('agency')),
            'Staff user status updated successfully'
        );
    }

    /**
     * Update staff user roles
     *
     * @authenticated
     *
     * @response StaffUserResource
     */
    public function updateRoles(UpdateStaffUserRolesRequest $request, User $staffUser): JsonResponse
    {
        $actor = $request->user();
        $roles = $request->input('roles');

        if (! is_array($roles)) {
            return $this->respondUnprocessable('Roles must be an array.');
        }

        $this->authorize('updateRoles', $staffUser);

        if (in_array('platform-admin', $roles, true)
            && (! $actor instanceof User || ! $actor->hasRole('platform-admin'))) {
            return $this->respondForbidden('Only platform administrators can grant platform-admin.');
        }

        if ($staffUser->hasRole('platform-admin')
            && ! in_array('platform-admin', $roles, true)
            && $this->syncStaffUser->wouldRemoveLastActivePlatformAdmin($staffUser)) {
            return $this->respondUnprocessable('At least one active platform administrator must remain.');
        }

        $staffUser->syncRoles($roles);
        $this->securityAudit->record('staff.roles_changed', actor: $actor instanceof User ? $actor : null, subject: $staffUser, properties: [
            'roles' => $roles,
        ], request: $request);

        return $this->respondSuccess(
            StaffUserResource::make($staffUser->refresh()->loadMissing('agency')),
            'Staff user roles updated successfully'
        );
    }

    private function revokeAllTokens(User $user): void
    {
        foreach ($user->tokens()->get() as $token) {
            $token->delete();
        }
    }
}
