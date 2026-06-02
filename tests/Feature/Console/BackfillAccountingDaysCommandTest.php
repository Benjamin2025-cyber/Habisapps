<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\AccountingDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

final class BackfillAccountingDaysCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_command_creates_historical_days_and_links_legacy_rows_across_supported_tables(): void
    {
        $agencyId = $this->createAgency('BF-AG-01');
        $userId = $this->createUser('backfill-teller@example.test');
        $tillId = $this->createTill($agencyId);
        $batchProcedureId = $this->createBatchProcedure('BF_DAILY_CONTROL');
        $existingDay = AccountingDay::factory()->closed()->create([
            'agency_id' => $agencyId,
            'business_date' => '2026-05-28',
            'origin' => AccountingDay::ORIGIN_MIGRATION,
        ]);

        $journalId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'BF-JE-001',
            'agency_id' => $agencyId,
            'business_date' => '2026-05-28',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sessionId = DB::table('teller_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'till_id' => $tillId,
            'agency_id' => $agencyId,
            'teller_user_id' => $userId,
            'business_date' => '2026-05-28',
            'opened_at' => '2026-05-28 08:00:00',
            'closed_at' => '2026-05-28 17:00:00',
            'opening_declaration_minor' => 100000,
            'closing_declaration_minor' => 100000,
            'currency' => 'XAF',
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batchRunId = DB::table('batch_runs')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $batchProcedureId,
            'agency_id' => $agencyId,
            'business_date' => '2026-05-28',
            'status' => 'succeeded',
            'started_at' => '2026-05-28 18:00:00',
            'finished_at' => '2026-05-28 18:05:00',
            'operator_user_id' => $userId,
            'actor_context' => 'user:'.$userId,
            'scope_hash' => hash('sha256', 'agency-batch-run'),
            'summary_payload' => json_encode(['legacy' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transactionId = DB::table('teller_transactions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $sessionId,
            'agency_id' => $agencyId,
            'transaction_date' => null,
            'transaction_type' => 'deposit',
            'amount_minor' => 25000,
            'currency' => 'XAF',
            'status' => 'posted',
            'reference' => 'BF-TX-001',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $command = $this->artisan('app:backfill-accounting-days');
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0);
        $command->run();

        $dayQuery = AccountingDay::query()
            ->where('scope_type', AccountingDay::SCOPE_AGENCY)
            ->where('agency_id', $agencyId);
        $dayQuery->getQuery()->whereDate('business_date', '2026-05-28');
        $day = $dayQuery->first();

        self::assertInstanceOf(AccountingDay::class, $day);
        self::assertSame($existingDay->id, $day->id);
        self::assertSame(AccountingDay::STATUS_CLOSED, $day->status);
        self::assertSame(AccountingDay::ORIGIN_MIGRATION, $day->origin);

        self::assertSame($day->id, DB::table('journal_entries')->where('id', $journalId)->value('accounting_day_id'));
        self::assertSame($day->id, DB::table('teller_sessions')->where('id', $sessionId)->value('accounting_day_id'));
        self::assertSame($day->id, DB::table('batch_runs')->where('id', $batchRunId)->value('accounting_day_id'));
        self::assertSame($day->id, DB::table('teller_transactions')->where('id', $transactionId)->value('accounting_day_id'));
    }

    public function test_backfill_command_creates_institution_days_for_global_batch_runs_with_migration_metadata(): void
    {
        $batchProcedureId = $this->createBatchProcedure('BF_GLOBAL_CONTROL');

        $batchRunId = DB::table('batch_runs')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'batch_procedure_id' => $batchProcedureId,
            'agency_id' => null,
            'business_date' => '2026-05-29',
            'status' => 'succeeded',
            'started_at' => '2026-05-29 18:00:00',
            'finished_at' => '2026-05-29 18:05:00',
            'actor_context' => 'system',
            'scope_hash' => hash('sha256', 'institution-batch-run'),
            'summary_payload' => json_encode(['legacy' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $command = $this->artisan('app:backfill-accounting-days');
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0);
        $command->run();

        $dayQuery = AccountingDay::query()->where('scope_type', AccountingDay::SCOPE_INSTITUTION);
        $dayQuery->getQuery()
            ->whereNull('agency_id')
            ->whereDate('business_date', '2026-05-29');
        $day = $dayQuery->first();

        self::assertInstanceOf(AccountingDay::class, $day);
        self::assertSame(AccountingDay::STATUS_CLOSED, $day->status);
        self::assertSame(AccountingDay::ORIGIN_MIGRATION, $day->origin);
        self::assertSame($day->id, DB::table('batch_runs')->where('id', $batchRunId)->value('accounting_day_id'));

        $summary = $day->close_summary_payload;
        self::assertIsArray($summary);
        $migration = $summary['migration'] ?? null;
        self::assertIsArray($migration);
        self::assertSame('historical_backfill', $migration['source'] ?? null);
        self::assertIsString($migration['batch_id'] ?? null);
        self::assertNotSame('', $migration['batch_id']);
    }

    public function test_backfill_dry_run_reports_work_without_creating_days_or_linking_rows(): void
    {
        $agencyId = $this->createAgency('BF-AG-DRY');

        $journalId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'BF-JE-DRY',
            'agency_id' => $agencyId,
            'business_date' => '2026-05-30',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $command = $this->artisan('app:backfill-accounting-days', ['--dry-run' => true]);
        self::assertInstanceOf(PendingCommand::class, $command);
        $command
            ->expectsOutputToContain('DRY-RUN MODE')
            ->assertExitCode(0);
        $command->run();

        $dayQuery = AccountingDay::query()->where('agency_id', $agencyId);
        $dayQuery->getQuery()->whereDate('business_date', '2026-05-30');
        self::assertFalse($dayQuery->getQuery()->exists());
        self::assertNull(DB::table('journal_entries')->where('id', $journalId)->value('accounting_day_id'));
    }

    public function test_backfill_strict_mode_passes_when_all_legacy_rows_are_mappable(): void
    {
        $agencyId = $this->createAgency('BF-AG-ST');

        DB::table('journal_entries')->insert([
            'public_id' => (string) Str::ulid(),
            'reference' => 'BF-JE-ST',
            'agency_id' => $agencyId,
            'business_date' => '2026-05-31',
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $command = $this->artisan('app:backfill-accounting-days', ['--strict' => true]);
        self::assertInstanceOf(PendingCommand::class, $command);
        $command->assertExitCode(0);
        $command->run();
    }

    private function createAgency(string $code): int
    {
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => 'Backfill Agency '.$code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createUser(string $email): int
    {
        $id = DB::table('users')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'name' => 'Backfill Teller',
            'phone_number' => '+237'.random_int(600000000, 699999999),
            'email' => $email,
            'password' => bcrypt('password'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createTill(int $agencyId): int
    {
        $id = DB::table('tills')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'BF-TILL-'.Str::upper(Str::random(4)),
            'name' => 'Backfill Till',
            'type' => 'counter',
            'status' => 'active',
            'currency' => 'XAF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createBatchProcedure(string $code): int
    {
        $id = DB::table('batch_procedures')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => 'Backfill Procedure '.$code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
