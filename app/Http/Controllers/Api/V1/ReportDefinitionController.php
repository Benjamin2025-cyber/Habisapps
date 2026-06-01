<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\ReportDefinitionCollection;
use App\Http\Resources\ReportDefinitionResource;
use App\Models\ReportDefinition;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReportDefinitionController extends BaseController
{
    /** @var array<int, string> */
    private const array ALLOWED_FILTERS = ['report_type', 'module', 'status'];

    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request): ReportDefinitionCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('accounting.audit.view')) {
            return $this->respondForbidden();
        }

        $gate = $this->enforceAgencyScope($actor);
        if ($gate instanceof JsonResponse) {
            return $gate;
        }

        $query = ReportDefinition::query()->latest()->latest('id');

        $canIncludeInactive = $request->query('include_inactive') === 'true'
            && $actor->hasRole('platform-admin');

        if (! $canIncludeInactive) {
            $query->where('status', ReportDefinition::STATUS_ACTIVE);

            // Surface only the latest active version per code so the catalog never
            // returns stale/duplicate report-definition versions as the active
            // default (FBI2-028). Definition versions are immutable and a new
            // version stays active alongside its predecessors, so the read model
            // must collapse each code to its highest active version.
            $query->whereKey($this->latestActiveVersionIds());
        }

        $filterError = $this->applyFilters($query, $request);
        if ($filterError instanceof JsonResponse) {
            return $filterError;
        }

        $this->applySearch($query, $request);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new ReportDefinitionCollection($query->paginate($perPage));
    }

    public function show(Request $request, ReportDefinition $reportDefinition): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('accounting.audit.view')) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(ReportDefinitionResource::make($reportDefinition));
    }

    private function enforceAgencyScope(User $actor): ?JsonResponse
    {
        if ($actor->hasRole('platform-admin')) {
            return null;
        }

        if ($this->hasInstitutionReadScope($actor)) {
            return null;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden(
                'A current agency assignment is required to list report definitions.'
            );
        }

        return null;
    }

    private function hasInstitutionReadScope(User $actor): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->hasPermissionTo('crm.scope.institution.read')
            || $actor->hasPermissionTo('crm.scope.institution.review')
            || $actor->hasPermissionTo('crm.scope.institution.manage');
    }

    /**
     * IDs of the highest active version for each report-definition code.
     *
     * @return array<int, int>
     */
    private function latestActiveVersionIds(): array
    {
        $ids = [];
        $rows = DB::table('report_definitions as rd')
            ->where('rd.status', ReportDefinition::STATUS_ACTIVE)
            ->whereRaw(
                'rd.version = (select max(latest.version) from report_definitions as latest'
                .' where latest.code = rd.code and latest.status = ?)',
                [ReportDefinition::STATUS_ACTIVE]
            )
            ->pluck('rd.id');

        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFilter(Request $request): array
    {
        $filter = $request->query('filter');

        return is_array($filter) ? $filter : [];
    }

    /**
     * @param  Builder<ReportDefinition>  $query
     */
    private function applyFilters(Builder $query, Request $request): ?JsonResponse
    {
        $filter = $this->extractFilter($request);

        if ($filter === []) {
            return null;
        }

        $unknown = array_diff(array_keys($filter), self::ALLOWED_FILTERS);
        if ($unknown !== []) {
            return $this->respondUnprocessable(
                message: 'Unsupported filter parameters.',
                errors: ['filter' => ['The following filter keys are not supported: '.implode(', ', $unknown)]]
            );
        }

        $reportType = $filter['report_type'] ?? null;
        if (is_string($reportType) && $reportType !== '') {
            $query->where('report_type', $reportType);
        }

        $module = $filter['module'] ?? null;
        if (is_string($module) && $module !== '') {
            $query->where('module', $module);
        }

        $status = $filter['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        return null;
    }

    /**
     * @param  Builder<ReportDefinition>  $query
     */
    private function applySearch(Builder $query, Request $request): void
    {
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return;
        }

        $term = trim($search);
        $query->where(static function (Builder $builder) use ($term): void {
            $builder->where('code', 'ilike', '%'.$term.'%')
                ->orWhere('name', 'ilike', '%'.$term.'%')
                ->orWhere('report_type', 'ilike', '%'.$term.'%');
        });
    }
}
