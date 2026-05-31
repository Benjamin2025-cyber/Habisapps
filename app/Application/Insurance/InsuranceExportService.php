<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class InsuranceExportService
{
    private const SOURCE_QUERY_VERSION = 'insurance_exports_v1';

    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function subscriptions(User $actor, int $agencyId, array $filters): array
    {
        $rows = DB::table('insurance_subscriptions')
            ->join('insurance_products', 'insurance_products.id', '=', 'insurance_subscriptions.insurance_product_id')
            ->leftJoin('insurance_partners', 'insurance_partners.id', '=', 'insurance_products.insurance_partner_id')
            ->join('clients', 'clients.id', '=', 'insurance_subscriptions.client_id')
            ->where('insurance_subscriptions.agency_id', $agencyId)
            ->when($this->resolveProductId($filters['product_public_id'] ?? null), fn ($query, int $productId) => $query->where('insurance_subscriptions.insurance_product_id', $productId))
            ->when($this->resolvePartnerId($filters['partner_public_id'] ?? null), fn ($query, int $partnerId) => $query->where('insurance_products.insurance_partner_id', $partnerId))
            ->when($this->nullableString($filters['period_start'] ?? null), fn ($query, string $start) => $query->whereDate('insurance_subscriptions.starts_on', '>=', $start))
            ->when($this->nullableString($filters['period_end'] ?? null), fn ($query, string $end) => $query->whereDate('insurance_subscriptions.starts_on', '<=', $end))
            ->when($this->nullableString($filters['status'] ?? null), fn ($query, string $status) => $query->where('insurance_subscriptions.status', $status))
            ->select([
                'insurance_subscriptions.public_id',
                'insurance_subscriptions.subscription_number',
                'insurance_products.name as product_name',
                'insurance_products.product_type',
                'clients.public_id as client_public_id',
                'insurance_subscriptions.starts_on',
                'insurance_subscriptions.ends_on',
                'insurance_subscriptions.coverage_amount_minor',
                'insurance_subscriptions.currency',
                'insurance_subscriptions.status',
            ])
            ->orderBy('insurance_subscriptions.created_at')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all();

        $rows = $this->filterRows($rows, $filters);
        $pagination = $this->paginateRows($rows, $filters, 100);

        return $this->exportPayload($actor, 'subscriptions', $agencyId, $filters, $pagination['rows'], $pagination['pagination']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function premiums(User $actor, int $agencyId, array $filters): array
    {
        $rows = DB::table('insurance_premium_assessments')
            ->join('insurance_subscriptions', 'insurance_subscriptions.id', '=', 'insurance_premium_assessments.insurance_subscription_id')
            ->leftJoin('insurance_products', 'insurance_products.id', '=', 'insurance_subscriptions.insurance_product_id')
            ->leftJoin('insurance_premium_payments', 'insurance_premium_payments.insurance_premium_assessment_id', '=', 'insurance_premium_assessments.id')
            ->where('insurance_subscriptions.agency_id', $agencyId)
            ->when($this->resolveProductId($filters['product_public_id'] ?? null), fn ($query, int $productId) => $query->where('insurance_subscriptions.insurance_product_id', $productId))
            ->when($this->resolvePartnerId($filters['partner_public_id'] ?? null), fn ($query, int $partnerId) => $query->where('insurance_products.insurance_partner_id', $partnerId))
            ->when($this->nullableString($filters['period_start'] ?? null), fn ($query, string $start) => $query->whereDate('insurance_premium_assessments.due_on', '>=', $start))
            ->when($this->nullableString($filters['period_end'] ?? null), fn ($query, string $end) => $query->whereDate('insurance_premium_assessments.due_on', '<=', $end))
            ->when($this->nullableString($filters['status'] ?? null), fn ($query, string $status) => $query->where('insurance_premium_assessments.status', $status))
            ->select([
                'insurance_premium_assessments.public_id',
                'insurance_subscriptions.subscription_number',
                'insurance_premium_assessments.premium_amount_minor',
                'insurance_premium_assessments.currency',
                'insurance_premium_assessments.due_on',
                'insurance_premium_assessments.status',
                'insurance_premium_payments.public_id as payment_public_id',
                'insurance_premium_payments.paid_at',
            ])
            ->orderBy('insurance_premium_assessments.due_on')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all();

        $rows = $this->filterRows($rows, $filters);
        $pagination = $this->paginateRows($rows, $filters, 100);

        return $this->exportPayload($actor, 'premiums', $agencyId, $filters, $pagination['rows'], $pagination['pagination']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function claims(User $actor, int $agencyId, array $filters): array
    {
        $rows = DB::table('insurance_claims')
            ->join('insurance_subscriptions', 'insurance_subscriptions.id', '=', 'insurance_claims.insurance_subscription_id')
            ->leftJoin('insurance_products', 'insurance_products.id', '=', 'insurance_subscriptions.insurance_product_id')
            ->where('insurance_claims.agency_id', $agencyId)
            ->when($this->resolveProductId($filters['product_public_id'] ?? null), fn ($query, int $productId) => $query->where('insurance_subscriptions.insurance_product_id', $productId))
            ->when($this->resolvePartnerId($filters['partner_public_id'] ?? null), fn ($query, int $partnerId) => $query->where('insurance_products.insurance_partner_id', $partnerId))
            ->when($this->nullableString($filters['period_start'] ?? null), fn ($query, string $start) => $query->whereDate('insurance_claims.incident_date', '>=', $start))
            ->when($this->nullableString($filters['period_end'] ?? null), fn ($query, string $end) => $query->whereDate('insurance_claims.incident_date', '<=', $end))
            ->when($this->nullableString($filters['status'] ?? null), fn ($query, string $status) => $query->where('insurance_claims.status', $status))
            ->select([
                'insurance_claims.public_id',
                'insurance_claims.claim_number',
                'insurance_claims.claim_type',
                'insurance_claims.incident_date',
                'insurance_claims.status',
                'insurance_claims.claimed_amount_minor',
                'insurance_claims.indemnified_amount_minor',
                'insurance_claims.currency',
                'insurance_claims.settled_at',
            ])
            ->orderBy('insurance_claims.created_at')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all();

        $rows = $this->filterRows($rows, $filters);
        $pagination = $this->paginateRows($rows, $filters, 100);

        return $this->exportPayload($actor, 'claims', $agencyId, $filters, $pagination['rows'], $pagination['pagination']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function commissions(User $actor, int $agencyId, array $filters): array
    {
        $rows = DB::table('insurance_remittance_items')
            ->join('insurance_remittance_batches', 'insurance_remittance_batches.id', '=', 'insurance_remittance_items.insurance_remittance_batch_id')
            ->join('insurance_products', 'insurance_products.id', '=', 'insurance_remittance_items.insurance_product_id')
            ->where('insurance_remittance_batches.agency_id', $agencyId)
            ->where('insurance_remittance_items.split_type', 'commission_income')
            ->when($this->resolveProductId($filters['product_public_id'] ?? null), fn ($query, int $productId) => $query->where('insurance_remittance_items.insurance_product_id', $productId))
            ->when($this->resolvePartnerId($filters['partner_public_id'] ?? null), fn ($query, int $partnerId) => $query->where('insurance_products.insurance_partner_id', $partnerId))
            ->when($this->nullableString($filters['period_start'] ?? null), fn ($query, string $start) => $query->whereDate('insurance_remittance_batches.period_from', '>=', $start))
            ->when($this->nullableString($filters['period_end'] ?? null), fn ($query, string $end) => $query->whereDate('insurance_remittance_batches.period_to', '<=', $end))
            ->select([
                'insurance_products.public_id as product_public_id',
                'insurance_products.name as product_name',
                'insurance_remittance_batches.public_id as remittance_batch_public_id',
                'insurance_remittance_batches.period_from',
                'insurance_remittance_batches.period_to',
                'insurance_remittance_batches.currency',
                'insurance_remittance_items.amount_minor',
            ])
            ->orderBy('insurance_remittance_batches.period_from')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all();

        $rows = $this->filterRows($rows, $filters);
        $pagination = $this->paginateRows($rows, $filters, 100);

        return $this->exportPayload($actor, 'commissions', $agencyId, $filters, $pagination['rows'], $pagination['pagination']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function remittances(User $actor, int $agencyId, array $filters): array
    {
        $rows = DB::table('insurance_remittance_batches')
            ->join('insurance_partners', 'insurance_partners.id', '=', 'insurance_remittance_batches.insurance_partner_id')
            ->where('insurance_remittance_batches.agency_id', $agencyId)
            ->when($this->resolvePartnerId($filters['partner_public_id'] ?? null), fn ($query, int $partnerId) => $query->where('insurance_remittance_batches.insurance_partner_id', $partnerId))
            ->when($this->nullableString($filters['period_start'] ?? null), fn ($query, string $start) => $query->whereDate('insurance_remittance_batches.period_from', '>=', $start))
            ->when($this->nullableString($filters['period_end'] ?? null), fn ($query, string $end) => $query->whereDate('insurance_remittance_batches.period_to', '<=', $end))
            ->when($this->nullableString($filters['status'] ?? null), fn ($query, string $status) => $query->where('insurance_remittance_batches.status', $status))
            ->select([
                'insurance_remittance_batches.public_id',
                'insurance_partners.public_id as partner_public_id',
                'insurance_partners.name as partner_name',
                'insurance_remittance_batches.period_from',
                'insurance_remittance_batches.period_to',
                'insurance_remittance_batches.currency',
                'insurance_remittance_batches.total_minor',
                'insurance_remittance_batches.status',
                'insurance_remittance_batches.approved_at',
            ])
            ->orderBy('insurance_remittance_batches.period_from')
            ->get()
            ->map(fn (object $row) => (array) $row)
            ->all();

        $rows = $this->filterRows($rows, $filters);
        $pagination = $this->paginateRows($rows, $filters, 100);

        return $this->exportPayload($actor, 'remittances', $agencyId, $filters, $pagination['rows'], $pagination['pagination']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function cancellationsRefunds(User $actor, int $agencyId, array $filters): array
    {
        $rows = array_map(
            fn (object $row): array => $this->cancellationRefundRow($row),
            $this->cancellationRefundRows($filters, $agencyId),
        );

        $rows = $this->filterRows($rows, $filters);
        $pagination = $this->paginateRows($rows, $filters, 100);

        return $this->exportPayload($actor, 'cancellations_refunds', $agencyId, $filters, $pagination['rows'], $pagination['pagination']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function cancellationsRefundsReport(array $filters, ?int $scopedAgencyId): array
    {
        $rows = $this->cancellationRefundRows($filters, $scopedAgencyId);
        $mapped = array_map(fn (object $row): array => $this->cancellationRefundRow($row), $rows);
        $mapped = $this->filterRows($mapped, $filters);
        $pagination = $this->paginateRows($mapped, $filters, 100);
        $refundTotal = 0;
        foreach ($mapped as $row) {
            $refundTotal += is_numeric($row['refund_amount_minor'] ?? null) ? (int) $row['refund_amount_minor'] : 0;
        }

        return [
            'items' => $pagination['rows'],
            'totals' => [
                'count' => count($mapped),
                'refund_amount_minor' => $refundTotal,
            ],
            'meta' => ['pagination' => $pagination['pagination']],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, mixed>  $rows
     * @param  array<string, int>  $pagination
     * @return array<string, mixed>
     */
    private function exportPayload(User $actor, string $exportType, int $agencyId, array $filters, array $rows, array $pagination): array
    {
        $checksum = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        $this->recordExport($actor->id, $exportType, $agencyId, $filters, $checksum, count($rows));
        $this->securityAudit->record('insurance.report.exported', actor: $actor, properties: [
            'export_type' => $exportType,
            'agency_id' => $agencyId,
            'record_count' => count($rows),
            'checksum' => $checksum,
            'source_query_version' => self::SOURCE_QUERY_VERSION,
        ]);

        return [
            'export_type' => $exportType,
            'record_count' => count($rows),
            'checksum' => $checksum,
            'source_query_version' => self::SOURCE_QUERY_VERSION,
            'format' => 'json_api_export',
            'generated_at' => now()->toISOString(),
            'rows' => $rows,
            'meta' => ['pagination' => $pagination],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, object>
     */
    private function cancellationRefundRows(array $filters, ?int $scopedAgencyId): array
    {
        $query = DB::table('insurance_cancellations')
            ->join('insurance_subscriptions', 'insurance_subscriptions.id', '=', 'insurance_cancellations.insurance_subscription_id')
            ->leftJoin('insurance_products', 'insurance_products.id', '=', 'insurance_subscriptions.insurance_product_id');

        $this->applyAgencyFilter($query, 'insurance_subscriptions.agency_id', $scopedAgencyId, $this->resolveAgencyId($filters['agency_public_id'] ?? null));
        $this->applyProductFilter($query, 'insurance_subscriptions.insurance_product_id', $this->resolveProductId($filters['product_public_id'] ?? null));
        $this->applyPartnerFilter($query, 'insurance_products.insurance_partner_id', $this->resolvePartnerId($filters['partner_public_id'] ?? null));
        $this->applyDateRangeFilter($query, 'insurance_cancellations.effective_on', $this->nullableString($filters['period_start'] ?? null), $this->nullableString($filters['period_end'] ?? null));
        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $query->where('insurance_cancellations.status', $filters['status']);
        }

        return $query
            ->orderBy('insurance_cancellations.effective_on')
            ->get([
                'insurance_cancellations.public_id',
                'insurance_subscriptions.public_id as subscription_public_id',
                'insurance_products.public_id as product_public_id',
                'insurance_cancellations.effective_on',
                'insurance_cancellations.refund_treatment',
                'insurance_cancellations.refund_amount_minor',
                'insurance_cancellations.status',
                'insurance_cancellations.approved_at',
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function cancellationRefundRow(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'subscription_public_id' => $this->rowString($row, 'subscription_public_id'),
            'product_public_id' => $this->rowNullableString($row, 'product_public_id'),
            'effective_on' => $this->rowString($row, 'effective_on'),
            'refund_treatment' => $this->rowString($row, 'refund_treatment'),
            'refund_amount_minor' => $this->rowNullableInt($row, 'refund_amount_minor'),
            'status' => $this->rowString($row, 'status'),
            'approved_at' => $this->rowNullableString($row, 'approved_at'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function filterRows(array $rows, array $filters): array
    {
        $search = $this->searchTerm($filters);
        if ($search === null) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => $this->rowMatchesSearch($row, $search)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return array{rows: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    private function paginateRows(array $rows, array $filters, int $defaultPerPage): array
    {
        $pageValue = $filters['page'] ?? 1;
        $perPageValue = $filters['per_page'] ?? $defaultPerPage;
        $page = max(1, is_numeric($pageValue) ? (int) $pageValue : 1);
        $perPage = min(max(is_numeric($perPageValue) ? (int) $perPageValue : $defaultPerPage, 1), 100);
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($rows, $offset, $perPage),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function searchTerm(array $filters): ?string
    {
        $search = $filters['search'] ?? null;
        if (! is_string($search) || trim($search) === '') {
            return null;
        }

        return mb_strtolower(trim($search));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowMatchesSearch(array $row, string $search): bool
    {
        $haystack = mb_strtolower(json_encode($row, JSON_THROW_ON_ERROR));

        return str_contains($haystack, $search);
    }

    private function recordExport(int $userId, string $exportType, int $agencyId, mixed $filters, string $checksum, int $count): void
    {
        DB::table('insurance_export_records')->insert([
            'public_id' => (string) Str::ulid(),
            'export_type' => $exportType,
            'agency_id' => $agencyId !== 0 ? $agencyId : null,
            'generated_by_user_id' => $userId,
            'filters' => is_array($filters) ? json_encode($filters, JSON_THROW_ON_ERROR) : null,
            'checksum' => $checksum,
            'source_query_version' => self::SOURCE_QUERY_VERSION,
            'record_count' => $count,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resolveAgencyId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $agency = DB::table('agencies')->where('public_id', $publicId)->first(['id']);

        return is_object($agency) ? $this->rowInt($agency, 'id') : null;
    }

    private function resolveProductId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $product = DB::table('insurance_products')->where('public_id', $publicId)->first(['id']);

        return is_object($product) ? $this->rowInt($product, 'id') : null;
    }

    private function resolvePartnerId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $partner = DB::table('insurance_partners')->where('public_id', $publicId)->first(['id']);

        return is_object($partner) ? $this->rowInt($partner, 'id') : null;
    }

    private function applyAgencyFilter(Builder $query, string $column, ?int $scopedAgencyId, ?int $requestedAgencyId): void
    {
        if ($scopedAgencyId !== null) {
            $query->where($column, $scopedAgencyId);

            return;
        }

        if ($requestedAgencyId !== null) {
            $query->where($column, $requestedAgencyId);
        }
    }

    private function applyProductFilter(Builder $query, string $column, ?int $productId): void
    {
        if ($productId !== null) {
            $query->where($column, $productId);
        }
    }

    private function applyPartnerFilter(Builder $query, string $column, ?int $partnerId): void
    {
        if ($partnerId !== null) {
            $query->where($column, $partnerId);
        }
    }

    private function applyDateRangeFilter(Builder $query, string $column, ?string $start, ?string $end): void
    {
        if ($start !== null && $start !== '') {
            $query->whereDate($column, '>=', $start);
        }
        if ($end !== null && $end !== '') {
            $query->whereDate($column, '<=', $end);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function rowString(object $row, string $key): string
    {
        $value = $this->rowValue($row, $key);

        return is_string($value) ? $value : (string) ($value ?? '');
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = $this->rowValue($row, $key);
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = $this->rowValue($row, $key);

        return is_int($value) ? $value : (int) ($value ?? 0);
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = $this->rowValue($row, $key);
        if ($value === null) {
            return null;
        }

        return is_int($value) ? $value : (int) $value;
    }

    private function rowValue(object $row, string $key): string|int|float|bool|null
    {
        $value = get_object_vars($row)[$key] ?? null;
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }
}
