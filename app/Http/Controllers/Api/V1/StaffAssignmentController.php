<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Staff\ManageStaffAssignment;
use App\Http\Controllers\BaseController;
use App\Http\Resources\StaffAgencyAssignmentCollection;
use App\Http\Resources\StaffAgencyAssignmentResource;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffAssignmentController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly ManageStaffAssignment $manageStaffAssignment,
    ) {}

    /**
     * List staff assignments
     *
     * @authenticated
     *
     * @response StaffAgencyAssignmentCollection
     */
    public function index(Request $request, User $staffUser): StaffAgencyAssignmentCollection|JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorize('viewAny', [StaffAgencyAssignment::class, $staffUser]);

        if ($actor->hasRole('platform-admin')) {
            $assignments = StaffAgencyAssignment::query()
                ->where('user_id', $staffUser->id)
                ->with('agency')
                ->get()
                ->sortByDesc('starts_on')
                ->values();
        } else {
            $actorAgencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($actorAgencyId === null) {
                return $this->respondForbidden();
            }

            $assignments = StaffAgencyAssignment::query()
                ->where('user_id', $staffUser->id)
                ->where('agency_id', $actorAgencyId)
                ->with('agency')
                ->get()
                ->sortByDesc('starts_on')
                ->values();
        }

        return new StaffAgencyAssignmentCollection($assignments);
    }

    /**
     * Create staff assignment
     *
     * @authenticated
     *
     * @response 201 StaffAgencyAssignmentResource
     */
    public function store(Request $request, User $staffUser): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorize('create', [StaffAgencyAssignment::class, $staffUser]);

        $validated = Validator::make($request->all(), [
            'agency_code' => ['nullable', 'string', 'exists:agencies,code'],
            'role_at_agency' => ['required', 'string', 'max:64'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'is_primary' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in([StaffAgencyAssignment::STATUS_ACTIVE, StaffAgencyAssignment::STATUS_ENDED])],
            'transfer_from_assignment_public_id' => ['nullable', 'string', 'exists:staff_agency_assignments,public_id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $roleAtAgency = $validated['role_at_agency'];
        if ($roleAtAgency === 'platform-admin') {
            return $this->respondForbidden('Platform authority cannot be assigned through agency assignments.');
        }

        $transferFromPublicId = $validated['transfer_from_assignment_public_id'] ?? null;
        if (is_string($transferFromPublicId) && $transferFromPublicId !== '') {
            $transferFromAssignment = StaffAgencyAssignment::query()
                ->where('public_id', $transferFromPublicId)
                ->first();

            if (! $transferFromAssignment instanceof StaffAgencyAssignment
                || $transferFromAssignment->user_id !== $staffUser->id
                || $actor->cannot('update', [StaffAgencyAssignment::class, $staffUser, $transferFromAssignment])) {
                return $this->respondForbidden();
            }
        }

        try {
            $assignment = $this->manageStaffAssignment->create($actor, $staffUser, $validated);
        } catch (ValidationException $exception) {
            return $this->respondUnprocessable(errors: $exception->errors());
        }

        $this->securityAudit->record('staff.assignment_created', actor: $actor, subject: $assignment, properties: [
            'staff_public_id' => $staffUser->public_id,
            'agency_public_id' => $assignment->agency->public_id ?? null,
            'reason' => $validated['reason'] ?? null,
        ], request: $request);

        return $this->respondCreated(
            StaffAgencyAssignmentResource::make($assignment),
            'Assignment created successfully'
        );
    }

    /**
     * Update staff assignment
     *
     * @authenticated
     *
     * @response StaffAgencyAssignmentResource
     */
    public function update(Request $request, User $staffUser, StaffAgencyAssignment $assignment): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->authorize('update', [StaffAgencyAssignment::class, $staffUser, $assignment]);

        $validated = Validator::make($request->all(), [
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', 'string', Rule::in([StaffAgencyAssignment::STATUS_ENDED, StaffAgencyAssignment::STATUS_ACTIVE])],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();

        try {
            $assignment = $this->manageStaffAssignment->end($staffUser, $assignment, $validated);
        } catch (ValidationException $exception) {
            return $this->respondUnprocessable(errors: $exception->errors());
        }

        $this->securityAudit->record('staff.assignment_ended', actor: $actor, subject: $assignment, properties: [
            'staff_public_id' => $staffUser->public_id,
            'agency_public_id' => $assignment->agency->public_id ?? null,
            'reason' => $validated['reason'] ?? null,
        ], request: $request);

        return $this->respondSuccess(
            StaffAgencyAssignmentResource::make($assignment->refresh()->loadMissing('agency')),
            'Assignment updated successfully'
        );
    }
}
