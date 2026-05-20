<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Staff\StaffUserWorkflowControllerAdapter;
use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\CreateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRolesRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserStatusRequest;
use App\Http\Resources\StaffUserCollection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StaffUserController extends BaseController
{
    public function __construct(
        private readonly StaffUserWorkflowControllerAdapter $staff,
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
        return $this->staff->index($request);
    }

    /**
     * Create staff user
     *
     * @authenticated
     *
     * @response 201 StaffUserResource
     */
    public function store(CreateStaffUserRequest $request): JsonResponse
    {
        return $this->staff->store($request);
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
        return $this->staff->show($request, $staffUser);
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
        return $this->staff->update($request, $staffUser);
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
        return $this->staff->updateStatus($request, $staffUser);
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
        return $this->staff->updateRoles($request, $staffUser);
    }
}
