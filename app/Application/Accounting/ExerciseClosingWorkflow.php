<?php

declare(strict_types=1);

namespace App\Application\Accounting;

use App\Http\Controllers\BaseController;
use App\Http\Resources\ExerciseClosingResource;
use App\Models\Agency;
use App\Models\ExerciseClosing;
use App\Models\InstitutionProfile;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Accounting\SoldesIntermediairesDeGestion;
use App\Support\AccountingDay\AccountingDayException;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\AccountingDay\AccountingScopeAccess;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Clôture annuelle — closes classes 6 and 7 and carries the result to 131 or 132.
 *
 * « À la fin de l'exercice, le résultat du 87 doit être transféré dans le 131
 *   s'il est positif (bénéfice) ou dans le 132 s'il est négatif (perte). »
 *
 * The transfer is an ordinary journal entry, deliberately: it goes through the
 * same maker-checker, the same accounting day, and the same posting rules as any
 * other. It is created submitted rather than posted, so a second pair of eyes
 * approves the single largest entry of the year.
 */
final class ExerciseClosingWorkflow extends BaseController
{
    /** Marks the entry so the compte de résultat can leave it out of the period it closes. */
    public const string SOURCE_TYPE = 'exercise_closing';

    public const string SOURCE_MODULE = 'accounting';

    public const string PERMISSION = 'accounting.exercise.close';

