<?php

declare(strict_types=1);

namespace App\Application\Dashboard;

use App\Application\Crm\ClientListQuery;
use App\Application\Loans\LoanDisbursementReadiness;
use App\Application\Loans\LoanListQuery;
use App\Http\Controllers\BaseController;
use App\Models\Agency;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Self-scoped dashboard summaries for field roles blocked from the operational dashboard.
 */
final class FieldRoleDashboardWorkflow extends BaseController
{
    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly DashboardMetrics $metrics,
        private readonly LoanListQuery $loanListQuery,
        private readonly LoanDisbursementReadiness $disbursementReadiness,
        private readonly ClientListQuery $clientListQuery,
    ) {}

    public function loanOfficer(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondUnauthorized();
        }
        if ($actor->hasRole('platform-admin')) {
            return $this->respondForbidden('Platform administrators should use GET /dashboards/operational.');
        }
        if (! $actor->hasRole('loan-officer')) {
            return $this->respondForbidden();
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden('Loan officer dashboard requires an active agency assignment.');
        }

        $currency = strtoupper((string) ($request->query('currency') ?? 'XAF'));
        $asOfDate = now()->toDateString();
        $loanIds = $this->scopedLoanIdsForCreditAgent($actor, $agencyId, $currency);

        $byStatus = $this->loanStatusCounts($loanIds);
        $reportableIds = $this->metrics->reportableLoanIdsWithin($loanIds);
        $delinquentIds = $this->metrics->delinquentLoanIdsWithin($reportableIds, $asOfDate);
        $portfolioOutstanding = $this->metrics->outstandingForLoanIds($reportableIds, $asOfDate);
        $collections = $this->metrics->postedCollectionMinorForCreditAgent(
            $actor->id,
            $currency,
            now()->startOfMonth()->toDateString(),
            now()->toDateString(),
        );

        return $this->respondSuccess([
            'scope' => 'self',
            'agency_public_id' => DB::table('agencies')->where('id', $agencyId)->value('public_id'),
            'currency' => $currency,
            'active_loan_count' => ($byStatus[Loan::STATUS_ACTIVE] ?? 0) + ($byStatus[Loan::STATUS_DISBURSED] ?? 0) + ($byStatus[Loan::STATUS_RESCHEDULED] ?? 0),
            'application_count' => ($byStatus[Loan::STATUS_APPLICATION] ?? 0) + ($byStatus[Loan::STATUS_IN_REVIEW] ?? 0),
            'delinquent_loan_count' => count($delinquentIds),
            'portfolio_outstanding_minor' => $portfolioOutstanding,
            'collections_mtd_minor' => $collections,
            'by_status' => $byStatus,
        ], 'Loan officer dashboard');
    }

    public function kycOfficer(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondUnauthorized();
        }
        if ($actor->hasRole('platform-admin')) {
            return $this->respondForbidden('Platform administrators should use institution-wide CRM reports.');
        }
        if (! $actor->hasRole('kyc-officer')) {
            return $this->respondForbidden();
        }

        $built = $this->clientListQuery->build($actor, $request);
        if ($built['error'] instanceof JsonResponse) {
            return $built['error'];
        }

        $byKycStatus = [
            'pending' => 0,
            'verified' => 0,
            'rejected' => 0,
        ];
        $rows = (clone $built['query'])->toBase()->reorder()
            ->selectRaw('kyc_status, COUNT(*) AS row_count')
            ->groupBy('kyc_status')
            ->get();
        foreach ($rows as $row) {
            $count = is_numeric($row->row_count ?? null) ? (int) $row->row_count : 0;
            $key = match ((string) ($row->kyc_status ?? '')) {
                Client::KYC_STATUS_VERIFIED => 'verified',
                Client::KYC_STATUS_REJECTED => 'rejected',
                Client::KYC_STATUS_DRAFT, Client::KYC_STATUS_PENDING_REVIEW => 'pending',
                default => null,
            };
            if ($key !== null) {
                $byKycStatus[$key] += $count;
            }
        }

        $recentWorkload = (clone $built['query'])->toBase()
            ->whereIn('kyc_status', [Client::KYC_STATUS_PENDING_REVIEW, Client::KYC_STATUS_DRAFT])
            ->where('updated_at', '>=', now()->subDays(7))
            ->count();

        return $this->respondSuccess([
            'scope' => 'current_agency',
            'by_kyc_status' => $byKycStatus,
            'recent_pending_count' => $recentWorkload,
        ], 'KYC officer dashboard');
    }

    public function accountant(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondUnauthorized();
        }
        if ($actor->hasRole('platform-admin')) {
            return $this->respondForbidden('Platform administrators should use GET /dashboards/operational.');
        }
        if (! $actor->hasRole('accountant') && ! $actor->hasRole('agency-manager')) {
            return $this->respondForbidden();
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
        if ($agencyId === null) {
            return $this->respondForbidden('Accountant dashboard requires an active agency assignment.');
        }

        $journalCounts = $this->journalStatusCounts($agencyId);
        $awaitingRequest = Request::create('/', 'GET', [
            'filter' => ['awaiting_disbursement' => 'true'],
            'currency' => $request->query('currency', 'XAF'),
        ]);
        $awaitingRequest->setUserResolver(static fn (): User => $actor);
        $built = $this->loanListQuery->build($actor, $awaitingRequest);
        $awaitingCount = $built['error'] instanceof JsonResponse
            ? 0
            : count($this->disbursementReadiness->awaitingDisbursementIdsWithin(
                array_values($built['query']->pluck('id')->filter(static fn (mixed $id): bool => is_numeric($id))->map(static fn (mixed $id): int => (int) $id)->all()),
            ));

        return $this->respondSuccess([
            'scope' => 'current_agency',
            'agency_public_id' => DB::table('agencies')->where('id', $agencyId)->value('public_id'),
            'submitted_journal_count' => $journalCounts[JournalEntry::STATUS_SUBMITTED] ?? 0,
            'approved_unposted_journal_count' => $journalCounts[JournalEntry::STATUS_APPROVED] ?? 0,
            'posted_journal_count' => $journalCounts[JournalEntry::STATUS_POSTED] ?? 0,
            'rejected_journal_count' => $journalCounts[JournalEntry::STATUS_REJECTED] ?? 0,
            'awaiting_disbursement_count' => $awaitingCount,
        ], 'Accountant dashboard');
    }

    public function regional(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondUnauthorized();
        }
        if ($actor->hasRole('platform-admin')) {
            return $this->respondForbidden('Platform administrators should use GET /dashboards/operational.');
        }
        if (! $actor->hasRole('regional-manager')) {
            return $this->respondForbidden();
        }

        $agencyIds = $this->staffAgencyScope->activeAssignedAgencyIds($actor);
        if ($agencyIds === []) {
            return $this->respondSuccess([
                'scope' => 'assigned_region',
                'agencies' => [],
                'active_loan_count' => 0,
                'delinquent_loan_count' => 0,
                'portfolio_outstanding_minor' => 0,
            ], 'Regional dashboard');
        }

        $currency = strtoupper((string) ($request->query('currency') ?? 'XAF'));
        $asOfDate = now()->toDateString();
        $agencies = DB::table('agencies')->whereIn('id', $agencyIds)->orderBy('code')->get(['id', 'public_id', 'code', 'name']);
        $rows = [];
        $totalActive = 0;
        $totalDelinquent = 0;
        $totalOutstanding = 0;

        foreach ($agencies as $agency) {
            if (! is_numeric($agency->id ?? null)) {
                continue;
            }
            $agencyModel = Agency::query()->whereKey((int) $agency->id)->first();
            if ($agencyModel === null) {
                continue;
            }

            $active = $this->metrics->reportableLoanCount($agencyModel, $currency, null, null);
            $delinquent = $this->metrics->delinquentLoanCount($agencyModel, $currency, $asOfDate, null, null);
            $outstanding = $this->metrics->portfolioOutstanding($agencyModel, $currency, $asOfDate, null, null);
            $rows[] = [
                'agency_public_id' => $agency->public_id,
                'agency_code' => $agency->code,
                'agency_name' => $agency->name,
                'active_loan_count' => $active,
                'delinquent_loan_count' => $delinquent,
                'portfolio_outstanding_minor' => $outstanding,
            ];
            $totalActive += $active;
            $totalDelinquent += $delinquent;
            $totalOutstanding += $outstanding;
        }

        return $this->respondSuccess([
            'scope' => 'assigned_region',
            'agencies' => $rows,
            'active_loan_count' => $totalActive,
            'delinquent_loan_count' => $totalDelinquent,
            'portfolio_outstanding_minor' => $totalOutstanding,
        ], 'Regional dashboard');
    }

    /**
     * @return list<int>
     */
    private function scopedLoanIdsForCreditAgent(User $actor, int $agencyId, string $currency): array
    {
        return array_values(Loan::query()
            ->where('agency_id', $agencyId)
            ->where('currency', $currency)
            ->where('credit_agent_id', $actor->id)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all());
    }

    /**
     * @param  list<int>  $loanIds
     * @return array<string, int>
     */
    private function loanStatusCounts(array $loanIds): array
    {
        $counts = [
            Loan::STATUS_APPLICATION => 0,
            Loan::STATUS_IN_REVIEW => 0,
            Loan::STATUS_APPROVED => 0,
            Loan::STATUS_DISBURSED => 0,
            Loan::STATUS_ACTIVE => 0,
            Loan::STATUS_RESCHEDULED => 0,
            Loan::STATUS_CLOSED => 0,
            Loan::STATUS_REJECTED => 0,
            Loan::STATUS_WRITTEN_OFF => 0,
        ];
        if ($loanIds === []) {
            return $counts;
        }

        $rows = DB::table('loans')
            ->whereIn('id', $loanIds)
            ->selectRaw('status, COUNT(*) AS row_count')
            ->groupBy('status')
            ->get();
        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            $count = is_numeric($row->row_count ?? null) ? (int) $row->row_count : 0;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function journalStatusCounts(int $agencyId): array
    {
        $counts = [];
        foreach ([
            JournalEntry::STATUS_SUBMITTED,
            JournalEntry::STATUS_APPROVED,
            JournalEntry::STATUS_POSTED,
            JournalEntry::STATUS_REJECTED,
        ] as $status) {
            $counts[$status] = 0;
        }

        $rows = DB::table('journal_entries')
            ->where('agency_id', $agencyId)
            ->whereIn('status', array_keys($counts))
            ->selectRaw('status, COUNT(*) AS row_count')
            ->groupBy('status')
            ->get();
        foreach ($rows as $row) {
            $status = (string) ($row->status ?? '');
            $count = is_numeric($row->row_count ?? null) ? (int) $row->row_count : 0;
            if (array_key_exists($status, $counts)) {
                $counts[$status] = $count;
            }
        }

        return $counts;
    }
}
