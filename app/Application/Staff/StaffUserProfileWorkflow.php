<?php

declare(strict_types=1);

namespace App\Application\Staff;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\CreateStaffUserRequest;
use App\Http\Requests\Api\V1\UpdateStaffUserRequest;
use App\Http\Resources\StaffUserCollection;
use App\Http\Resources\StaffUserResource;
use App\Models\User;
use App\Support\Otp\OtpService;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class StaffUserProfileWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly SyncStaffUser $syncStaffUser,
        private readonly OtpService $otpService,
    ) {}

    public function index(Request $request): StaffUserCollection|JsonResponse
    {
        $actor = $request->user();
        $this->authorize('viewAny', User::class);

        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $query = User::query()->with(['agency', 'hrEmployee.supervisor', 'roles.permissions', 'permissions'])->latest();

        // Non-platform-admins only ever see staff in their current agency.
        // Resolve that visibility once and reuse it for both the page and the
        // status counts so the two never diverge.
        $scopeIds = null;
        if (! $actor->hasRole('platform-admin')) {
            $agencyId = $actor->currentAgencyId();

            if ($agencyId === null) {
                return $this->respondForbidden();
            }

            $scopeIds = $this->staffAgencyScope->currentAgencyStaffIdList($agencyId);
            $query->whereKey($scopeIds);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('name', 'ilike', '%'.$term.'%')
                    ->orWhere('phone_number', 'ilike', '%'.$term.'%')
                    ->orWhere('email', 'ilike', '%'.$term.'%')
                    ->orWhere('matricule', 'ilike', '%'.$term.'%')
                    ->orWhere('job_title', 'ilike', '%'.$term.'%')
                    ->orWhere('agency_code', 'ilike', '%'.$term.'%')
                    ->orWhere('agency_name', 'ilike', '%'.$term.'%');
            });
        }

        $statusFilter = $this->resolveStatusFilter($request);
        if ($statusFilter !== null) {
            $query->where('status', $statusFilter);
        }

        return (new StaffUserCollection($query->paginate($perPage)))
            ->withStatusCounts($this->statusCounts($scopeIds));
    }

    /**
     * Read and validate the optional staff status filter from `filter[status]`
     * or the top-level `status` query parameter. Throws a 422 for unsupported
     * statuses.
     */
    private function resolveStatusFilter(Request $request): ?string
    {
        $status = $request->query('status');
        $filter = $request->query('filter');
        if ($status === null && is_array($filter) && array_key_exists('status', $filter)) {
            $status = $filter['status'];
        }

        if (! is_string($status) || $status === '') {
            return null;
        }

        Validator::make(['status' => $status], [
            'status' => [Rule::in([
                User::STATUS_PENDING_VERIFICATION,
                User::STATUS_ACTIVE,
                User::STATUS_SUSPENDED,
                User::STATUS_DEACTIVATED,
            ])],
        ])->validate();

        return $status;
    }

    /**
     * Status counts for the actor-visible staff population, independent of any
     * status filter applied to the page.
     *
     * @param  array<int, int>|null  $scopeIds  Null = institution-wide (platform admin).
     * @return array<string, int>
     */
    private function statusCounts(?array $scopeIds): array
    {
        $counts = [
            'total' => 0,
            User::STATUS_PENDING_VERIFICATION => 0,
            User::STATUS_ACTIVE => 0,
            User::STATUS_SUSPENDED => 0,
            User::STATUS_DEACTIVATED => 0,
        ];

        if ($scopeIds === []) {
            return $counts;
        }

        $query = DB::table('users');
        if ($scopeIds !== null) {
            $query->whereIn('id', $scopeIds);
        }

        $rows = $query
            ->select('status')
            ->selectRaw('COUNT(*) AS row_count')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = is_string($row->status) ? $row->status : '';
            $count = is_numeric($row->row_count) ? (int) $row->row_count : 0;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
            $counts['total'] += $count;
        }

        return $counts;
    }

    public function store(CreateStaffUserRequest $request): JsonResponse
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
            'gender' => $request->filled('gender') ? $request->string('gender')->toString() : null,
            'birth_date' => $request->filled('birth_date') ? $request->date('birth_date')?->toDateString() : null,
            'birth_place' => $request->filled('birth_place') ? $request->string('birth_place')->toString() : null,
            'service_name' => $request->filled('service_name') ? $request->string('service_name')->toString() : null,
            'supervisor_id' => $this->supervisorId($request->input('supervisor_public_id')),
            'portfolio_code' => $request->filled('portfolio_code') ? $request->string('portfolio_code')->toString() : null,
            'agency_id' => $agencyAttributes['agency_id'],
            'agency_code' => $agencyAttributes['agency_code'],
            'agency_name' => $agencyAttributes['agency_name'],
        ]);

        $this->otpService->issueActivationChallenge($user, $request);
        $this->securityAudit->record('staff.created', actor: $actor, subject: $user, request: $request);

        return $this->respondCreated(
            StaffUserResource::make($user->loadMissing(['agency', 'hrEmployee.supervisor', 'roles.permissions', 'permissions'])),
            'Staff user created successfully'
        );
    }

    public function show(Request $request, User $staffUser): JsonResponse
    {
        $this->authorize('view', $staffUser);

        return $this->respondSuccess(
            StaffUserResource::make($staffUser->loadMissing(['agency', 'hrEmployee.supervisor', 'roles.permissions', 'permissions']))
        );
    }

    public function update(UpdateStaffUserRequest $request, User $staffUser): JsonResponse
    {
        $this->authorize('update', $staffUser);

        $attributes = $request->safe()->only([
            'name',
            'phone_number',
            'email',
            'matricule',
            'job_title',
            'gender',
            'birth_date',
            'birth_place',
            'service_name',
            'portfolio_code',
            'agency_code',
        ]);

        if ($request->has('supervisor_public_id')) {
            $attributes['supervisor_id'] = $this->supervisorId($request->input('supervisor_public_id'));
        }

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
            StaffUserResource::make($staffUser->refresh()->loadMissing(['agency', 'hrEmployee.supervisor', 'roles.permissions', 'permissions'])),
            'Staff user updated successfully'
        );
    }

    private function revokeAllTokens(User $user): void
    {
        foreach ($user->tokens()->get() as $token) {
            $token->delete();
        }
    }

    private function supervisorId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $id = User::query()->where('public_id', $publicId)->value('id');

        return is_int($id) ? $id : null;
    }
}
