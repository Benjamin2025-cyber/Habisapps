<?php

declare(strict_types=1);

namespace App\Application\JournalEntries;

use App\Http\Controllers\BaseController;
use App\Models\Agency;
use App\Models\JournalEntry;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Shared scoped journal-entry list query used by GET /journal-entries and stats.
 */
final class JournalEntryListQuery extends BaseController
{
    /** @var list<string> */
    public const array ALLOWED_FILTER_KEYS = [
        'status',
    ];

    /** @var list<string> */
    public const array STATUS_KEYS = [
        JournalEntry::STATUS_DRAFT,
        JournalEntry::STATUS_SUBMITTED,
        JournalEntry::STATUS_APPROVED,
        JournalEntry::STATUS_POSTED,
        JournalEntry::STATUS_REJECTED,
        JournalEntry::STATUS_CANCELLED,
        JournalEntry::STATUS_REVERSED,
    ];

    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    /**
     * @return array{query: Builder<JournalEntry>, error: JsonResponse|null}
     */
    public function build(User $actor, Request $request): array
    {
        $filterError = $this->validateFilters($request);
        if ($filterError instanceof JsonResponse) {
            return ['query' => JournalEntry::query()->whereKey(0), 'error' => $filterError];
        }

        $query = JournalEntry::query()
            ->with(['agency', 'accountingDay', 'lines', 'reversalOf', 'submittedBy', 'reviewedBy'])
            ->latest();

        $scopeError = $this->applyActorScope($query, $actor, $request);
        if ($scopeError instanceof JsonResponse) {
            return ['query' => JournalEntry::query()->whereKey(0), 'error' => $scopeError];
        }

        $status = $this->statusFilterValue($request);
        if ($status !== null) {
            $query->where('status', $status);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('reference', 'ilike', '%'.$term.'%')
                    ->orWhere('description', 'ilike', '%'.$term.'%')
                    ->orWhere('source_module', 'ilike', '%'.$term.'%')
                    ->orWhere('source_type', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%');
            });
        }

        return ['query' => $query, 'error' => null];
    }

    /**
     * @param  Builder<JournalEntry>  $query
     */
    public function applyActorScope(Builder $query, User $actor, Request $request): ?JsonResponse
    {
        if ($actor->hasRole('platform-admin') || $actor->hasPermissionTo('accounting.audit.view')) {
            $agencyPublicId = $request->query('agency_public_id');
            if (is_string($agencyPublicId) && $agencyPublicId !== '') {
                $agencyId = Agency::query()->where('public_id', $agencyPublicId)->value('id');
                $query->where('agency_id', is_int($agencyId) ? $agencyId : 0);
            }

            return null;
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden('Journal entry list requires an active agency assignment.');
        }

        $query->where('agency_id', $agencyId);

        return null;
    }

    private function validateFilters(Request $request): ?JsonResponse
    {
        $filter = $request->query('filter');
        if (is_array($filter)) {
            $unknown = array_diff(array_keys($filter), self::ALLOWED_FILTER_KEYS);
            if ($unknown !== []) {
                return $this->respondUnprocessable(
                    message: 'Unsupported filter parameters.',
                    errors: ['filter' => [__('domain.unsupported_filter_keys', ['keys' => implode(', ', $unknown)])]],
                );
            }
        }

        $status = $this->statusFilterValue($request);
        if ($status !== null) {
            Validator::make(['status' => $status], [
                'status' => [Rule::in(self::STATUS_KEYS)],
            ])->validate();
        }

        return null;
    }

    private function statusFilterValue(Request $request): ?string
    {
        $status = $request->query('status');
        $filter = $request->query('filter');
        if (($status === null || $status === '') && is_array($filter) && array_key_exists('status', $filter)) {
            $status = $filter['status'];
        }

        return is_string($status) && $status !== '' ? $status : null;
    }
}
