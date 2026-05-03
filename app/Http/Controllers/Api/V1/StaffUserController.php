<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\CreateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRolesRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserStatusRequest;
use App\Http\Resources\StaffUserCollection;
use App\Http\Resources\StaffUserResource;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use App\Support\Otp\OtpService;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class StaffUserController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
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
        if ($request->user()?->can('users.view') !== true) {
            return $this->respondForbidden();
        }

        $actor = $request->user();

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = User::query()->with('agency')->latest();

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

        $agencyAttributes = $this->resolveAgencyAttributes(
            $request->filled('agency_code') ? $request->string('agency_code')->toString() : null,
        );

        if (! $this->canAssignAgency($actor, $agencyAttributes['agency_id'])) {
            return $this->respondForbidden('Staff can only be created inside your agency scope.');
        }

        $user = DB::transaction(function () use ($actor, $agencyAttributes, $request): User {
            $user = User::query()->create([
                'name' => $request->string('name')->toString(),
                'phone_number' => $request->string('phone_number')->toString(),
                'email' => $request->filled('email') ? $request->string('email')->toString() : null,
                'matricule' => $request->filled('matricule') ? $request->string('matricule')->toString() : null,
                'job_title' => $request->filled('job_title') ? $request->string('job_title')->toString() : null,
                'agency_id' => $agencyAttributes['agency_id'],
                'agency_code' => $agencyAttributes['agency_code'],
                'agency_name' => $agencyAttributes['agency_name'],
                'status' => User::STATUS_PENDING_VERIFICATION,
                'invited_by_user_id' => $actor->id,
            ]);

            if ($agencyAttributes['agency_id'] !== null) {
                $this->createPrimaryAssignment($user, $agencyAttributes['agency_id'], $user->job_title ?? 'staff');
            }

            $user->assignRole('staff');

            return $user;
        });

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
        if ($request->user()?->can('users.view') !== true) {
            return $this->respondForbidden();
        }

        if (! $this->canAccessStaffUser($request, $staffUser)) {
            return $this->respondForbidden();
        }

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
        if (! $this->canManagePlatformAdmin($request, $staffUser)) {
            return $this->respondForbidden('Only platform administrators can manage platform administrators.');
        }

        if (! $this->canAccessStaffUser($request, $staffUser)) {
            return $this->respondForbidden();
        }

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
            $agencyAttributes = $this->resolveAgencyAttributes(
                is_string($attributes['agency_code']) ? $attributes['agency_code'] : null,
            );

            $actor = $request->user();
            if (! $actor instanceof User || ! $this->canAssignAgency($actor, $agencyAttributes['agency_id'])) {
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

        DB::transaction(function () use ($attributes, $staffUser): void {
            $staffUser->update($attributes);

            if (array_key_exists('agency_id', $attributes)) {
                $agencyId = $attributes['agency_id'];
                $this->replacePrimaryAssignment($staffUser, is_int($agencyId) ? $agencyId : null, $staffUser->job_title ?? 'staff');
            }
        });

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
        if (! $this->canManagePlatformAdmin($request, $staffUser)) {
            return $this->respondForbidden('Only platform administrators can manage platform administrators.');
        }

        if (! $this->canAccessStaffUser($request, $staffUser)) {
            return $this->respondForbidden();
        }

        $status = $request->string('status')->toString();

        if ($status === User::STATUS_ACTIVE && $staffUser->phone_verified_at === null) {
            return $this->respondUnprocessable('Staff user must verify their phone number before activation.');
        }

        if (in_array($status, [User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED], true)
            && $this->wouldRemoveLastActivePlatformAdmin($staffUser)) {
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

        if (! $this->canManagePlatformAdmin($request, $staffUser)) {
            return $this->respondForbidden('Only platform administrators can manage platform administrators.');
        }

        if (! $this->canAccessStaffUser($request, $staffUser)) {
            return $this->respondForbidden();
        }

        if (in_array('platform-admin', $roles, true)
            && (! $actor instanceof User || ! $actor->hasRole('platform-admin'))) {
            return $this->respondForbidden('Only platform administrators can grant platform-admin.');
        }

        if ($staffUser->hasRole('platform-admin')
            && ! in_array('platform-admin', $roles, true)
            && $this->wouldRemoveLastActivePlatformAdmin($staffUser)) {
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

    /**
     * @return array{agency_id:int|null, agency_code:string|null, agency_name:string|null}
     */
    private function resolveAgencyAttributes(?string $agencyCode): array
    {
        if (! is_string($agencyCode) || $agencyCode === '') {
            return [
                'agency_id' => null,
                'agency_code' => null,
                'agency_name' => null,
            ];
        }

        $agency = DB::table('agencies')
            ->where('code', $agencyCode)
            ->first(['id', 'code', 'name']);

        if ($agency === null) {
            return [
                'agency_id' => null,
                'agency_code' => $agencyCode,
                'agency_name' => null,
            ];
        }

        return [
            'agency_id' => is_numeric($agency->id) ? (int) $agency->id : null,
            'agency_code' => is_string($agency->code) ? $agency->code : $agencyCode,
            'agency_name' => is_string($agency->name) ? $agency->name : null,
        ];
    }

    private function canAssignAgency(User $actor, ?int $agencyId): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $agencyId !== null && $this->staffAgencyScope->currentAgencyId($actor) === $agencyId;
    }

    private function canAccessStaffUser(Request $request, User $target): bool
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            return false;
        }

        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        $actorAgencyId = $this->staffAgencyScope->currentAgencyId($actor);

        return $actorAgencyId !== null && $this->staffAgencyScope->currentAgencyId($target) === $actorAgencyId;
    }

    private function createPrimaryAssignment(User $user, int $agencyId, string $roleAtAgency): void
    {
        (new StaffAgencyAssignment)->newQuery()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => $roleAtAgency,
            'starts_on' => now()->toDateString(),
            'is_primary' => true,
            'status' => StaffAgencyAssignment::STATUS_ACTIVE,
        ]);
    }

    private function replacePrimaryAssignment(User $user, ?int $agencyId, string $roleAtAgency): void
    {
        $today = now()->toDateString();
        $currentAssignment = DB::table('staff_agency_assignments')
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
            ->where('starts_on', '<=', $today)
            ->whereRaw('(ends_on IS NULL OR ends_on >= ?)', [$today])
            ->latest('starts_on')
            ->first(['agency_id']);

        if (is_object($currentAssignment)
            && property_exists($currentAssignment, 'agency_id')
            && is_numeric($currentAssignment->agency_id)
            && (int) $currentAssignment->agency_id === $agencyId) {
            return;
        }

        (new StaffAgencyAssignment)->newQuery()
            ->where('user_id', $user->id)
            ->where('is_primary', true)
            ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
            ->update([
                'status' => StaffAgencyAssignment::STATUS_ENDED,
                'ends_on' => now()->toDateString(),
                'updated_at' => now(),
            ]);

        if ($agencyId !== null) {
            $this->createPrimaryAssignment($user, $agencyId, $roleAtAgency);
        }
    }

    private function wouldRemoveLastActivePlatformAdmin(User $target): bool
    {
        if (! $target->hasRole('platform-admin') || $target->status !== User::STATUS_ACTIVE) {
            return false;
        }

        $activePlatformAdmins = DB::table('users')
            ->join('model_has_roles', static function ($join): void {
                $join->on('users.id', '=', 'model_has_roles.model_id')
                    ->where('model_has_roles.model_type', User::class);
            })
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'platform-admin')
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereNotNull('users.phone_verified_at')
            ->count();

        return $activePlatformAdmins <= 1;
    }

    private function canManagePlatformAdmin(Request $request, User $target): bool
    {
        if (! $target->hasRole('platform-admin')) {
            return true;
        }

        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }
}
