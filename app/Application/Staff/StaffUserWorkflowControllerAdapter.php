<?php

declare(strict_types=1);

namespace App\Application\Staff;

use App\Http\Requests\Api\V1\CreateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRolesRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserStatusRequest;
use App\Http\Resources\StaffUserCollection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffUserWorkflowControllerAdapter
{
    public function __construct(
        private readonly StaffUserProfileWorkflow $profile,
        private readonly StaffAccessControlWorkflow $access,
    ) {}

    public function index(Request $request): StaffUserCollection|JsonResponse
    {
        return $this->profile->index($request);
    }

    public function store(CreateStaffUserRequest $request): JsonResponse
    {
        return $this->profile->store($request);
    }

    public function show(Request $request, User $staffUser): JsonResponse
    {
        return $this->profile->show($request, $staffUser);
    }

    public function update(UpdateStaffUserRequest $request, User $staffUser): JsonResponse
    {
        return $this->profile->update($request, $staffUser);
    }

    public function updateStatus(UpdateStaffUserStatusRequest $request, User $staffUser): JsonResponse
    {
        return $this->access->updateStatus($request, $staffUser);
    }

    public function updateRoles(UpdateStaffUserRolesRequest $request, User $staffUser): JsonResponse
    {
        return $this->access->updateRoles($request, $staffUser);
    }
}
