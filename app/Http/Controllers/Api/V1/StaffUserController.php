<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\CreateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRolesRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserStatusRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Support\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffUserController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('users.view') !== true) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $users = User::query()->latest()->paginate($perPage);

        return $this->respondSuccess([
            'users' => StaffUserResource::collection($users->getCollection())->resolve(),
        ], meta: [
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function store(CreateStaffUserRequest $request, OtpService $otpService): JsonResponse
    {
        $actor = $request->user();

        $user = User::query()->create([
            'name' => $request->string('name')->toString(),
            'phone_number' => $request->string('phone_number')->toString(),
            'email' => $request->filled('email') ? $request->string('email')->toString() : null,
            'matricule' => $request->filled('matricule') ? $request->string('matricule')->toString() : null,
            'job_title' => $request->filled('job_title') ? $request->string('job_title')->toString() : null,
            'agency_code' => $request->filled('agency_code') ? $request->string('agency_code')->toString() : null,
            'agency_name' => $request->filled('agency_name') ? $request->string('agency_name')->toString() : null,
            'status' => User::STATUS_PENDING_VERIFICATION,
            'invited_by_user_id' => $actor instanceof User ? $actor->id : null,
        ]);

        $user->assignRole('staff');
        $otpService->issueActivationChallenge($user, $request);

        return $this->respondCreated([
            'user' => StaffUserResource::make($user)->resolve(),
        ], 'Staff user created successfully');
    }

    public function show(Request $request, User $staffUser): JsonResponse
    {
        if ($request->user()?->can('users.view') !== true) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess([
            'user' => StaffUserResource::make($staffUser)->resolve(),
        ]);
    }

    public function update(UpdateStaffUserRequest $request, User $staffUser): JsonResponse
    {
        if (! $this->canManagePlatformAdmin($request, $staffUser)) {
            return $this->respondForbidden('Only platform administrators can manage platform administrators.');
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

        if (array_key_exists('phone_number', $attributes)
            && $attributes['phone_number'] !== $staffUser->phone_number) {
            $attributes['phone_verified_at'] = null;
            $attributes['status'] = User::STATUS_PENDING_VERIFICATION;
            $this->revokeAllTokens($staffUser);
        }

        $staffUser->update($attributes);

        return $this->respondSuccess([
            'user' => StaffUserResource::make($staffUser->refresh())->resolve(),
        ], 'Staff user updated successfully');
    }

    public function updateStatus(UpdateStaffUserStatusRequest $request, User $staffUser): JsonResponse
    {
        if (! $this->canManagePlatformAdmin($request, $staffUser)) {
            return $this->respondForbidden('Only platform administrators can manage platform administrators.');
        }

        $status = $request->string('status')->toString();

        if ($status === User::STATUS_ACTIVE && $staffUser->phone_verified_at === null) {
            return $this->respondUnprocessable('Staff user must verify their phone number before activation.');
        }

        $staffUser->forceFill(['status' => $status])->save();

        if (in_array($status, [User::STATUS_SUSPENDED, User::STATUS_DEACTIVATED], true)) {
            $this->revokeAllTokens($staffUser);
        }

        return $this->respondSuccess([
            'user' => StaffUserResource::make($staffUser->refresh())->resolve(),
        ], 'Staff user status updated successfully');
    }

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

        if (in_array('platform-admin', $roles, true)
            && (! $actor instanceof User || ! $actor->hasRole('platform-admin'))) {
            return $this->respondForbidden('Only platform administrators can grant platform-admin.');
        }

        $staffUser->syncRoles($roles);

        return $this->respondSuccess([
            'user' => StaffUserResource::make($staffUser->refresh())->resolve(),
        ], 'Staff user roles updated successfully');
    }

    private function revokeAllTokens(User $user): void
    {
        foreach ($user->tokens()->get() as $token) {
            $token->delete();
        }
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
