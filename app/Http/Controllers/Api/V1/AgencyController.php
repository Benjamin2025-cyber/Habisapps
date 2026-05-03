<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\AgencyCollection;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AgencyController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    /**
     * List agencies
     *
     * @authenticated
     */
    public function index(Request $request): AgencyCollection|JsonResponse
    {
        if ($request->user()?->can('agencies.view') !== true) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        $query = Agency::query()->with('manager')->latest();

        if (! $actor->hasRole('platform-admin')) {
            $currentAgencyId = $actor->currentAgencyId();

            if ($currentAgencyId === null) {
                return $this->respondForbidden();
            }

            $query->whereKey($currentAgencyId);
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new AgencyCollection(
            $query->paginate($perPage)
        );
    }

    /**
     * Create agency
     *
     * @authenticated
     *
     * @response 201 AgencyResource
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->user()?->can('agencies.manage') !== true) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:32', 'unique:agencies,code'],
            'name' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:128'],
            'city' => ['nullable', 'string', 'max:128'],
            'branch_name' => ['nullable', 'string', 'max:128'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'creation_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in([
                Agency::STATUS_ACTIVE,
                Agency::STATUS_INACTIVE,
                Agency::STATUS_SUSPENDED,
                Agency::STATUS_ARCHIVED,
            ])],
            'manager_public_id' => ['nullable', 'string', 'exists:users,public_id'],
        ])->validate();

        $agency = DB::transaction(function () use ($validated): Agency {
            /** @var Agency $agency */
            $agency = Agency::query()->create([
                'code' => $validated['code'],
                'name' => $validated['name'],
                'region' => $validated['region'] ?? null,
                'city' => $validated['city'] ?? null,
                'branch_name' => $validated['branch_name'] ?? null,
                'phone_number' => $validated['phone_number'] ?? null,
                'email' => $validated['email'] ?? null,
                'address_line_1' => $validated['address_line_1'] ?? null,
                'address_line_2' => $validated['address_line_2'] ?? null,
                'creation_date' => $validated['creation_date'] ?? null,
                'status' => $validated['status'] ?? Agency::STATUS_ACTIVE,
            ]);

            if (isset($validated['manager_public_id'])) {
                $this->assignManager($agency, $validated['manager_public_id']);
            }

            return $agency->refresh()->loadMissing('manager');
        });

        $this->securityAudit->record('agency.created', actor: $request->user(), subject: $agency, request: $request);

        return $this->respondCreated(
            AgencyResource::make($agency),
            'Agency created successfully'
        );
    }

    /**
     * Get agency
     *
     * @authenticated
     *
     * @response AgencyResource
     */
    public function show(Request $request, Agency $agency): JsonResponse
    {
        if ($request->user()?->can('agencies.view') !== true) {
            return $this->respondForbidden();
        }

        if (! $this->canViewAgency($request, $agency)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(
            AgencyResource::make($agency->loadMissing('manager'))
        );
    }

    /**
     * Update agency
     *
     * @authenticated
     *
     * @response AgencyResource
     */
    public function update(Request $request, Agency $agency): JsonResponse
    {
        if ($request->user()?->can('agencies.manage') !== true) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:128'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'branch_name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'creation_date' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        $agency->update($validated);
        $this->securityAudit->record('agency.updated', actor: $request->user(), subject: $agency, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(
            AgencyResource::make($agency->refresh()->loadMissing('manager')),
            'Agency updated successfully'
        );
    }

    /**
     * Update agency status
     *
     * @authenticated
     *
     * @response AgencyResource
     */
    public function updateStatus(Request $request, Agency $agency): JsonResponse
    {
        if ($request->user()?->can('agencies.manage') !== true) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'status' => ['required', 'string', Rule::in([
                Agency::STATUS_ACTIVE,
                Agency::STATUS_INACTIVE,
                Agency::STATUS_SUSPENDED,
                Agency::STATUS_ARCHIVED,
            ])],
        ])->validate();

        $agency->forceFill(['status' => $validated['status']])->save();
        $this->securityAudit->record('agency.status_changed', actor: $request->user(), subject: $agency, properties: [
            'status' => $validated['status'],
        ], request: $request);

        return $this->respondSuccess(
            AgencyResource::make($agency->refresh()->loadMissing('manager')),
            'Agency status updated successfully'
        );
    }

    /**
     * Archive agency
     *
     * @authenticated
     *
     * @response AgencyResource
     */
    public function destroy(Request $request, Agency $agency): JsonResponse
    {
        if ($request->user()?->can('agencies.manage') !== true) {
            return $this->respondForbidden();
        }

        if ($agency->status === Agency::STATUS_ARCHIVED) {
            return $this->respondSuccess(
                AgencyResource::make($agency->loadMissing('manager')),
                'Agency already archived'
            );
        }

        $agency->forceFill(['status' => Agency::STATUS_ARCHIVED])->save();
        $this->securityAudit->record('agency.archived', actor: $request->user(), subject: $agency, request: $request);

        return $this->respondSuccess(
            AgencyResource::make($agency->refresh()->loadMissing('manager')),
            'Agency archived successfully'
        );
    }

    /**
     * Update agency manager
     *
     * @authenticated
     *
     * @response AgencyResource
     */
    public function updateManager(Request $request, Agency $agency): JsonResponse
    {
        if ($request->user()?->can('agencies.manage') !== true) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'manager_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'role_at_agency' => ['nullable', 'string', 'max:64'],
        ])->validate();

        $managerPublicId = $validated['manager_public_id'] ?? null;
        $roleAtAgency = is_string($validated['role_at_agency'] ?? null) && $validated['role_at_agency'] !== ''
            ? $validated['role_at_agency']
            : 'agency-manager';

        $manager = null;
        if (is_string($managerPublicId) && $managerPublicId !== '') {
            $manager = User::query()->where('public_id', $managerPublicId)->first();

            if ($manager === null) {
                return $this->respondNotFound();
            }

            if ($manager->status !== User::STATUS_ACTIVE) {
                return $this->respondUnprocessable('Manager must be active.');
            }

            $managerAgencyId = $this->staffAgencyScope->currentAgencyId($manager);
            if ($managerAgencyId !== null && $managerAgencyId !== $agency->id) {
                return $this->respondForbidden('Manager must belong to the selected agency.');
            }

            $this->assignManager($agency, $managerPublicId, $roleAtAgency);
        } else {
            $agency->forceFill(['manager_id' => null])->save();
        }

        $this->securityAudit->record('agency.manager_changed', actor: $request->user(), subject: $agency, properties: [
            'manager_public_id' => $manager?->public_id,
            'role_at_agency' => $roleAtAgency,
        ], request: $request);

        return $this->respondSuccess(
            AgencyResource::make($agency->refresh()->loadMissing('manager')),
            'Agency manager updated successfully'
        );
    }

    private function canViewAgency(Request $request, Agency $agency): bool
    {
        $actor = $request->user();

        if ($actor === null) {
            return false;
        }

        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $actor->currentAgencyId() === $agency->id;
    }

    private function assignManager(Agency $agency, string $managerPublicId, string $roleAtAgency = 'agency-manager'): void
    {
        $manager = User::query()->where('public_id', $managerPublicId)->first();
        if ($manager === null) {
            throw ValidationException::withMessages(['manager_public_id' => ['The selected manager is invalid.']]);
        }

        DB::transaction(function () use ($agency, $manager, $roleAtAgency): void {
            $primaryAssignment = StaffAgencyAssignment::query()
                ->where('user_id', $manager->id)
                ->where('agency_id', $agency->id)
                ->where('is_primary', true)
                ->where('status', StaffAgencyAssignment::STATUS_ACTIVE)
                ->latest('starts_on')
                ->first();

            if ($primaryAssignment === null) {
                StaffAgencyAssignment::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'user_id' => $manager->id,
                    'agency_id' => $agency->id,
                    'role_at_agency' => $roleAtAgency,
                    'starts_on' => now()->toDateString(),
                    'is_primary' => true,
                    'status' => StaffAgencyAssignment::STATUS_ACTIVE,
                ]);
            }

            $manager->forceFill([
                'agency_id' => $agency->id,
                'agency_code' => $agency->code,
                'agency_name' => $agency->name,
            ])->save();

            $agency->forceFill(['manager_id' => $manager->id])->save();
        });
    }
}
