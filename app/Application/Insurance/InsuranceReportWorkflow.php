<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

final class InsuranceReportWorkflow extends BaseController
{
    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly InsuranceExportService $insuranceExports,
    ) {}

    public function activeSubscriptions(Request $request): JsonResponse
    {
        return $this->render($request, 'active_subscriptions', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateFilters($request, allowStatus: false);
            $query = DB::table('insurance_subscriptions')
                ->leftJoin('insurance_products', 'insurance_products.id', '=', 'insurance_subscriptions.insurance_product_id')
                ->leftJoin('insurance_partners', 'insurance_partners.id', '=', 'insurance_products.insurance_partner_id')
                ->leftJoin('agencies', 'agencies.id', '=', 'insurance_subscriptions.agency_id')
                ->where('insurance_subscriptions.status', 'active');

            $this->applyCommonFilters($query, 'insurance_subscriptions.agency_id', 'insurance_subscriptions.insurance_product_id', 'insurance_products.insurance_partner_id', $scopedAgencyId, $validated);
            $this->applyDateRangeFilter($query, 'insurance_subscriptions.starts_on', $validated['period_start'], $validated['period_end']);

            $rows = $query->orderBy('insurance_subscriptions.starts_on')->get([
                'insurance_subscriptions.public_id',
                'insurance_subscriptions.starts_on',
                'insurance_subscriptions.ends_on',
                'insurance_subscriptions.coverage_amount_minor',
                'insurance_subscriptions.currency',
                'insurance_products.code as product_code',
                'insurance_products.name as product_name',
                'insurance_partners.code as partner_code',
                'insurance_partners.name as partner_name',
                'agencies.code as agency_code',
            ]);

            $items = [];
            $totalCoverage = 0;
            foreach ($rows as $row) {
                $coverage = $this->rowInt($row, 'coverage_amount_minor');
                $totalCoverage += $coverage;
                $items[] = [
                    'public_id' => $this->rowString($row, 'public_id'),
                    'starts_on' => $this->rowNullableString($row, 'starts_on'),
                    'ends_on' => $this->rowNullableString($row, 'ends_on'),
                    'coverage_amount_minor' => $coverage,
                    'currency' => $this->rowString($row, 'currency'),
                    'product_code' => $this->rowNullableString($row, 'product_code'),
                    'product_name' => $this->rowNullableString($row, 'product_name'),
                    'partner_code' => $this->rowNullableString($row, 'partner_code'),
                    'partner_name' => $this->rowNullableString($row, 'partner_name'),
                    'agency_code' => $this->rowNullableString($row, 'agency_code'),
                ];
            }

            return ['items' => $items, 'totals' => ['count' => count($items), 'coverage_amount_minor' => $totalCoverage]];
        });
    }

    public function premiums(Request $request): JsonResponse
    {
        return $this->render($request, 'premiums', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateFilters($request, allowStatus: true);
            $query = DB::table('insurance_premium_assessments as ipa')
                ->join('insurance_subscriptions as isub', 'isub.id', '=', 'ipa.insurance_subscription_id')
                ->leftJoin('insurance_products as ip', 'ip.id', '=', 'isub.insurance_product_id');

            $this->applyCommonFilters($query, 'isub.agency_id', 'isub.insurance_product_id', 'ip.insurance_partner_id', $scopedAgencyId, $validated);
            $this->applyDateRangeFilter($query, 'ipa.due_on', $validated['period_start'], $validated['period_end']);
            $this->applyStatusFilter($query, 'ipa.status', $validated['status']);

            return $this->statusAmountBuckets($query, 'ipa.status', 'ipa.premium_amount_minor');
        });
    }

    public function unpaidPremiums(Request $request): JsonResponse
    {
        return $this->render($request, 'unpaid_premiums', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateFilters($request, allowStatus: false);
            $query = DB::table('insurance_premium_assessments as ipa')
                ->join('insurance_subscriptions as isub', 'isub.id', '=', 'ipa.insurance_subscription_id')
                ->leftJoin('insurance_products as ip', 'ip.id', '=', 'isub.insurance_product_id')
                ->where('ipa.status', 'assessed');

            $this->applyCommonFilters($query, 'isub.agency_id', 'isub.insurance_product_id', 'ip.insurance_partner_id', $scopedAgencyId, $validated);
            $this->applyDateRangeFilter($query, 'ipa.due_on', $validated['period_start'], $validated['period_end']);

            $rows = $query->orderBy('ipa.due_on')->get([
                'ipa.public_id',
                'ipa.premium_amount_minor',
                'ipa.currency',
                'ipa.due_on',
                'ipa.status',
                'isub.public_id as subscription_public_id',
                'ip.code as product_code',
            ]);

            $items = [];
            $totalAmount = 0;
            foreach ($rows as $row) {
                $amount = $this->rowInt($row, 'premium_amount_minor');
                $totalAmount += $amount;
                $items[] = [
                    'public_id' => $this->rowString($row, 'public_id'),
                    'subscription_public_id' => $this->rowString($row, 'subscription_public_id'),
                    'premium_amount_minor' => $amount,
                    'currency' => $this->rowString($row, 'currency'),
                    'due_on' => $this->rowNullableString($row, 'due_on'),
                    'status' => $this->rowString($row, 'status'),
                    'product_code' => $this->rowNullableString($row, 'product_code'),
                ];
            }

            return ['items' => $items, 'totals' => ['count' => count($items), 'amount_minor' => $totalAmount]];
        });
    }

    public function claims(Request $request): JsonResponse
    {
        return $this->render($request, 'claims_by_status', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateFilters($request, allowStatus: true);
            $query = DB::table('insurance_claims as ic')
                ->join('insurance_subscriptions as isub', 'isub.id', '=', 'ic.insurance_subscription_id')
                ->leftJoin('insurance_products as ip', 'ip.id', '=', 'isub.insurance_product_id');

            $this->applyCommonFilters($query, 'ic.agency_id', 'isub.insurance_product_id', 'ip.insurance_partner_id', $scopedAgencyId, $validated);
            $this->applyDateRangeFilter($query, 'ic.incident_date', $validated['period_start'], $validated['period_end']);
            $this->applyStatusFilter($query, 'ic.status', $validated['status']);

            $rows = $query
                ->selectRaw('ic.status as status, COUNT(*) as count, COALESCE(SUM(ic.claimed_amount_minor), 0) as claimed_minor, COALESCE(SUM(ic.indemnified_amount_minor), 0) as indemnified_minor')
                ->groupBy('ic.status')
                ->get();

            $buckets = [];
            $totalCount = 0;
            $totalClaimed = 0;
            $totalIndemnified = 0;
            foreach ($rows as $row) {
                $count = $this->rowInt($row, 'count');
                $claimed = $this->rowInt($row, 'claimed_minor');
                $indemnified = $this->rowInt($row, 'indemnified_minor');
                $buckets[$this->rowString($row, 'status')] = [
                    'count' => $count,
                    'claimed_amount_minor' => $claimed,
                    'indemnified_amount_minor' => $indemnified,
                ];
                $totalCount += $count;
                $totalClaimed += $claimed;
                $totalIndemnified += $indemnified;
            }

            return [
                'by_status' => $buckets,
                'totals' => ['count' => $totalCount, 'claimed_amount_minor' => $totalClaimed, 'indemnified_amount_minor' => $totalIndemnified],
            ];
        });
    }

    public function expiringCoverage(Request $request): JsonResponse
    {
        return $this->render($request, 'expiring_coverage', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateFilters($request, allowStatus: false);
            $start = $validated['period_start'] ?? now()->toDateString();
            $end = $validated['period_end'] ?? now()->addDays(30)->toDateString();
            $query = DB::table('insurance_subscriptions as isub')
                ->leftJoin('insurance_products as ip', 'ip.id', '=', 'isub.insurance_product_id')
                ->where('isub.status', 'active')
                ->whereNotNull('isub.ends_on')
                ->whereBetween('isub.ends_on', [$start, $end]);

            $this->applyCommonFilters($query, 'isub.agency_id', 'isub.insurance_product_id', 'ip.insurance_partner_id', $scopedAgencyId, $validated);

            $items = $query->orderBy('isub.ends_on')->get([
                'isub.public_id',
                'isub.ends_on',
                'isub.currency',
                'isub.coverage_amount_minor',
                'ip.code as product_code',
            ])->map(fn (object $row): array => [
                'public_id' => $this->rowString($row, 'public_id'),
                'ends_on' => $this->rowNullableString($row, 'ends_on'),
                'currency' => $this->rowString($row, 'currency'),
                'coverage_amount_minor' => $this->rowInt($row, 'coverage_amount_minor'),
                'product_code' => $this->rowNullableString($row, 'product_code'),
            ])->all();

            return ['window' => ['from' => $start, 'to' => $end], 'items' => $items, 'totals' => ['count' => count($items)]];
        });
    }

    public function commissions(Request $request): JsonResponse
    {
        $actor = $this->reportActor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $agencyId = $this->scopedAgencyIdForReport($actor, $request->query('agency_public_id'));
        $rows = DB::table('insurance_remittance_items')
            ->join('insurance_remittance_batches', 'insurance_remittance_batches.id', '=', 'insurance_remittance_items.insurance_remittance_batch_id')
            ->join('insurance_products', 'insurance_products.id', '=', 'insurance_remittance_items.insurance_product_id')
            ->where('insurance_remittance_batches.agency_id', $agencyId)
            ->where('insurance_remittance_items.split_type', 'commission_income')
            ->select([
                'insurance_products.name as product_name',
                'insurance_remittance_batches.period_from',
                'insurance_remittance_batches.period_to',
                'insurance_remittance_batches.currency',
                DB::raw('SUM(insurance_remittance_items.amount_minor) as total_commission_minor'),
            ])
            ->groupBy('insurance_products.name', 'insurance_remittance_batches.period_from', 'insurance_remittance_batches.period_to', 'insurance_remittance_batches.currency')
            ->get();

        return $this->respondSuccess(['items' => $rows->map(fn (object $row): array => [
            'product_name' => $this->rowString($row, 'product_name'),
            'period_from' => $this->rowString($row, 'period_from'),
            'period_to' => $this->rowString($row, 'period_to'),
            'currency' => $this->rowString($row, 'currency'),
            'total_commission_minor' => $this->rowInt($row, 'total_commission_minor'),
        ])->all()], 'Commission report');
    }

    public function remittances(Request $request): JsonResponse
    {
        $actor = $this->reportActor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $agencyId = $this->scopedAgencyIdForReport($actor, $request->query('agency_public_id'));
        $batches = DB::table('insurance_remittance_batches')
            ->join('insurance_partners', 'insurance_partners.id', '=', 'insurance_remittance_batches.insurance_partner_id')
            ->where('insurance_remittance_batches.agency_id', $agencyId)
            ->select([
                'insurance_remittance_batches.public_id',
                'insurance_partners.name as partner_name',
                'insurance_remittance_batches.period_from',
                'insurance_remittance_batches.period_to',
                'insurance_remittance_batches.currency',
                'insurance_remittance_batches.total_minor',
                'insurance_remittance_batches.status',
                'insurance_remittance_batches.approved_at',
            ])
            ->orderByDesc('insurance_remittance_batches.created_at')
            ->get();

        return $this->respondSuccess(['items' => $batches->toArray()], 'Remittances report');
    }

    public function lossRatio(Request $request): JsonResponse
    {
        return $this->render($request, 'loss_ratio', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateFilters($request, allowStatus: false);
            $premiumQuery = DB::table('insurance_premium_payments')
                ->join('insurance_premium_assessments', 'insurance_premium_assessments.id', '=', 'insurance_premium_payments.insurance_premium_assessment_id')
                ->join('insurance_subscriptions', 'insurance_subscriptions.id', '=', 'insurance_premium_assessments.insurance_subscription_id')
                ->where('insurance_premium_payments.status', 'posted')
                ->whereNull('insurance_premium_payments.reversed_at');
            $claimQuery = DB::table('insurance_claims')
                ->join('insurance_subscriptions', 'insurance_subscriptions.id', '=', 'insurance_claims.insurance_subscription_id')
                ->where('insurance_claims.status', 'settled')
                ->whereNull('insurance_claims.reversal_at');

            $this->applyAgencyFilter($premiumQuery, 'insurance_subscriptions.agency_id', $scopedAgencyId, $validated['agency_id']);
            $this->applyAgencyFilter($claimQuery, 'insurance_claims.agency_id', $scopedAgencyId, $validated['agency_id']);
            $this->applyProductFilter($premiumQuery, 'insurance_subscriptions.insurance_product_id', $validated['product_id']);
            $this->applyProductFilter($claimQuery, 'insurance_subscriptions.insurance_product_id', $validated['product_id']);
            $this->applyDateRangeFilter($premiumQuery, 'insurance_premium_payments.paid_at', $validated['period_start'], $validated['period_end']);
            $this->applyDateRangeFilter($claimQuery, 'insurance_claims.settled_at', $validated['period_start'], $validated['period_end']);

            $premiumMinor = (int) $premiumQuery->sum('insurance_premium_payments.amount_minor');
            $claimMinor = (int) $claimQuery->sum('insurance_claims.indemnified_amount_minor');

            return [
                'earned_premium_minor' => $premiumMinor,
                'settled_claims_minor' => $claimMinor,
                'loss_ratio_basis_points' => $premiumMinor > 0 ? (int) round(($claimMinor / $premiumMinor) * 10000) : null,
            ];
        });
    }

    public function cancellationsRefunds(Request $request): JsonResponse
    {
        return $this->render($request, 'cancellations_refunds', function (Request $request, ?int $scopedAgencyId): array {
            $this->validateFilters($request, allowStatus: true);

            return $this->insuranceExports->cancellationsRefundsReport($request->all(), $scopedAgencyId);
        });
    }

    /**
     * @param  callable(Request, ?int): array<string, mixed>  $compute
     */
    private function render(Request $request, string $reportKey, callable $compute): JsonResponse
    {
        $actor = $this->reportActor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $scopedAgencyId = null;
        if (! $actor->hasRole('platform-admin')) {
            $scopedAgencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($scopedAgencyId === null) {
                return $this->respondForbidden('No active agency assignment for this user.');
            }
        }

        try {
            $payload = $compute($request, $scopedAgencyId);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_report' => [$exception->getMessage()]]);
        }

        return $this->respondSuccess($payload, 'Insurance report generated successfully', ['report' => $reportKey]);
    }

    private function reportActor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasPermissionTo('insurance.reports.view') ? $actor : null;
    }

    /**
     * @return array{agency_id:?int, product_id:?int, partner_id:?int, period_start:?string, period_end:?string, status:?string}
     */
    private function validateFilters(Request $request, bool $allowStatus): array
    {
        $rules = [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'product_public_id' => ['sometimes', 'nullable', 'string', 'exists:insurance_products,public_id'],
            'partner_public_id' => ['sometimes', 'nullable', 'string', 'exists:insurance_partners,public_id'],
            'period_start' => ['sometimes', 'nullable', 'date'],
            'period_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_start'],
        ];
        if ($allowStatus) {
            $rules['status'] = ['sometimes', 'nullable', 'string', 'max:32'];
        }

        $validated = Validator::make($request->all(), $rules)->validate();

        return [
            'agency_id' => $this->resolvePublicId('agencies', $validated['agency_public_id'] ?? null),
            'product_id' => $this->resolvePublicId('insurance_products', $validated['product_public_id'] ?? null),
            'partner_id' => $this->resolvePublicId('insurance_partners', $validated['partner_public_id'] ?? null),
            'period_start' => is_string($validated['period_start'] ?? null) && $validated['period_start'] !== '' ? $validated['period_start'] : null,
            'period_end' => is_string($validated['period_end'] ?? null) && $validated['period_end'] !== '' ? $validated['period_end'] : null,
            'status' => is_string($validated['status'] ?? null) && $validated['status'] !== '' ? $validated['status'] : null,
        ];
    }

    private function resolvePublicId(string $table, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);

        return is_object($row) && is_numeric(((array) $row)['id'] ?? null) ? (int) ((array) $row)['id'] : null;
    }

    /**
     * @param  array{agency_id:?int, product_id:?int, partner_id:?int, period_start:?string, period_end:?string, status:?string}  $validated
     */
    private function applyCommonFilters(Builder $query, string $agencyColumn, string $productColumn, string $partnerColumn, ?int $scopedAgencyId, array $validated): void
    {
        $this->applyAgencyFilter($query, $agencyColumn, $scopedAgencyId, $validated['agency_id']);
        $this->applyProductFilter($query, $productColumn, $validated['product_id']);
        $this->applyPartnerFilter($query, $partnerColumn, $validated['partner_id']);
    }

    private function applyAgencyFilter(Builder $query, string $column, ?int $scopedAgencyId, ?int $requestedAgencyId): void
    {
        if ($scopedAgencyId !== null) {
            if ($requestedAgencyId !== null && $requestedAgencyId !== $scopedAgencyId) {
                throw new InvalidArgumentException('Agency-scoped users cannot query other agencies.');
            }
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
        if ($start !== null) {
            $query->where($column, '>=', $start);
        }
        if ($end !== null) {
            $query->where($column, '<=', $end);
        }
    }

    private function applyStatusFilter(Builder $query, string $column, ?string $status): void
    {
        if ($status !== null) {
            $query->where($column, $status);
        }
    }

    /**
     * @param  literal-string  $statusColumn
     * @param  literal-string  $amountColumn
     * @return array{by_status: array<string, array{count:int, amount_minor:int}>, totals: array{count:int, amount_minor:int}}
     */
    private function statusAmountBuckets(Builder $query, string $statusColumn, string $amountColumn): array
    {
        $rows = $query
            ->selectRaw($statusColumn.' as status, COUNT(*) as count, COALESCE(SUM('.$amountColumn.'), 0) as amount_minor')
            ->groupBy($statusColumn)
            ->get();

        $buckets = [];
        $totalCount = 0;
        $totalAmount = 0;
        foreach ($rows as $row) {
            $count = $this->rowInt($row, 'count');
            $amount = $this->rowInt($row, 'amount_minor');
            $buckets[$this->rowString($row, 'status')] = ['count' => $count, 'amount_minor' => $amount];
            $totalCount += $count;
            $totalAmount += $amount;
        }

        return ['by_status' => $buckets, 'totals' => ['count' => $totalCount, 'amount_minor' => $totalAmount]];
    }

    private function scopedAgencyIdForReport(User $actor, mixed $agencyPublicId): int
    {
        if ($actor->hasRole('platform-admin') && is_string($agencyPublicId) && $agencyPublicId !== '') {
            return $this->resolvePublicId('agencies', $agencyPublicId) ?? 0;
        }

        if ($actor->hasRole('agency-manager')) {
            return $this->staffAgencyScope->currentAgencyId($actor) ?? 0;
        }

        return is_string($agencyPublicId) && $agencyPublicId !== ''
            ? $this->resolvePublicId('agencies', $agencyPublicId) ?? 0
            : 0;
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }
}
