<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccountingDay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('app:backfill-accounting-days {--dry-run : Simulate the backfill without persisting} {--strict : Fail if any rows cannot be mapped}')]
#[Description('Create historical accounting days and backfill accounting_day_id links on legacy rows')]
final class BackfillAccountingDays extends Command
{
    private string $batchId = '';

    /**
     * @var array<string, int>
     */
    private array $dayCache = [];

    /**
     * @var array<string, int>
     */
    private array $counts = [
        'days_created' => 0,
        'days_reused' => 0,
        'journal_entries_updated' => 0,
        'teller_sessions_updated' => 0,
        'batch_runs_updated' => 0,
        'teller_transactions_updated' => 0,
    ];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $exceptions = [];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $strict = (bool) $this->option('strict');
        $this->batchId = (string) Str::ulid();

        $this->info('Starting accounting-day historical backfill...');
        $this->line('Backfill batch ID: '.$this->batchId);
        if ($dryRun) {
            $this->warn('DRY-RUN MODE: no database writes will be committed.');
        }

        $keys = $this->collectScopeDateKeys();
        $this->line('Found '.count($keys).' distinct scope/date keys to map.');
        foreach ($keys as $key) {
            $this->resolveOrCreateDay($key['scope_type'], $key['agency_id'], $key['business_date'], $dryRun);
        }

        $this->backfillJournalEntries($dryRun);
        $this->backfillTellerSessions($dryRun);
        $this->backfillBatchRuns($dryRun);
        $this->backfillTellerTransactions($dryRun);

        $this->table(
            ['metric', 'count'],
            array_map(
                static fn (string $metric, int $count): array => [$metric, (string) $count],
                array_keys($this->counts),
                array_values($this->counts),
            )
        );

        if ($this->exceptions !== []) {
            $this->warn('Exceptions found: '.count($this->exceptions));
            $preview = array_slice($this->exceptions, 0, 20);
            $this->table(
                ['table', 'id', 'reason', 'agency_id', 'business_date'],
                array_map(
                    static fn (array $row): array => [
                        (string) ($row['table'] ?? ''),
                        (string) ($row['id'] ?? ''),
                        (string) ($row['reason'] ?? ''),
                        (string) ($row['agency_id'] ?? ''),
                        (string) ($row['business_date'] ?? ''),
                    ],
                    $preview,
                )
            );
        }

        if ($strict && $this->exceptions !== []) {
            $this->error('Strict mode enabled and unmapped rows were found.');

            return self::FAILURE;
        }

        $this->info('Accounting-day backfill completed.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{scope_type:string, agency_id:int|null, business_date:string}>
     */
    private function collectScopeDateKeys(): array
    {
        $keys = [];

        $append = function (?int $agencyId, ?string $businessDate) use (&$keys): void {
            if (! is_string($businessDate) || trim($businessDate) === '') {
                return;
            }

            $scopeType = $agencyId === null ? AccountingDay::SCOPE_INSTITUTION : AccountingDay::SCOPE_AGENCY;
            $cacheKey = $scopeType.'|'.($agencyId === null ? 'null' : (string) $agencyId).'|'.$businessDate;
            if (isset($keys[$cacheKey])) {
                return;
            }

            $keys[$cacheKey] = [
                'scope_type' => $scopeType,
                'agency_id' => $agencyId,
                'business_date' => $businessDate,
            ];
        };

        DB::table('journal_entries')
            ->select(['agency_id', 'business_date'])
            ->whereNotNull('business_date')
            ->distinct()
            ->orderBy('business_date')
            ->get()
            ->each(static function (object $row) use ($append): void {
                $append(is_numeric($row->agency_id) ? (int) $row->agency_id : null, is_string($row->business_date) ? $row->business_date : null);
            });

        DB::table('teller_sessions')
            ->select(['agency_id', 'business_date'])
            ->whereNotNull('business_date')
            ->distinct()
            ->orderBy('business_date')
            ->get()
            ->each(static function (object $row) use ($append): void {
                $append(is_numeric($row->agency_id) ? (int) $row->agency_id : null, is_string($row->business_date) ? $row->business_date : null);
            });

        DB::table('batch_runs')
            ->select(['agency_id', 'business_date'])
            ->whereNotNull('business_date')
            ->distinct()
            ->orderBy('business_date')
            ->get()
            ->each(static function (object $row) use ($append): void {
                $append(is_numeric($row->agency_id) ? (int) $row->agency_id : null, is_string($row->business_date) ? $row->business_date : null);
            });

        DB::table('teller_transactions')
            ->select(['agency_id', 'transaction_date'])
            ->whereNotNull('transaction_date')
            ->distinct()
            ->orderBy('transaction_date')
            ->get()
            ->each(static function (object $row) use ($append): void {
                $append(is_numeric($row->agency_id) ? (int) $row->agency_id : null, is_string($row->transaction_date) ? $row->transaction_date : null);
            });

        return array_values($keys);
    }

    private function resolveOrCreateDay(string $scopeType, ?int $agencyId, string $businessDate, bool $dryRun): ?int
    {
        $cacheKey = $scopeType.'|'.($agencyId === null ? 'null' : (string) $agencyId).'|'.$businessDate;
        if (array_key_exists($cacheKey, $this->dayCache)) {
            return $this->dayCache[$cacheKey];
        }

        $existing = DB::table('accounting_days')
            ->where('scope_type', $scopeType)
            ->when(
                $scopeType === AccountingDay::SCOPE_AGENCY,
                fn ($query) => $query->where('agency_id', $agencyId),
                fn ($query) => $query->whereNull('agency_id'),
            )
            ->whereDate('business_date', $businessDate)
            ->value('id');

        if (is_numeric($existing)) {
            $this->counts['days_reused']++;

            return $this->dayCache[$cacheKey] = (int) $existing;
        }

        if ($dryRun) {
            $this->counts['days_created']++;

            return $this->dayCache[$cacheKey] = 0;
        }

        $openedAt = Carbon::parse($businessDate)->startOfDay();
        $closedAt = Carbon::parse($businessDate)->endOfDay();

        $id = DB::table('accounting_days')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'scope_type' => $scopeType,
            'agency_id' => $agencyId,
            'business_date' => $businessDate,
            'calendar_opened_at' => $openedAt,
            'calendar_closed_at' => $closedAt,
            'status' => AccountingDay::STATUS_CLOSED,
            'is_holiday' => false,
            'holiday_name' => null,
            'close_summary_payload' => json_encode([
                'migration' => [
                    'batch_id' => $this->batchId,
                    'source' => 'historical_backfill',
                ],
            ]),
            'close_failure_reason' => null,
            'reopen_reason' => null,
            'origin' => AccountingDay::ORIGIN_MIGRATION,
            'write_lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->counts['days_created']++;

        return $this->dayCache[$cacheKey] = is_int($id) ? $id : (int) $id;
    }

    private function backfillJournalEntries(bool $dryRun): void
    {
        DB::table('journal_entries')
            ->whereNull('accounting_day_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($dryRun): void {
                foreach ($rows as $row) {
                    $this->mapRowToDay('journal_entries', $row, 'business_date', $dryRun, 'journal_entries_updated');
                }
            });
    }

