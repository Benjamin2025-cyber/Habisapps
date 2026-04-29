<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\StaffAgencyAssignmentResource;
use App\Models\Agency;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StaffAssignmentController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request, User $staffUser): JsonResponse
    {
        if ($request->user()?->can('staff.assignments.view') !== true) {
            return $this->respondForbidden();
        }

        /** @var User $actor */
        $actor = $request->user();

        if (! $this->canAccessStaffUser($actor, $staffUser)) {
            return $this->respondForbidden();
        }

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

        return $this->respondSuccess([
            'assignments' => StaffAgencyAssignmentResource::collection($assignments)->resolve(),
        ]);
    }

    public function store(Request $request, User $staffUser): JsonResponse
    {
        if ($request->user()?->can('staff.assignments.manage') !== true) {
            return $this->respondForbidden();
        }

        /** @var User $actor */
        $actor = $request->user();

        if (! $this->canAccessStaffUser($actor, $staffUser)) {
            return $this->respondForbidden();
        }

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

        $agency = $this->resolveAgency($actor, $validated['agency_code'] ?? null, $staffUser);

        if ($agency === null) {
            return $this->respondNotFound();
        }

        if (! $this->canManageAgency($actor, $agency->id)) {
            return $this->respondForbidden();
        }

        $roleAtAgency = $validated['role_at_agency'];
        if ($roleAtAgency === 'platform-admin') {
            return $this->respondForbidden('Platform authority cannot be assigned through agency assignments.');
        }

        $isPrimary = (bool) ($validated['is_primary'] ?? true);
        $status = $validated['status'] ?? StaffAgencyAssignment::STATUS_ACTIVE;
        $newStartsOn = Carbon::parse($validated['starts_on'])->startOfDay();
        $transferSource = null;

        if (isset($validated['transfer_from_assignment_public_id'])) {
            $transferSource = StaffAgencyAssignment::query()
                ->with('agency')
                ->where('public_id', $validated['transfer_from_assignment_public_id'])
                ->where('user_id', $staffUser->id)
                ->first();

            if ($transferSource === null) {
                throw ValidationException::withMessages(['transfer_from_assignment_public_id' => ['The selected assignment is invalid.']]);
            }

            if (! $this->canManageAgency($actor, $transferSource->agency_id)) {
                return $this->respondForbidden();
            }
        }

        $assignment = DB::transaction(function () use ($staffUser, $agency, $validated, $roleAtAgency, $isPrimary, $status, $newStartsOn, $transferSource): StaffAgencyAssignment {
            if ($isPrimary) {
                $existingPrimary = StaffAgencyAssignment::query()
                    ->where('user_id', $staffUser->id)
                    ->where('is_primary', true)
                    ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
                    ->latest('starts_on')
                    ->first();

                if ($existingPrimary !== null) {
                    $existingStartsOn = Carbon::parse($existingPrimary->starts_on)->startOfDay();
                    if ($newStartsOn->lessThanOrEqualTo($existingStartsOn)) {
                        throw ValidationException::withMessages([
                            'starts_on' => ['Primary assignment transfers must start after the current primary assignment starts.'],
                        ]);
                    }

                    $existingPrimary->forceFill([
                        'status' => StaffAgencyAssignment::STATUS_ENDED,
                        'ends_on' => $newStartsOn->copy()->subDay()->toDateString(),
                    ])->save();
                }
            }

            $assignment = StaffAgencyAssignment::query()->create([
                'public_id' => (string) \Illuminate\Support\Str::ulid(),
                'user_id' => $staffUser->id,
                'agency_id' => $agency->id,
                'role_at_agency' => $roleAtAgency,
                'starts_on' => $validated['starts_on'],
                'ends_on' => $validated['ends_on'] ?? null,
                'is_primary' => $isPrimary,
                'status' => $status,
            ]);

            if ($transferSource !== null) {
                $transferSource->forceFill([
                    'status' => StaffAgencyAssignment::STATUS_ENDED,
                    'ends_on' => $newStartsOn->copy()->subDay()->toDateString(),
                ])->save();
            }

            if ($isPrimary) {
                $staffUser->forceFill([
                    'agency_id' => $agency->id,
                    'agency_code' => $agency->code,
                    'agency_name' => $agency->name,
                ])->save();
            }

            return $assignment->refresh()->loadMissing('agency');
        });

        $this->securityAudit->record('staff.assignment_created', actor: $actor, subject: $assignment, properties: [
            'staff_public_id' => $staffUser->public_id,
            'agency_public_id' => $agency->public_id,
            'reason' => $validated['reason'] ?? null,
        ], request: $request);

        return $this->respondCreated([
            'assignment' => StaffAgencyAssignmentResource::make($assignment)->resolve(),
        ], 'Assignment created successfully');
    }

    public function update(Request $request, User $staffUser, StaffAgencyAssignment $assignment): JsonResponse
    {
        if ($request->user()?->can('staff.assignments.manage') !== true) {
            return $this->respondForbidden();
        }

        /** @var User $actor */
        $actor = $request->user();

        if ($assignment->user_id !== $staffUser->id || ! $this->canAccessStaffUser($actor, $staffUser)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin')) {
            $actorAgencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($actorAgencyId === null || $assignment->agency_id !== $actorAgencyId) {
                return $this->respondForbidden();
            }
        }

        $validated = Validator::make($request->all(), [
            'ends_on' => ['required', 'date'],
            'status' => ['sometimes', 'string', Rule::in([StaffAgencyAssignment::STATUS_ENDED, StaffAgencyAssignment::STATUS_ACTIVE])],
            'reason' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $endsOn = Carbon::parse($validated['ends_on'])->startOfDay();
        $startsOn = Carbon::parse($assignment->starts_on)->startOfDay();
        if ($endsOn->lessThan($startsOn)) {
            return $this->respondUnprocessable('Assignment end date must be on or after the start date.');
        }

        $assignment->forceFill([
            'ends_on' => $validated['ends_on'],
            'status' => $validated['status'] ?? StaffAgencyAssignment::STATUS_ENDED,
            'is_primary' => false,
        ])->save();

        $this->securityAudit->record('staff.assignment_ended', actor: $actor, subject: $assignment, properties: [
            'staff_public_id' => $staffUser->public_id,
            'agency_public_id' => $assignment->agency->public_id ?? null,
            'reason' => $validated['reason'] ?? null,
        ], request: $request);

        return $this->respondSuccess([
            'assignment' => StaffAgencyAssignmentResource::make($assignment->refresh()->loadMissing('agency'))->resolve(),
        ], 'Assignment updated successfully');
    }

    private function canAccessStaffUser(User $actor, User $target): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        $actorAgencyId = $this->staffAgencyScope->currentAgencyId($actor);

        return $actorAgencyId !== null && $this->staffAgencyScope->currentAgencyId($target) === $actorAgencyId;
    }

    private function canManageAgency(User $actor, ?int $agencyId): bool
    {
        if ($agencyId === null) {
            return false;
        }

        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $this->staffAgencyScope->currentAgencyId($actor) === $agencyId;
    }

    private function resolveAgency(User $actor, ?string $agencyCode, User $staffUser): ?Agency
    {
        if (is_string($agencyCode) && $agencyCode !== '') {
            $agency = Agency::query()->where('code', $agencyCode)->first();

            if ($agency !== null) {
                return $agency;
            }
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($staffUser);
        if ($agencyId === null) {
            if ($actor->hasRole('platform-admin') && $actor->currentAgencyId() !== null) {
                return Agency::query()->whereKey($actor->currentAgencyId())->first();
            }

            return null;
        }

        return Agency::query()->whereKey($agencyId)->first();
    }
}
