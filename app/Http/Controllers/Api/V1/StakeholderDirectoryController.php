<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\ClientGuarantorCollection;
use App\Http\Resources\ClientProxyCollection;
use App\Models\Agency;
use App\Models\ClientGuarantor;
use App\Models\ClientProxy;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transversal (institution-wide) read-only directories for guarantors and
 * proxies. These are referential views layered over client-owned records;
 * creation/mutation remains nested under `clients/{client}` so ownership and
 * validation are preserved (see FBI-012).
 */
final class StakeholderDirectoryController extends BaseController
{
    public function __construct(private readonly StaffAgencyScope $staffAgencyScope) {}

    /**
     * List guarantors across the institution or current agency.
     *
     * @authenticated
     */
    public function guarantors(Request $request): ClientGuarantorCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', ClientGuarantor::class)) {
            return $this->respondForbidden();
        }

        $query = ClientGuarantor::query()->with(['client', 'guarantorClient', 'document'])->latest();

        $scoped = $this->applyScope($query, $request, $actor);
        if ($scoped instanceof JsonResponse) {
            return $scoped;
        }

        $this->applyCommonFilters($query, $request);

        $search = $this->searchTerm($request);
        if ($search !== null) {
            $query->where(function (Builder $builder) use ($search, $actor): void {
                $builder->where('guarantor_full_name', 'ilike', '%'.$search.'%');
                if ($this->canViewPii($actor, 'crm.guarantors.pii.view')) {
                    $builder->orWhere('guarantor_phone_number', 'ilike', '%'.$search.'%');
                }
            });
        }

        return new ClientGuarantorCollection($query->paginate($this->perPage($request)));
    }

    /**
     * List proxies/mandates across the institution or current agency.
     *
     * @authenticated
     */
    public function proxies(Request $request): ClientProxyCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', ClientProxy::class)) {
            return $this->respondForbidden();
        }

        $query = ClientProxy::query()->with(['client', 'document', 'customerAccount'])->latest();

        $scoped = $this->applyScope($query, $request, $actor);
        if ($scoped instanceof JsonResponse) {
            return $scoped;
        }

        $this->applyCommonFilters($query, $request);

        $search = $this->searchTerm($request);
        if ($search !== null) {
            $query->where(function (Builder $builder) use ($search, $actor): void {
                $builder->where('proxy_full_name', 'ilike', '%'.$search.'%');
                if ($this->canViewPii($actor, 'crm.pii.view')) {
                    $builder
                        ->orWhere('proxy_phone_number', 'ilike', '%'.$search.'%')
                        ->orWhere('proxy_email', 'ilike', '%'.$search.'%');
                }
            });
        }

        return new ClientProxyCollection($query->paginate($this->perPage($request)));
    }

    /**
     * Constrain the query to the institution (scope=all + institution read
     * permission) or to the actor's current agency. Returns a JsonResponse
     * when the actor has no usable scope.
     *
     * @param  Builder<ClientGuarantor>|Builder<ClientProxy>  $query
     */
    private function applyScope(Builder $query, Request $request, User $actor): ?JsonResponse
    {
        if ($request->query('scope') === 'all' && $this->hasInstitutionReadScope($actor)) {
            return null;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden('A directory query requires an active agency assignment or institution scope.');
        }

        $query->where('agency_id', $agencyId);

        return null;
    }

    /**
     * @param  Builder<ClientGuarantor>|Builder<ClientProxy>  $query
     */
    private function applyCommonFilters(Builder $query, Request $request): void
    {
        $filter = $request->query('filter');
        $filter = is_array($filter) ? $filter : [];

        $status = $filter['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $verificationStatus = $filter['verification_status'] ?? null;
        if (is_string($verificationStatus) && $verificationStatus !== '') {
            $query->where('verification_status', $verificationStatus);
        }

        $agencyPublicId = $filter['agency_public_id'] ?? null;
        if (is_string($agencyPublicId) && $agencyPublicId !== '') {
            $agencyId = Agency::query()->where('public_id', $agencyPublicId)->value('id');
            // An unknown agency yields an impossible predicate (empty page)
            // instead of silently ignoring the filter.
            $query->where('agency_id', is_int($agencyId) ? $agencyId : 0);
        }
    }

    private function searchTerm(Request $request): ?string
    {
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return null;
        }

        return trim($search);
    }

    private function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }

    private function canViewPii(User $actor, string $specificPermission): bool
    {
        return $actor->hasPermissionTo($specificPermission) || $actor->hasPermissionTo('crm.pii.view');
    }

    private function hasInstitutionReadScope(User $actor): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->hasPermissionTo('crm.scope.institution.read')
            || $actor->hasPermissionTo('crm.scope.institution.review')
            || $actor->hasPermissionTo('crm.scope.institution.manage');
    }
}
