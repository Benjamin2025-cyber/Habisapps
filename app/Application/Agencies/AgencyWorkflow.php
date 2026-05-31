<?php

declare(strict_types=1);

namespace App\Application\Agencies;

use App\Http\Controllers\BaseController;
use App\Http\Resources\AgencyCollection;
use App\Http\Resources\AgencyResource;
use App\Models\Agency;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class AgencyWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly CreateAgency $createAgency,
        private readonly AssignAgencyManager $assignAgencyManager,
    ) {}

    public function index(Request $request): AgencyCollection|JsonResponse
    {
        $actor = $request->user();
        $this->authorize('viewAny', Agency::class);
        $query = Agency::query()->with('manager')->latest();

        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            if (! $actor instanceof User) {
                return $this->respondForbidden();
            }

            $currentAgencyId = $actor->currentAgencyId();

            if ($currentAgencyId === null) {
                return $this->respondForbidden();
            }

            $query->whereKey($currentAgencyId);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('code', 'ilike', '%'.$term.'%')
                    ->orWhere('name', 'ilike', '%'.$term.'%')
                    ->orWhere('city', 'ilike', '%'.$term.'%')
                    ->orWhere('region', 'ilike', '%'.$term.'%')
                    ->orWhere('branch_name', 'ilike', '%'.$term.'%')
                    ->orWhere('email', 'ilike', '%'.$term.'%')
                    ->orWhere('phone_number', 'ilike', '%'.$term.'%');
            });
        }

        $status = $request->query('status');
        if (is_string($status) && trim($status) !== '') {
            $status = trim($status);
            if (in_array($status, [
                Agency::STATUS_ACTIVE,
                Agency::STATUS_INACTIVE,
                Agency::STATUS_SUSPENDED,
                Agency::STATUS_ARCHIVED,
            ], true)) {
                $query->where('status', $status);
            }
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new AgencyCollection($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Agency::class);

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:32', 'unique:agencies,code'],
            'name' => ['required', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:128'],
            'city' => ['nullable', 'string', 'max:128'],
            'branch_name' => ['nullable', 'string', 'max:128'],
            'branch_type' => ['nullable', 'string', 'max:64'],
            'phone_number' => ['nullable', 'string', 'max:32'],
            'fax_number' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'po_box' => ['nullable', 'string', 'max:128'],
            'geographic_description' => ['nullable', 'string', 'max:2000'],
            'creation_date' => ['nullable', 'date'],
            'status' => ['nullable', 'string', Rule::in([
                Agency::STATUS_ACTIVE,
                Agency::STATUS_INACTIVE,
                Agency::STATUS_SUSPENDED,
                Agency::STATUS_ARCHIVED,
            ])],
            'manager_public_id' => ['nullable', 'string', 'exists:users,public_id'],
        ])->validate();

        $agency = $this->createAgency->execute($validated);

        $this->securityAudit->record('agency.created', actor: $request->user(), subject: $agency, request: $request);

        return $this->respondCreated(AgencyResource::make($agency), 'Agency created successfully');
    }

    public function show(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize('view', $agency);

        return $this->respondSuccess(AgencyResource::make($agency->loadMissing('manager')));
    }

    public function update(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize('update', $agency);

        $validated = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'region' => ['sometimes', 'nullable', 'string', 'max:128'],
            'city' => ['sometimes', 'nullable', 'string', 'max:128'],
            'branch_name' => ['sometimes', 'nullable', 'string', 'max:128'],
            'branch_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'fax_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'address_line_1' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'po_box' => ['sometimes', 'nullable', 'string', 'max:128'],
            'geographic_description' => ['sometimes', 'nullable', 'string', 'max:2000'],
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

    public function updateStatus(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize('updateStatus', $agency);

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

    public function destroy(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize('delete', $agency);

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

    public function updateManager(Request $request, Agency $agency): JsonResponse
    {
        $this->authorize('updateManager', $agency);

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

            $this->assignAgencyManager->execute($agency, $manager, $roleAtAgency);
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
}