    public function __construct(
        private readonly AccountingDayGuard $accountingDayGuard,
        private readonly AccountingScopeAccess $scopeAccess,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly SecurityAudit $securityAudit,
    ) {}

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
        if ($fiscalYear < 2000 || $fiscalYear > 2200) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [__('domain.exercise_fiscal_year_invalid')]]);
        }

        $currencyInput = $request->input('currency');
        $currency = strtoupper(is_string($currencyInput) && $currencyInput !== '' ? $currencyInput : 'XAF');
        [$opensOn, $closesOn] = $this->exercisePeriod($fiscalYear);

        // Checked before any work: the unique index is the real guard, but
        // reaching it would mean building the whole entry first and reporting a
        // constraint violation for something we can answer plainly.
        $existing = DB::table('exercise_closings')
            ->where('agency_id', $agency->id)
            ->where('fiscal_year', $fiscalYear)
            ->where('currency', $currency)
            ->exists();
        if ($existing) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [__('domain.exercise_already_closed')]]);
        }

        // Exercises close in order. An EMF's exercise is approved by the
        // assemblée générale and filed with COBAC one year at a time, and the
        // arithmetic here depends on it: balances are cumulative, so closing 2026
        // while 2025 is still open would sweep 2025's charges and produits into
        // 2026's result. Both years would be misstated, and 2025 could never be
        // closed afterwards because nothing would be left in it to close.
        //
        // The earlier closing must be posted, not merely drawn up. A clôture
        // waiting for review has moved nothing, so its exercise is still sitting
        // in classes 6 and 7.
        $blocking = $this->earliestUnclosedExercise($agency->id, $currency, $fiscalYear);
        if ($blocking !== null) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [
                __('domain.exercise_earlier_year_still_open', ['year' => (string) $blocking]),
            ]]);
        }

        $balances = $this->resultAccountBalances($agency->id, $currency, $closesOn);
        if ($balances === []) {
            return $this->respondUnprocessable(errors: ['fiscal_year' => [__('domain.exercise_nothing_to_close')]]);
        }

        // net = produits − charges. Each raw balance is debit − credit, so a
        // charge is positive and a produit negative, and the result is the
        // negation of their sum. Derived from the same figures that are about to
        // be reversed, so the entry cannot disagree with the result it carries.
        $rawTotal = 0;
        foreach ($balances as $balance) {
            $rawTotal += $balance['raw_minor'];
        }
        $netResult = -$rawTotal;

        $resultCode = SoldesIntermediairesDeGestion::resultAccountFor($netResult);
        $resultAccount = $this->postableAccount($agency->id, $resultCode);
        if (! $resultAccount instanceof LedgerAccount && $netResult !== 0) {
            return $this->respondUnprocessable(errors: ['result_account' => [
                __('domain.exercise_result_account_missing', ['code' => $resultCode]),
            ]]);
        }

        try {
            $day = $this->accountingDayGuard->resolveAccountingDay(
                $actor,
                'journal.create',
                $agency->id,
                $closesOn->toDateString(),
                $request,
            );
        } catch (AccountingDayException $exception) {
            return $this->respondUnprocessable(errors: ['closes_on' => [$exception->getMessage()]]);
        }

        $closing = DB::transaction(function () use (
            $actor, $agency, $fiscalYear, $opensOn, $closesOn, $currency,
            $balances, $netResult, $resultCode, $resultAccount, $day
        ): ExerciseClosing {
            $entry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => 'CLOT-'.$fiscalYear.'-'.$agency->code,
                'business_date' => $closesOn->toDateString(),
                'accounting_day_id' => $day->id,
                'posted_at' => null,
                'agency_id' => $agency->id,
                'source_module' => self::SOURCE_MODULE,
                'source_type' => self::SOURCE_TYPE,
                'source_public_id' => null,
                // Draft while the lines go on: a database trigger holds journal
                // lines under a submitted entry immutable, which is the guard that
                // makes a submitted entry mean something. Submitted below, once it
                // is complete and balanced.
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => 'Clôture de l\'exercice '.$fiscalYear,
                'created_by_user_id' => $actor->id,
                'submitted_by_user_id' => null,
                'submitted_at' => null,
                'posted_by_user_id' => null,
                'reversed_by_user_id' => null,
                'reversal_of_journal_entry_id' => null,
                'idempotency_key' => null,
            ]);

            $debitTotal = 0;
            $creditTotal = 0;

            // Solde each account by posting the opposite of where it stands, so it
            // ends the exercise at nil.
            foreach ($balances as $balance) {
                $raw = $balance['raw_minor'];
                $debit = $raw < 0 ? -$raw : 0;
                $credit = $raw > 0 ? $raw : 0;

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agency->id,
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => $balance['ledger_account_id'],
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => $debit,
                    'credit_minor' => $credit,
                    'currency' => $currency,
                    'line_memo' => 'Solde de clôture '.$fiscalYear,
                ]);

                $debitTotal += $debit;
                $creditTotal += $credit;
            }

            if ($netResult !== 0) {
                // A bénéfice is a credit to 131, a perte a debit to 132.
                $debit = $netResult < 0 ? -$netResult : 0;
                $credit = $netResult > 0 ? $netResult : 0;

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agency->id,
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => $resultAccount->id,
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => $debit,
                    'credit_minor' => $credit,
                    'currency' => $currency,
                    'line_memo' => 'Résultat de l\'exercice '.$fiscalYear,
                ]);

                $debitTotal += $debit;
                $creditTotal += $credit;
            }

            // Defensive, and worth the two lines: an unbalanced clôture would be
            // rejected at review with nothing to say which account was wrong, and
            // the arithmetic above is the whole point of this class.
            if ($debitTotal !== $creditTotal) {
                throw new RuntimeException(
                    "Clôture {$fiscalYear} is unbalanced: debit {$debitTotal} against credit {$creditTotal}."
                );
            }

            // Complete and balanced: hand it to the reviewer.
            $entry->forceFill([
                'status' => JournalEntry::STATUS_SUBMITTED,
                'submitted_at' => now(),
                'submitted_by_user_id' => $actor->id,
            ])->save();

            return ExerciseClosing::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agency->id,
                'fiscal_year' => $fiscalYear,
                'opens_on' => $opensOn->toDateString(),
                'closes_on' => $closesOn->toDateString(),
                'currency' => $currency,
                'net_result_minor' => $netResult,
                'result_account_code' => $resultCode,
                'journal_entry_id' => $entry->id,
                'created_by_user_id' => $actor->id,
            ]);
        });

        $this->securityAudit->record('exercise.closed', actor: $actor, subject: $closing, request: $request);

        return $this->respondCreated(
            ExerciseClosingResource::make($closing->loadMissing(['agency', 'journalEntry'])),
            'Exercise closing created successfully',
        );
    }

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->can('accounting.audit.view')) {
            return $this->respondForbidden();
        }

        $query = ExerciseClosing::query()->with(['agency', 'journalEntry'])->latest();

        if (! $this->scopeAccess->canManageInstitutionScope($actor) && ! $actor->can('ledger.scope.institution.read')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden();
            }
            $query->where('agency_id', $agencyId);
        }

        return $this->respondSuccess([
            'exercise_closings' => ExerciseClosingResource::collection($query->get())->resolve(),
        ]);
    }

    /**
     * The exercise period, from the institution's fiscal-year start month. The
     * exercise is named by the calendar year it opens in, so "2026" with a March
     * start runs 2026-03-01 to 2027-02-28.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function exercisePeriod(int $fiscalYear): array
    {
        $opensOn = Carbon::create($fiscalYear, $this->fiscalYearStartMonth(), 1);
        if (! $opensOn instanceof Carbon) {
            throw new RuntimeException("Cannot resolve the exercise opening for {$fiscalYear}.");
        }
        $opensOn = $opensOn->startOfDay();

        // A year on, less a day: end-of-month arithmetic is why this is not
        // "same day next year minus one", which mis-handles February.
        $closesOn = $opensOn->copy()->addYear()->subDay();

        return [$opensOn, $closesOn];
    }

    /**
     * Class 6 and 7 balances for the agency, cumulative up to the closing date,
     * excluding accounts that already stand at nil.
     *
     * Cumulative rather than period-bounded, and that is deliberate: the purpose
     * of a clôture is that these accounts end at zero. A period-bounded balance
     * would leave anything posted outside the period behind — a back-dated entry,
     * or an exercise nobody closed — and the account would carry it forward
     * invisibly into every later year. Cumulative is also self-correcting: once an
     * exercise is closed its accounts are at nil, so the next cumulative balance
     * is exactly the next exercise's activity.
     *
     * Previous clôture entries are included for that reason. They are what makes
     * the running total nil.
     *
     * @return array<int, array{ledger_account_id: int, raw_minor: int}>
     */
    private function resultAccountBalances(int $agencyId, string $currency, Carbon $closesOn): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_entries.agency_id', $agencyId)
            ->where('journal_lines.currency', $currency)
            ->whereDate('journal_entries.business_date', '<=', $closesOn->toDateString())
            ->whereRaw("left(ledger_accounts.code, 1) in ('6', '7')")
            ->where('ledger_accounts.is_postable', true)
            ->groupBy('ledger_accounts.id')
            ->selectRaw('ledger_accounts.id AS ledger_account_id')
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) - COALESCE(SUM(journal_lines.credit_minor), 0) AS raw_minor')
            ->orderBy('ledger_accounts.id')
            ->get();

        $balances = [];
        foreach ($rows as $row) {
            $raw = (int) (((array) $row)['raw_minor'] ?? 0);
            if ($raw === 0) {
                continue;
            }

            $balances[] = [
                'ledger_account_id' => (int) (((array) $row)['ledger_account_id'] ?? 0),
                'raw_minor' => $raw,
            ];
        }

        return $balances;
    }

    /**
     * The earliest exercise before $fiscalYear that carries activity and has no
     * posted clôture, or null when the year is the next one due.
     *
     * Years with no activity are skipped rather than demanded: there is nothing
     * to close in them, so requiring a clôture would deadlock — the closing would
     * be refused as empty and the refusal would block every later year.
     */
    private function earliestUnclosedExercise(int $agencyId, string $currency, int $fiscalYear): ?int
    {
        $firstActivity = $this->firstResultActivityDate($agencyId, $currency);
        if ($firstActivity === null) {
            return null;
        }

        for ($year = $this->fiscalYearOf($firstActivity); $year < $fiscalYear; $year++) {
            [$opensOn, $closesOn] = $this->exercisePeriod($year);

            if (! $this->hasResultActivityBetween($agencyId, $currency, $opensOn, $closesOn)) {
                continue;
            }

            $closed = DB::table('exercise_closings')
                ->join('journal_entries', 'journal_entries.id', '=', 'exercise_closings.journal_entry_id')
                ->where('exercise_closings.agency_id', $agencyId)
                ->where('exercise_closings.fiscal_year', $year)
                ->where('exercise_closings.currency', $currency)
                ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
                ->exists();

            if (! $closed) {
                return $year;
            }
        }

        return null;
    }

    /** The exercise a date falls in, named by the calendar year the exercise opens in. */
    private function fiscalYearOf(Carbon $date): int
    {
        $startMonth = $this->fiscalYearStartMonth();

        return $date->month >= $startMonth ? $date->year : $date->year - 1;
    }

    private function fiscalYearStartMonth(): int
    {
        $startMonth = InstitutionProfile::query()->first()?->fiscal_year_start_month;

        return $startMonth === null || $startMonth < 1 || $startMonth > 12 ? 1 : $startMonth;
    }

    private function firstResultActivityDate(int $agencyId, string $currency): ?Carbon
    {
        $earliest = $this->resultActivityQuery($agencyId, $currency)
            ->selectRaw('MIN(journal_entries.business_date) AS earliest')
            ->value('earliest');

        if (! is_string($earliest) || $earliest === '') {
            return null;
        }

        return Carbon::parse($earliest);
    }

    private function hasResultActivityBetween(int $agencyId, string $currency, Carbon $from, Carbon $to): bool
    {
        return $this->resultActivityQuery($agencyId, $currency)
            ->whereDate('journal_entries.business_date', '>=', $from->toDateString())
            ->whereDate('journal_entries.business_date', '<=', $to->toDateString())
            ->exists();
    }

    private function resultActivityQuery(int $agencyId, string $currency): Builder
    {
        return DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_entries.agency_id', $agencyId)
            ->where('journal_lines.currency', $currency)
            ->whereRaw("left(ledger_accounts.code, 1) in ('6', '7')");
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
