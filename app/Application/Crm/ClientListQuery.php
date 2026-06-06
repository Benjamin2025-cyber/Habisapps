<?php

declare(strict_types=1);

namespace App\Application\Crm;

use App\Http\Controllers\BaseController;
use App\Models\Agency;
use App\Models\Client;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Shared scoped client list query used by GET /clients and GET /clients/stats.
 */
final class ClientListQuery extends BaseController
{
    /** @var list<string> */
    public const array ALLOWED_FILTER_KEYS = [
        'status',
        'kyc_status',
    ];

    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    /**
     * @return array{query: Builder<Client>, error: JsonResponse|null}
     */
    public function build(User $actor, Request $request): array
    {
        $filterError = $this->validateFilters($request);
        if ($filterError instanceof JsonResponse) {
            return ['query' => Client::query()->whereKey(0), 'error' => $filterError];
        }

        $query = Client::query()
            ->with(['agency', 'profilePhotoDocument', 'prospector', 'collectionAgent', 'sector', 'subSector'])
            ->latest();

        $scopeError = $this->applyActorScope($query, $actor, $request);
        if ($scopeError instanceof JsonResponse) {
            return ['query' => Client::query()->whereKey(0), 'error' => $scopeError];
        }

        $this->applyFilters($query, $request, $actor);

        return ['query' => $query, 'error' => null];
    }

    /**
     * @param  Builder<Client>  $query
     */
    public function applyActorScope(Builder $query, User $actor, Request $request): ?JsonResponse
    {
        if ($this->shouldUseInstitutionScope($actor, $request)) {
            $agencyPublicId = $request->query('agency_public_id');
            if (is_string($agencyPublicId) && $agencyPublicId !== '') {
                $agencyId = Agency::query()->where('public_id', $agencyPublicId)->value('id');
                $query->where('agency_id', is_int($agencyId) ? $agencyId : 0);
            }

            return null;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden();
        }

        $query->where('agency_id', $agencyId);

        return null;
    }

    /**
     * @param  Builder<Client>  $query
     */
    private function applyFilters(Builder $query, Request $request, User $actor): void
    {
        $status = $this->filterString($request, 'status');
        if ($status !== null) {
            $query->where('status', $status);
        }

        $kycStatus = $this->filterString($request, 'kyc_status');
        if ($kycStatus !== null) {
            $query->where('kyc_status', $kycStatus);
        }

        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return;
        }

        $term = trim($search);
        $query->where(function (Builder $builder) use ($actor, $term): void {
            $builder
                ->where('client_reference', 'ilike', '%'.$term.'%')
                ->orWhere('first_name', 'ilike', '%'.$term.'%')
                ->orWhere('last_name', 'ilike', '%'.$term.'%');

            if ($actor->hasPermissionTo('crm.pii.view')) {
                $builder
                    ->orWhere('phone_number', 'ilike', '%'.$term.'%')
                    ->orWhere('email', 'ilike', '%'.$term.'%');
            }
        });
    }

    private function validateFilters(Request $request): ?JsonResponse
    {
        $filter = $request->query('filter');
        if (is_array($filter)) {
            $unknown = array_diff(array_keys($filter), self::ALLOWED_FILTER_KEYS);
            if ($unknown !== []) {
                return $this->respondUnprocessable(
                    message: 'Unsupported filter parameters.',
                    errors: ['filter' => ['The following filter keys are not supported: '.implode(', ', $unknown)]],
                );
            }
        }

        $status = $this->filterString($request, 'status');
        if ($status !== null) {
            Validator::make(['status' => $status], [
                'status' => [Rule::in([
                    Client::STATUS_ACTIVE,
                    Client::STATUS_INACTIVE,
                    Client::STATUS_SUSPENDED,
                    Client::STATUS_ARCHIVED,
                ])],
            ])->validate();
        }

        $kycStatus = $this->filterString($request, 'kyc_status');
        if ($kycStatus !== null) {
            Validator::make(['kyc_status' => $kycStatus], [
                'kyc_status' => [Rule::in([
                    Client::KYC_STATUS_DRAFT,
                    Client::KYC_STATUS_PENDING_REVIEW,
                    Client::KYC_STATUS_VERIFIED,
                    Client::KYC_STATUS_REJECTED,
                    Client::KYC_STATUS_SUSPENDED,
                    Client::KYC_STATUS_ARCHIVED,
                ])],
            ])->validate();
        }

        return null;
    }

    private function filterString(Request $request, string $key): ?string
    {
        $direct = $request->query($key);
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $filter = $request->query('filter');
        if (is_array($filter) && isset($filter[$key]) && is_string($filter[$key]) && $filter[$key] !== '') {
            return $filter[$key];
        }

        return null;
    }

    private function shouldUseInstitutionScope(User $actor, Request $request): bool
    {
        return $request->query('scope') === 'all' && (
            $actor->hasRole('platform-admin')
            || $actor->hasPermissionTo('crm.scope.institution.read')
            || $actor->hasPermissionTo('crm.scope.institution.review')
            || $actor->hasPermissionTo('crm.scope.institution.manage')
        );
    }
}