    private function backfillTellerSessions(bool $dryRun): void
    {
        DB::table('teller_sessions')
            ->whereNull('accounting_day_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($dryRun): void {
                foreach ($rows as $row) {
                    $this->mapRowToDay('teller_sessions', $row, 'business_date', $dryRun, 'teller_sessions_updated');
                }
            });
    }

    private function backfillBatchRuns(bool $dryRun): void
    {
        DB::table('batch_runs')
            ->whereNull('accounting_day_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($dryRun): void {
                foreach ($rows as $row) {
                    $this->mapRowToDay('batch_runs', $row, 'business_date', $dryRun, 'batch_runs_updated');
                }
            });
    }

    private function backfillTellerTransactions(bool $dryRun): void
    {
        DB::table('teller_transactions')
            ->leftJoin('teller_sessions', 'teller_transactions.teller_session_id', '=', 'teller_sessions.id')
            ->whereNull('teller_transactions.accounting_day_id')
            ->orderBy('teller_transactions.id')
            ->select([
                'teller_transactions.id as id',
                'teller_transactions.agency_id',
                'teller_transactions.transaction_date',
                'teller_sessions.business_date as session_business_date',
                'teller_sessions.accounting_day_id as session_accounting_day_id',
            ])
            ->chunkById(500, function ($rows) use ($dryRun): void {
                foreach ($rows as $row) {
                    $sessionDayId = is_numeric($row->session_accounting_day_id) ? (int) $row->session_accounting_day_id : null;
                    if ($sessionDayId !== null) {
                        if (! $dryRun) {
                            DB::table('teller_transactions')->where('id', $row->id)->update([
                                'accounting_day_id' => $sessionDayId,
                                'updated_at' => now(),
                            ]);
                        }
                        $this->counts['teller_transactions_updated']++;

                        continue;
                    }

                    $businessDate = is_string($row->session_business_date) && $row->session_business_date !== ''
                        ? $row->session_business_date
                        : (is_string($row->transaction_date) ? $row->transaction_date : null);

                    $this->mapRowToDay('teller_transactions', $row, null, $dryRun, 'teller_transactions_updated', $businessDate);
                }
            }, 'teller_transactions.id', 'id');
    }

    private function mapRowToDay(
        string $table,
        object $row,
        ?string $dateColumn,
        bool $dryRun,
        string $counterKey,
        ?string $overrideDate = null,
    ): void {
        $agencyId = property_exists($row, 'agency_id') && is_numeric($row->agency_id) ? (int) $row->agency_id : null;
        $businessDate = $overrideDate;
        if ($businessDate === null && $dateColumn !== null) {
            $value = $row->{$dateColumn} ?? null;
            $businessDate = is_string($value) ? $value : null;
        }

        if (! is_string($businessDate) || trim($businessDate) === '') {
            $this->exceptions[] = [
                'table' => $table,
                'id' => $row->id ?? null,
                'reason' => 'missing_business_date',
                'agency_id' => $agencyId,
                'business_date' => null,
            ];

            return;
        }

        $dayId = $this->resolveOrCreateDay(
            $agencyId === null ? AccountingDay::SCOPE_INSTITUTION : AccountingDay::SCOPE_AGENCY,
            $agencyId,
            $businessDate,
            $dryRun
        );

        if (! is_int($dayId) || $dayId <= 0) {
            $this->counts[$counterKey]++;

            return;
        }

        if (! $dryRun) {
            DB::table($table)->where('id', $row->id)->update([
                'accounting_day_id' => $dayId,
                'updated_at' => now(),
            ]);
        }

        $this->counts[$counterKey]++;
    }
}
