<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Http\Controllers\BaseController;
use App\Http\Resources\ResultAppropriationResource;
use App\Models\Agency;
use App\Models\ExerciseClosing;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\ResultAppropriation;
use App\Models\User;
use App\Support\Accounting\SoldesIntermediairesDeGestion;
use App\Support\AccountingDay\AccountingDayException;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\AccountingDay\AccountingScopeAccess;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Affectation du résultat — empties 131 or 132 into the accounts the assemblée
 * générale decided on.
 *
 * The split is not computed here. The réserve légale rate, what goes to report à
 * nouveau, what is distributed — those are the AG's decision, taken months after
 * the clôture and recorded in its minutes. This workflow's job is to check the
 * decision adds up to the result exactly, post it, and refuse everything else.
 *
 * Posted into the current open accounting day, not back into the closed exercise:
 * the allocation is an event of the year in which the AG met.
 */
final class ResultAppropriationWorkflow extends BaseController
{
    public const string SOURCE_TYPE = 'result_appropriation';

    public const string SOURCE_MODULE = 'accounting';

    public const string PERMISSION = 'accounting.exercise.appropriate';

    public function __construct(
        private readonly AccountingDayGuard $accountingDayGuard,
        private readonly AccountingScopeAccess $scopeAccess,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->can('accounting.audit.view')) {
            return $this->respondForbidden();
        }

        $query = ResultAppropriation::query()->with(['agency', 'journalEntry'])->latest();

