<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\ReportRunCollection;
use App\Http\Resources\ReportRunResource;
use App\Models\Agency;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanRepaymentAllocation;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\User;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\FormulaPolicyNotApproved;
use App\Support\Finance\FormulaPolicyRegistry;
use App\Support\Security\SecurityAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ReportRunController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly FormulaPolicyRegistry $formulaPolicyRegistry,
    ) {}

    public function index(Request $request): ReportRunCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('accounting.audit.view')) {
            return $this->respondForbidden();
        }

        return new ReportRunCollection(
            ReportRun::query()
                ->with(['reportDefinition', 'agency', 'document'])
                ->latest()
                ->paginate(min(max($request->integer('per_page', 25), 1), 100))
        );
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('accounting.audit.view')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'report_definition_public_id' => ['required', 'string', 'exists:report_definitions,public_id'],
            'agency_public_id' => ['nullable', 'string', 'exists:agencies,public_id'],
            'period_starts_on' => ['nullable', 'date'],
            'period_ends_on' => ['nullable', 'date', 'after_or_equal:period_starts_on'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'document_public_id' => ['nullable', 'string', 'exists:documents,public_id'],
            'parameters' => ['nullable', 'array'],
        ])->validate();

        $definition = ReportDefinition::query()
            ->where('public_id', $validated['report_definition_public_id'])
            ->where('status', ReportDefinition::STATUS_ACTIVE)
            ->first();
        if (! $definition instanceof ReportDefinition) {
            return $this->respondUnprocessable(errors: ['report_definition_public_id' => ['The selected report definition must be active.']]);
        }
        if (! in_array($definition->report_type, $this->supportedReportTypes(), true)) {
            return $this->respondUnprocessable(errors: ['report_definition_public_id' => ['Selected report type is not supported.']]);
        }

        $agency = isset($validated['agency_public_id'])
            ? Agency::query()->where('public_id', $validated['agency_public_id'])->first()
            : null;
        $document = isset($validated['document_public_id'])
            ? Document::query()->where('public_id', $validated['document_public_id'])->first()
            : null;
        if ($document instanceof Document && $agency instanceof Agency && $document->agency_id !== $agency->id) {
            return $this->respondUnprocessable(errors: ['document_public_id' => ['Report export document must belong to the selected agency.']]);
        }

        $currency = strtoupper($validated['currency'] ?? 'XAF');
        $from = $validated['period_starts_on'] ?? null;
        $to = $validated['period_ends_on'] ?? null;
        if ($definition->report_type === ReportDefinition::TYPE_EMF_TRIAL_BALANCE) {
            $missingMappings = $this->unmappedLedgerAccounts($agency, $currency, $from, $to);
            if ($missingMappings !== []) {
                return $this->respondUnprocessable(errors: [
                    'ledger_accounts' => ['EMF/COBAC report generation requires active mappings for all posted ledger accounts.'],
                    'unmapped_ledger_accounts' => $missingMappings,
                ]);
            }
        }

        try {
            $summary = match ($definition->report_type) {
                ReportDefinition::TYPE_TRIAL_BALANCE => $this->trialBalanceSummary($agency, $currency, $from, $to),
                ReportDefinition::TYPE_EMF_TRIAL_BALANCE => $this->emfTrialBalanceSummary($agency, $currency, $from, $to),
                ReportDefinition::TYPE_CREDIT_PORTFOLIO_OUTSTANDING => $this->creditPortfolioOutstandingSummary($agency, $currency, $from, $to),
                ReportDefinition::TYPE_CREDIT_PAR_DELINQUENCY => $this->creditParDelinquencySummary($agency, $currency, $to ?? now()->toDateString()),
                ReportDefinition::TYPE_CREDIT_COLLECTION_PERFORMANCE => $this->creditCollectionPerformanceSummary($agency, $currency, $from, $to),
                default => $this->generalLedgerSummary($agency, $currency, $from, $to),
            };
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['portfolio_reporting_metrics' => [$exception->getMessage()]]);
        }

        $run = ReportRun::query()->create([
            'public_id' => (string) Str::ulid(),
            'report_definition_id' => $definition->id,
            'agency_id' => $agency?->id,
            'period_starts_on' => $from,
            'period_ends_on' => $to,
            'status' => ReportRun::STATUS_COMPLETED,
            'generated_at' => now(),
            'generated_by_user_id' => $actor->id,
            'document_id' => $document?->id,
            'parameters' => array_merge($validated['parameters'] ?? [], ['currency' => $currency]),
            'summary' => $summary,
        ]);

        $this->securityAudit->record('report_run.generated', actor: $actor, subject: $run, request: $request);

        return $this->respondCreated(ReportRunResource::make($run->loadMissing(['reportDefinition', 'agency', 'document'])), 'Report run generated successfully');
    }

    /**
     * @return array<int, string>
     */
    private function supportedReportTypes(): array
    {
        return [
            ReportDefinition::TYPE_TRIAL_BALANCE,
            ReportDefinition::TYPE_GENERAL_LEDGER,
            ReportDefinition::TYPE_EMF_TRIAL_BALANCE,
            ReportDefinition::TYPE_CREDIT_PORTFOLIO_OUTSTANDING,
            ReportDefinition::TYPE_CREDIT_PAR_DELINQUENCY,
            ReportDefinition::TYPE_CREDIT_COLLECTION_PERFORMANCE,
        ];
    }

    public function show(Request $request, ReportRun $reportRun): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasPermissionTo('accounting.audit.view')) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(ReportRunResource::make($reportRun->loadMissing(['reportDefinition', 'agency', 'document'])));
    }

    /**
     * @return array<string, mixed>
     */
    private function trialBalanceSummary(?Agency $agency, string $currency, ?string $from, ?string $to): array
    {
        $query = $this->postedLineQuery($agency, $currency, $from, $to)
            ->selectRaw('ledger_accounts.public_id AS ledger_account_public_id')
            ->selectRaw('ledger_accounts.code AS ledger_account_code')
            ->selectRaw('ledger_accounts.name AS ledger_account_name')
            ->selectRaw('ledger_accounts.normal_balance_side AS normal_balance_side')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total_minor')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total_minor')
            ->groupBy('ledger_accounts.id', 'ledger_accounts.public_id', 'ledger_accounts.code', 'ledger_accounts.name', 'ledger_accounts.normal_balance_side')
            ->orderBy('ledger_accounts.code');

        $rows = $query->get()->map(function (object $row): array {
            $debit = (int) $row->debit_total_minor;
            $credit = (int) $row->credit_total_minor;

            return [
                'ledger_account_public_id' => $row->ledger_account_public_id,
                'ledger_account_code' => $row->ledger_account_code,
                'ledger_account_name' => $row->ledger_account_name,
                'normal_balance_side' => $row->normal_balance_side,
                'debit_total_minor' => $debit,
                'credit_total_minor' => $credit,
                'balance_minor' => $row->normal_balance_side === LedgerAccount::NORMAL_BALANCE_CREDIT ? $credit - $debit : $debit - $credit,
            ];
        })->all();

        return [
            'report_type' => ReportDefinition::TYPE_TRIAL_BALANCE,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'row_count' => count($rows),
            'debit_total_minor' => array_sum(array_column($rows, 'debit_total_minor')),
            'credit_total_minor' => array_sum(array_column($rows, 'credit_total_minor')),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generalLedgerSummary(?Agency $agency, string $currency, ?string $from, ?string $to): array
    {
        $totals = $this->postedLineQuery($agency, $currency, $from, $to)
            ->selectRaw('COUNT(*) AS line_count')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total_minor')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total_minor')
            ->first();

        return [
            'report_type' => ReportDefinition::TYPE_GENERAL_LEDGER,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'line_count' => (int) ($totals->line_count ?? 0),
            'debit_total_minor' => (int) ($totals->debit_total_minor ?? 0),
            'credit_total_minor' => (int) ($totals->credit_total_minor ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function creditPortfolioOutstandingSummary(?Agency $agency, string $currency, ?string $from, ?string $to): array
    {
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::PortfolioReportingMetrics);

        $rows = $this->reportableLoans($agency, $currency)
            ->map(fn (Loan $loan): array => $this->loanExposureRow($loan, $to))
            ->filter(fn (array $row): bool => $row['outstanding_minor'] > 0)
            ->values()
            ->all();

        return [
            'report_type' => ReportDefinition::TYPE_CREDIT_PORTFOLIO_OUTSTANDING,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'loan_count' => count($rows),
            'principal_outstanding_minor' => array_sum(array_column($rows, 'principal_outstanding_minor')),
            'interest_outstanding_minor' => array_sum(array_column($rows, 'interest_outstanding_minor')),
            'penalty_outstanding_minor' => array_sum(array_column($rows, 'penalty_outstanding_minor')),
            'outstanding_minor' => array_sum(array_column($rows, 'outstanding_minor')),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function creditParDelinquencySummary(?Agency $agency, string $currency, string $asOfDate): array
    {
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::PortfolioReportingMetrics);

        $rows = [];
        foreach ($this->reportableLoans($agency, $currency) as $loan) {
            $row = $this->loanExposureRow($loan, $asOfDate);
            $overdue = $this->loanOverdueAmount($loan, $asOfDate, 30);
            if ($overdue <= 0) {
                continue;
            }

            $row['par30_overdue_amount_minor'] = $overdue;
            $row['restructured'] = $loan->status === Loan::STATUS_RESCHEDULED;
            $rows[] = $row;
        }

        return [
            'report_type' => ReportDefinition::TYPE_CREDIT_PAR_DELINQUENCY,
            'currency' => $currency,
            'as_of_date' => $asOfDate,
            'loan_count' => count($rows),
            'par30_outstanding_at_risk_minor' => array_sum(array_column($rows, 'outstanding_minor')),
            'delinquent_overdue_amount_minor' => array_sum(array_column($rows, 'par30_overdue_amount_minor')),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function creditCollectionPerformanceSummary(?Agency $agency, string $currency, ?string $from, ?string $to): array
    {
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::PortfolioReportingMetrics);

        $expected = $this->expectedCollectionAmount($agency, $currency, $from, $to);
        $actual = $this->actualCollectionAmount($agency, $currency, $from, $to);

        return [
            'report_type' => ReportDefinition::TYPE_CREDIT_COLLECTION_PERFORMANCE,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'expected_collection_minor' => $expected,
            'actual_collection_minor' => $actual,
            'collection_gap_minor' => max(0, $expected - $actual),
            'performance_ratio' => $expected > 0 ? round($actual / $expected, 6) : null,
        ];
    }

    /**
     * @return Collection<int, Loan>
     */
    private function reportableLoans(?Agency $agency, string $currency): Collection
    {
        $loanIds = DB::table('loans')
            ->select('id')
            ->where('currency', $currency)
            ->whereIn('status', [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED]);
        if ($agency instanceof Agency) {
            $loanIds->where('agency_id', $agency->id);
        }

        $ids = $loanIds->pluck('id')
            ->filter(fn (mixed $id): bool => is_int($id))
            ->values();
        $models = Loan::query()->findMany($ids->all())->keyBy('id');

        return $ids
            ->map(fn (int $id): mixed => $models->get($id))
            ->filter(fn (mixed $loan): bool => $loan instanceof Loan)
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function loanExposureRow(Loan $loan, ?string $asOfDate): array
    {
        $principal = $this->loanOpenComponentAmount($loan, LoanRepaymentAllocation::COMPONENT_PRINCIPAL, 'principal_minor', null, $asOfDate);
        $interest = $this->loanOpenComponentAmount($loan, LoanRepaymentAllocation::COMPONENT_INTEREST, 'interest_minor', null, $asOfDate);
        $penalty = $this->loanOpenComponentAmount($loan, LoanRepaymentAllocation::COMPONENT_PENALTY, 'penalty_minor', null, $asOfDate);

        return [
            'loan_public_id' => $loan->public_id,
            'loan_number' => $loan->loan_number,
            'status' => $loan->status,
            'agency_id' => $loan->agency_id,
            'principal_outstanding_minor' => $principal,
            'interest_outstanding_minor' => $interest,
            'penalty_outstanding_minor' => $penalty,
            'outstanding_minor' => $principal + $interest + $penalty,
        ];
    }

    private function loanOverdueAmount(Loan $loan, string $asOfDate, int $daysPastDue): int
    {
        $cutoff = CarbonImmutable::parse($asOfDate)->subDays($daysPastDue)->toDateString();

        return $this->loanOpenComponentAmount($loan, LoanRepaymentAllocation::COMPONENT_PRINCIPAL, 'principal_minor', $cutoff, null)
            + $this->loanOpenComponentAmount($loan, LoanRepaymentAllocation::COMPONENT_INTEREST, 'interest_minor', $cutoff, null)
            + $this->loanOpenComponentAmount($loan, LoanRepaymentAllocation::COMPONENT_PENALTY, 'penalty_minor', $cutoff, null);
    }

    private function loanOpenComponentAmount(Loan $loan, string $component, string $column, ?string $dueOnOrBefore, ?string $dueOnReportLimit): int
    {
        $query = DB::table('loan_schedule_lines')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->where('loan_schedule_snapshots.loan_id', $loan->id)
            ->where('loan_schedule_snapshots.status', 'active');
        if ($dueOnOrBefore !== null) {
            $query->whereDate('loan_schedule_lines.due_date', '<', $dueOnOrBefore);
        }
        if ($dueOnReportLimit !== null) {
            $query->whereDate('loan_schedule_lines.due_date', '<=', $dueOnReportLimit);
        }

        $due = (int) $query->sum('loan_schedule_lines.'.$column);
        if ($due <= 0) {
            return 0;
        }

        $paid = (int) DB::table('loan_repayment_allocations')
            ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
            ->join('loan_schedule_lines', 'loan_schedule_lines.id', '=', 'loan_repayment_allocations.loan_schedule_line_id')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->where('loan_schedule_snapshots.loan_id', $loan->id)
            ->where('loan_schedule_snapshots.status', 'active')
            ->where('loan_repayments.status', 'posted')
            ->where('loan_repayment_allocations.component', $component)
            ->sum('loan_repayment_allocations.amount_minor');

        return max(0, $due - $paid);
    }

    private function expectedCollectionAmount(?Agency $agency, string $currency, ?string $from, ?string $to): int
    {
        $query = DB::table('loan_schedule_lines')
            ->join('loan_schedule_snapshots', 'loan_schedule_snapshots.id', '=', 'loan_schedule_lines.loan_schedule_snapshot_id')
            ->join('loans', 'loans.id', '=', 'loan_schedule_snapshots.loan_id')
            ->where('loan_schedule_snapshots.status', 'active')
            ->where('loans.currency', $currency)
            ->whereIn('loans.status', [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED]);
        if ($agency instanceof Agency) {
            $query->where('loans.agency_id', $agency->id);
        }
        if ($from !== null) {
            $query->whereDate('loan_schedule_lines.due_date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('loan_schedule_lines.due_date', '<=', $to);
        }

        $total = $query
            ->selectRaw('COALESCE(SUM(loan_schedule_lines.principal_minor + loan_schedule_lines.interest_minor + loan_schedule_lines.penalty_minor), 0) AS total_minor')
            ->first();

        return (int) ($total->total_minor ?? 0);
    }

    private function actualCollectionAmount(?Agency $agency, string $currency, ?string $from, ?string $to): int
    {
        $query = DB::table('loan_repayment_allocations')
            ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
            ->join('loans', 'loans.id', '=', 'loan_repayments.loan_id')
            ->where('loan_repayments.status', 'posted')
            ->where('loan_repayments.currency', $currency)
            ->whereIn('loan_repayment_allocations.component', [
                LoanRepaymentAllocation::COMPONENT_PRINCIPAL,
                LoanRepaymentAllocation::COMPONENT_INTEREST,
                LoanRepaymentAllocation::COMPONENT_PENALTY,
            ]);
        if ($agency instanceof Agency) {
            $query->where('loans.agency_id', $agency->id);
        }
        if ($from !== null) {
            $query->whereDate('loan_repayments.paid_on', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('loan_repayments.paid_on', '<=', $to);
        }

        return (int) $query->sum('loan_repayment_allocations.amount_minor');
    }

    /**
     * @return array<string, mixed>
     */
    private function emfTrialBalanceSummary(?Agency $agency, string $currency, ?string $from, ?string $to): array
    {
        $rows = $this->postedLineQuery($agency, $currency, $from, $to)
            ->join('emf_ledger_account_mappings', function ($join): void {
                $join->on('emf_ledger_account_mappings.ledger_account_id', '=', 'ledger_accounts.id')
                    ->where('emf_ledger_account_mappings.status', '=', 'active');
            })
            ->join('emf_regulatory_accounts', function ($join): void {
                $join->on('emf_regulatory_accounts.id', '=', 'emf_ledger_account_mappings.emf_regulatory_account_id')
                    ->where('emf_regulatory_accounts.status', '=', 'active');
            })
            ->selectRaw('emf_regulatory_accounts.public_id AS emf_regulatory_account_public_id')
            ->selectRaw('emf_regulatory_accounts.code AS emf_code')
            ->selectRaw('emf_regulatory_accounts.name AS emf_name')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total_minor')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total_minor')
            ->groupBy('emf_regulatory_accounts.id', 'emf_regulatory_accounts.public_id', 'emf_regulatory_accounts.code', 'emf_regulatory_accounts.name')
            ->orderBy('emf_regulatory_accounts.code')
            ->get()
            ->map(fn (object $row): array => [
                'emf_regulatory_account_public_id' => $row->emf_regulatory_account_public_id,
                'emf_code' => $row->emf_code,
                'emf_name' => $row->emf_name,
                'debit_total_minor' => (int) $row->debit_total_minor,
                'credit_total_minor' => (int) $row->credit_total_minor,
            ])
            ->all();

        return [
            'report_type' => ReportDefinition::TYPE_EMF_TRIAL_BALANCE,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'row_count' => count($rows),
            'debit_total_minor' => array_sum(array_column($rows, 'debit_total_minor')),
            'credit_total_minor' => array_sum(array_column($rows, 'credit_total_minor')),
            'rows' => $rows,
        ];
    }

    /**
     * @return array<int, array{public_id:string, code:string, name:string}>
     */
    private function unmappedLedgerAccounts(?Agency $agency, string $currency, ?string $from, ?string $to): array
    {
        return $this->postedLineQuery($agency, $currency, $from, $to)
            ->leftJoin('emf_ledger_account_mappings', function ($join): void {
                $join->on('emf_ledger_account_mappings.ledger_account_id', '=', 'ledger_accounts.id')
                    ->where('emf_ledger_account_mappings.status', '=', 'active');
            })
            ->leftJoin('emf_regulatory_accounts', function ($join): void {
                $join->on('emf_regulatory_accounts.id', '=', 'emf_ledger_account_mappings.emf_regulatory_account_id')
                    ->where('emf_regulatory_accounts.status', '=', 'active');
            })
            ->whereNull('emf_regulatory_accounts.id')
            ->select('ledger_accounts.public_id', 'ledger_accounts.code', 'ledger_accounts.name')
            ->distinct()
            ->orderBy('ledger_accounts.code')
            ->get()
            ->map(fn (object $row): array => [
                'public_id' => (string) $row->public_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
            ])
            ->all();
    }

    private function postedLineQuery(?Agency $agency, string $currency, ?string $from, ?string $to): Builder
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.currency', $currency);

        if ($agency instanceof Agency) {
            $query->where('journal_entries.agency_id', $agency->id);
        }
        if ($from !== null) {
            $query->whereDate('journal_entries.business_date', '>=', $from);
        }
        if ($to !== null) {
            $query->whereDate('journal_entries.business_date', '<=', $to);
        }

        return $query;
    }
}
