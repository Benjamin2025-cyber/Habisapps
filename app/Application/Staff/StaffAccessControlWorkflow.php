<?php

declare(strict_types=1);

namespace App\Application\Staff;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\UpdateStaffUserRolesRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserStatusRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;

final class StaffAccessControlWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly SyncStaffUser $syncStaffUser,
    ) {}

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
            StaffUserResource::make($staffUser->refresh()->loadMissing(['agency', 'hrEmployee.supervisor'])),
            'Staff user status updated successfully'
        );
    }

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
            StaffUserResource::make($staffUser->refresh()->loadMissing(['agency', 'hrEmployee.supervisor'])),
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