        if (! $this->scopeAccess->canManageInstitutionScope($actor) && ! $actor->can('ledger.scope.institution.read')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }
            $query->where('agency_id', $agencyId);
        }

        return $this->respondSuccess([
            'result_appropriations' => ResultAppropriationResource::collection($query->get())->resolve(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->can(self::PERMISSION)) {
            return $this->respondForbidden();
        }

        $agency = $this->resolveAgency($request, $actor);
        if (! $agency instanceof Agency) {
            return $this->respondUnprocessable(errors: ['agency_public_id' => [__('domain.journal_entry_requires_agency')]]);
        }
        if (! $this->scopeAccess->canManageInstitutionScope($actor)
            && $this->staffAgencyScope->currentAgencyId($actor) !== $agency->id) {
            return $this->respondUnprocessable(errors: ['agency_public_id' => [__('domain.journal_entry_outside_actor_scope')]]);
        }

        $fiscalYear = $request->integer('fiscal_year');
        $currencyInput = $request->input('currency');
        $currency = strtoupper(is_string($currencyInput) && $currencyInput !== '' ? $currencyInput : 'XAF');

        $decidedOn = $request->input('decided_on');
        if (! is_string($decidedOn) || $decidedOn === '') {
            return $this->respondUnprocessable(errors: ['decided_on' => [__('domain.appropriation_decided_on_required')]]);
        }

        // The result may only be allocated once the clôture has actually reached
        // the ledger. Allocating from a clôture still in review would credit the
        // réserves from an account that holds nothing yet.
        $closing = ExerciseClosing::query()
            ->with('journalEntry')
            ->where('agency_id', $agency->id)
            ->where('fiscal_year', $fiscalYear)
            ->where('currency', $currency)
            ->first();
        if (! $closing instanceof ExerciseClosing || ! $closing->isPosted()) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [__('domain.appropriation_requires_posted_closing')]]);
        }

        $alreadyDone = DB::table('result_appropriations')
            ->where('agency_id', $agency->id)
            ->where('fiscal_year', $fiscalYear)
            ->where('currency', $currency)
            ->exists();
        if ($alreadyDone) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [__('domain.appropriation_already_done')]]);
        }

        $netResult = $closing->net_result_minor;
        if ($netResult === 0) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [__('domain.appropriation_nothing_to_allocate')]]);
        }

        $sourceCode = SoldesIntermediairesDeGestion::resultAccountFor($netResult);
        $source = $this->postableAccount($agency->id, $sourceCode);
        if (! $source instanceof LedgerAccount) {
            return $this->respondUnprocessable(errors: ['source_account' => [
                __('domain.exercise_result_account_missing', ['code' => $sourceCode]),
            ]]);
        }

        // Allocated oldest first. An AG approves one exercise at a time, and the
        // result accounts do not distinguish which year their balance came from:
        // 131 holding two unallocated bénéfices looks exactly like one large one.
        // Allocating the newer year would empty its share and strand the older,
        // which is then marked neither allocated nor reachable.
        $earlier = $this->earliestUnallocatedExercise($agency->id, $currency, $fiscalYear);
        if ($earlier !== null) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [
                __('domain.appropriation_earlier_year_first', ['year' => (string) $earlier]),
            ]]);
        }

        // Defensive: the account must actually hold what is about to be taken out
        // of it. Less than that means something outside these workflows moved it,
        // and posting anyway would overdraw a capital account.
        $held = $this->heldResult($source->id, $currency);
        $expected = abs($netResult);
        if ($held < $expected) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [
                __('domain.appropriation_source_balance_short', [
                    'code' => $sourceCode,
                    'held' => (string) $held,
                    'expected' => (string) $expected,
                ]),
            ]]);
        }

        $allocations = $this->readAllocations($request, $agency->id, $sourceCode);
        if (is_string($allocations)) {
            return $this->respondUnprocessable(errors: ['allocations' => [$allocations]]);
        }

        $allocated = 0;
        foreach ($allocations as $allocation) {
            $allocated += $allocation['amount_minor'];
        }
        if ($allocated !== $expected) {
            return $this->respondUnprocessable(errors: ['allocations' => [
                __('domain.appropriation_total_mismatch', [
                    'allocated' => (string) $allocated,
                    'expected' => (string) $expected,
                ]),
            ]]);
        }

        try {
            // The current open day: the AG met in this exercise, not the one being
            // allocated, and reopening a settled year to record its own AG would
            // put the entry back inside accounts already filed.
            $day = $this->accountingDayGuard->resolveAccountingDay($actor, 'journal.create', $agency->id, null, $request);
        } catch (AccountingDayException $exception) {
            return $this->respondUnprocessable(errors: ['decided_on' => [$exception->getMessage()]]);
        }

        $appropriation = DB::transaction(function () use (
            $actor, $agency, $fiscalYear, $currency, $decidedOn, $netResult,
            $sourceCode, $source, $allocations, $expected, $day
        ): ResultAppropriation {
            $entry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => 'AFFECT-'.$fiscalYear.'-'.$agency->code,
                'business_date' => $day->business_date->toDateString(),
                'accounting_day_id' => $day->id,
                'posted_at' => null,
                'agency_id' => $agency->id,
                'source_module' => self::SOURCE_MODULE,
                'source_type' => self::SOURCE_TYPE,
                'source_public_id' => null,
                // Draft while the lines go on; a trigger holds lines under a
                // submitted entry immutable.
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => "Affectation du résultat de l'exercice ".$fiscalYear,
                'created_by_user_id' => $actor->id,
                'submitted_by_user_id' => null,
                'submitted_at' => null,
                'posted_by_user_id' => null,
                'reversed_by_user_id' => null,
                'reversal_of_journal_entry_id' => null,
                'idempotency_key' => null,
            ]);

            // A bénéfice is emptied by debiting 131 and crediting the destinations;
            // a perte by crediting 132 and debiting them, since the loss is being
            // absorbed rather than distributed.
            $isProfit = $netResult > 0;

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agency->id,
                'journal_entry_id' => $entry->id,
                'ledger_account_id' => $source->id,
                'customer_account_id' => null,
                'loan_id' => null,
                'debit_minor' => $isProfit ? $expected : 0,
                'credit_minor' => $isProfit ? 0 : $expected,
                'currency' => $currency,
                'line_memo' => 'Solde du '.$sourceCode.' — exercice '.$fiscalYear,
            ]);

            foreach ($allocations as $allocation) {
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agency->id,
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => $allocation['ledger_account_id'],
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => $isProfit ? 0 : $allocation['amount_minor'],
                    'credit_minor' => $isProfit ? $allocation['amount_minor'] : 0,
                    'currency' => $currency,
                    'line_memo' => 'Affectation du résultat '.$fiscalYear,
                ]);
            }

            $entry->forceFill([
                'status' => JournalEntry::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_by_user_id' => $actor->id,
            ])->save();

            return ResultAppropriation::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agency->id,
                'fiscal_year' => $fiscalYear,
                'currency' => $currency,
                'source_account_code' => $sourceCode,
                'amount_minor' => $expected,
                'decided_on' => $decidedOn,
                'journal_entry_id' => $entry->id,
                'created_by_user_id' => $actor->id,
            ]);
        });

        $this->securityAudit->record('exercise.result_appropriated', actor: $actor, subject: $appropriation, request: $request);

        return $this->respondCreated(
            ResultAppropriationResource::make($appropriation->loadMissing(['agency', 'journalEntry'])),
            'Result appropriation created successfully',
        );
    }

    /**
     * The AG's split, validated. Returns a message instead of rows when the
     * request cannot be honoured.
     *
     * @return array<int, array{ledger_account_id: int, amount_minor: int}>|string
     */
    private function readAllocations(Request $request, int $agencyId, string $sourceCode): array|string
    {
        $raw = $request->input('allocations');
        if (! is_array($raw) || $raw === []) {
            return __('domain.appropriation_allocations_required');
        }

        $rows = [];
        $seen = [];
        foreach ($raw as $item) {
            if (! is_array($item)) {
                return __('domain.appropriation_allocations_required');
            }

            $publicId = $item['ledger_account_public_id'] ?? null;
            $amount = $item['amount_minor'] ?? null;
            if (! is_string($publicId) || ! is_int($amount)) {
                return __('domain.appropriation_allocations_required');
            }
            if ($amount <= 0) {
                return __('domain.appropriation_allocation_amount_positive');
            }

            $account = LedgerAccount::query()->where('public_id', $publicId)->first();
            if (! $account instanceof LedgerAccount) {
                return __('domain.appropriation_allocation_account_invalid', ['id' => $publicId]);
            }
            // Allocating the result back into the account it came from would post a
            // line against itself and balance perfectly while achieving nothing.
            if (in_array($account->code, [
                SoldesIntermediairesDeGestion::RESULT_ACCOUNT_PROFIT,
                SoldesIntermediairesDeGestion::RESULT_ACCOUNT_LOSS,
            ], true)) {
                return __('domain.appropriation_allocation_not_result_account', ['code' => $sourceCode]);
            }
            if ($account->agency_id !== $agencyId || ! $account->is_postable || $account->status !== LedgerAccount::STATUS_ACTIVE) {
                return __('domain.appropriation_allocation_account_invalid', ['id' => $publicId]);
            }
            if (isset($seen[$account->id])) {
                return __('domain.appropriation_allocation_duplicated', ['code' => $account->code]);
            }

            $seen[$account->id] = true;
            $rows[] = ['ledger_account_id' => $account->id, 'amount_minor' => $amount];
        }

        return $rows;
    }

    /**
     * The earliest exercise whose clôture is posted but whose result has not been
     * allocated, or null when $fiscalYear is the next one due.
     *
     * Mirrors the clôture's own ordering rule. Both are asked the same way: is
     * there something older still outstanding?
     */
    private function earliestUnallocatedExercise(int $agencyId, string $currency, int $fiscalYear): ?int
    {
        $earliest = DB::table('exercise_closings')
            ->join('journal_entries', 'journal_entries.id', '=', 'exercise_closings.journal_entry_id')
            ->leftJoin('result_appropriations', function ($join) use ($currency): void {
                $join->on('result_appropriations.agency_id', '=', 'exercise_closings.agency_id')
                    ->on('result_appropriations.fiscal_year', '=', 'exercise_closings.fiscal_year')
                    ->where('result_appropriations.currency', '=', $currency);
            })
            ->where('exercise_closings.agency_id', $agencyId)
            ->where('exercise_closings.currency', $currency)
            ->where('exercise_closings.fiscal_year', '<', $fiscalYear)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->whereNull('result_appropriations.id')
            // A break-even exercise has nothing to allocate, so it is never owed.
            ->where('exercise_closings.net_result_minor', '!=', 0)
            ->selectRaw('MIN(exercise_closings.fiscal_year) AS earliest')
            ->value('earliest');

        return is_numeric($earliest) ? (int) $earliest : null;
    }

    /** Cumulative posted balance of the result account, as a magnitude. */
    private function heldResult(int $ledgerAccountId, string $currency): int
    {
        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $ledgerAccountId)
            ->where('journal_lines.currency', $currency)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total')
            ->first();

        $debit = (int) (((array) $row)['debit_total'] ?? 0);
        $credit = (int) (((array) $row)['credit_total'] ?? 0);

        return abs($credit - $debit);
    }

    private function postableAccount(int $agencyId, string $code): ?LedgerAccount
    {
        return LedgerAccount::query()
            ->where('agency_id', $agencyId)
            ->where('code', $code)
            ->where('is_postable', true)
            ->where('status', LedgerAccount::STATUS_ACTIVE)
            ->first();
    }

    private function resolveAgency(Request $request, User $actor): ?Agency
    {
        if ($request->filled('agency_public_id')) {
            return Agency::query()->where('public_id', $request->string('agency_public_id'))->first();
        }

        $agencyId = $this->staffAgencyScope->currentAgencyId($actor);

        return $agencyId === null ? null : Agency::query()->whereKey($agencyId)->first();
    }
}
