<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountingDay;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayException;
use App\Support\AccountingDay\AccountingDayGuard;
use Database\Seeders\BatchProcedureSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use Tests\Traits\OpensAccountingDay;

final class AccountingDayLifecycleTest extends TestCase
{
    use OpensAccountingDay;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_accountant_can_open_close_and_reopen_an_accounting_day(): void
    {
        $agency = $this->createAgency('AD-OPEN');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);

        $open = $this->actingAsSanctum($accountant)->postJson('/api/v1/accounting-days/open', [
            'business_date' => '2026-06-01',
        ]);

        $this->assertJsonSuccess($open, 201);
        $open->assertJsonPath('data.status', AccountingDay::STATUS_OPEN);
        $open->assertJsonPath('data.business_date', '2026-06-01');
        $open->assertJsonPath('data.can_register', true);
        $open->assertJsonMissingPath('data.id');
        $open->assertJsonMissingPath('data.agency_id');

        $publicId = $open->json('data.public_id');
        self::assertIsString($publicId);

        // Idempotent open returns the same active day.
        $openAgain = $this->actingAsSanctum($accountant)->postJson('/api/v1/accounting-days/open', []);
        $this->assertJsonSuccess($openAgain, 200);
        $openAgain->assertJsonPath('data.public_id', $publicId);

        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION');

        $startClose = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$publicId}/start-close");
        $this->assertJsonSuccess($startClose, 200);
        $startClose->assertJsonPath('data.status', AccountingDay::STATUS_CLOSING);
        $startClose->assertJsonPath('data.can_register', false);

        $close = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$publicId}/close");
        $this->assertJsonSuccess($close, 200);
        $close->assertJsonPath('data.status', AccountingDay::STATUS_CLOSED);
        $close->assertJsonPath('data.can_register', false);

        $admin = $this->createUserWithRole('platform-admin', $agency['code']);
        $reopen = $this->actingAsSanctum($admin)->postJson("/api/v1/accounting-days/{$publicId}/reopen", [
            'reason' => 'Correction of a misposted entry approved by finance.',
        ]);
        $this->assertJsonSuccess($reopen, 200);
        $reopen->assertJsonPath('data.status', AccountingDay::STATUS_REOPENED);
        $reopen->assertJsonPath('data.can_register', true);

        $this->assertDatabaseHas('activity_log', ['event' => 'accounting_day.opened']);
        $this->assertDatabaseHas('activity_log', ['event' => 'accounting_day.closed']);
        $this->assertDatabaseHas('activity_log', ['event' => 'accounting_day.reopened']);
    }

    public function test_cannot_open_two_active_days_for_the_same_agency(): void
    {
        $agency = $this->createAgency('AD-DUP');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);

        $this->openAccountingDayForAgency($agency['id'], '2026-06-01');

        $second = $this->actingAsSanctum($accountant)->postJson('/api/v1/accounting-days/open', [
            'business_date' => '2026-06-02',
        ]);

        // Idempotent: the already-open day is returned rather than a duplicate.
        $this->assertJsonSuccess($second, 200);
        $second->assertJsonPath('data.business_date', '2026-06-01');
        self::assertSame(1, AccountingDay::query()->where('agency_id', $agency['id'])->getQuery()->count());
    }

    public function test_missing_accounting_day_fails_closed_in_tests_instead_of_auto_opening(): void
    {
        config(['security.accounting_day.auto_open_on_missing' => false]);

        $agency = $this->createAgency('AD-MISSING');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);

        try {
            app(AccountingDayGuard::class)->assertCanRegister($accountant, 'test.registration', $agency['id']);
            self::fail('Missing accounting day should fail closed.');
        } catch (AccountingDayException $exception) {
            self::assertSame(AccountingDayException::CODE_MISSING, $exception->errorCode);
        }

        self::assertSame(0, AccountingDay::query()->where('agency_id', $agency['id'])->getQuery()->count());
    }

    public function test_database_rejects_two_open_days_per_agency(): void
    {
        $agency = $this->createAgency('AD-RACE');
        $this->openAccountingDayForAgency($agency['id'], '2026-06-01');

        $this->expectException(QueryException::class);

        AccountingDay::query()->create([
            'public_id' => (string) Str::ulid(),
            'scope_type' => AccountingDay::SCOPE_AGENCY,
            'agency_id' => $agency['id'],
            'business_date' => '2026-06-02',
            'status' => AccountingDay::STATUS_OPEN,
            'calendar_opened_at' => now(),
            'origin' => AccountingDay::ORIGIN_MANUAL,
        ]);
    }

    public function test_close_is_blocked_when_unposted_journals_exist(): void
    {
        $agency = $this->createAgency('AD-BLOCK');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);

        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');

        DB::table('journal_entries')->insert([
            'public_id' => (string) Str::ulid(),
            'reference' => 'JE-BLOCK-1',
            'business_date' => '2026-06-01',
            'agency_id' => $agency['id'],
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->setAccountingDayClosing($day);

        $close = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");
        $this->assertJsonError($close, 422);
        $close->assertJsonPath('errors.code', 'accounting_day_close_blocked');
        $close->assertJsonPath('data', null);

        self::assertSame(AccountingDay::STATUS_CLOSING, $day->refresh()->status);
    }

    public function test_reopen_requires_reopen_permission(): void
    {
        $agency = $this->createAgency('AD-PERM');
        $agencyManager = $this->createUserWithRole('agency-manager', $agency['code']);

        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->closeAccountingDay($day);

        $reopen = $this->actingAsSanctum($agencyManager)->postJson("/api/v1/accounting-days/{$day->public_id}/reopen", [
            'reason' => 'Trying to reopen without the elevated permission.',
        ]);

        $reopen->assertStatus(403);
    }

    public function test_reopen_reason_is_only_visible_to_reopen_authorized_users(): void
    {
        $agency = $this->createAgency('AD-REASON');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $admin = $this->createUserWithRole('platform-admin', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->closeAccountingDay($day);

        $reason = 'Sensitive internal correction approval details.';
        $reopen = $this->actingAsSanctum($admin)->postJson("/api/v1/accounting-days/{$day->public_id}/reopen", [
            'reason' => $reason,
        ]);
        $this->assertJsonSuccess($reopen, 200);
        $reopen->assertJsonPath('data.reopen_reason', $reason);

        $viewerResponse = $this->actingAsSanctum($accountant)->getJson("/api/v1/accounting-days/{$day->public_id}");
        $this->assertJsonSuccess($viewerResponse, 200);
        $viewerResponse->assertJsonPath('data.reopen_reason', null);
    }

    public function test_current_returns_consultation_state_after_close(): void
    {
        $agency = $this->createAgency('AD-CUR');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);

        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->closeAccountingDay($day);

        $current = $this->actingAsSanctum($accountant)->getJson('/api/v1/accounting-days/current');
        $this->assertJsonSuccess($current, 200);
        $current->assertJsonPath('data.status', AccountingDay::STATUS_CLOSED);
        $current->assertJsonPath('data.can_register', false);
    }

    public function test_start_close_links_required_close_control_batch_runs_and_persists_summary(): void
    {
        $agency = $this->createAgency('AD-BATCH-LINK');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');

        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION');

        $startClose = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/start-close");
        $this->assertJsonSuccess($startClose, 200);
        $startClose->assertJsonPath('data.status', AccountingDay::STATUS_CLOSING);
        $runPublicId = $startClose->json('data.close_summary.close_control_batch_run_public_ids.0');
        self::assertIsString($runPublicId);
        self::assertNotSame('', $runPublicId);

        $linkedRuns = DB::table('batch_runs')
            ->where('accounting_day_id', $day->id)
            ->whereDate('business_date', '2026-06-01')
            ->count();
        self::assertSame(2, $linkedRuns);

        $close = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");
        $this->assertJsonSuccess($close, 200);
        $close->assertJsonPath('data.status', AccountingDay::STATUS_CLOSED);
    }

    public function test_seeded_procedures_let_start_close_create_close_control_runs(): void
    {
        $this->seed(BatchProcedureSeeder::class);

        $agency = $this->createAgency('AD-SEEDED-CLOSE');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');

        $startClose = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/start-close");
        $this->assertJsonSuccess($startClose, 200);
        $startClose->assertJsonPath('data.status', AccountingDay::STATUS_CLOSING);

        // No missing_procedure: the seeded procedures were resolved and runs linked.
        $failures = $startClose->json('data.close_summary.close_control_batch_failures');
        self::assertIsArray($failures);
        self::assertNotContains('accounting_close_verification', $failures);
        self::assertNotContains('cash_close_verification', $failures);

        self::assertSame(2, DB::table('batch_runs')->where('accounting_day_id', $day->id)->count());
    }

    public function test_start_close_is_blocked_when_close_control_procedures_are_missing(): void
    {
        $agency = $this->createAgency('AD-BATCH-MISS');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');

        $startClose = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/start-close");

        $this->assertJsonError($startClose, 422);
        $startClose->assertJsonPath('errors.code', 'accounting_day_start_close_blocked');
        $startClose->assertJsonPath('errors.blockers.0.control', 'missing_close_control_procedures');
        $startClose->assertJsonPath('errors.blockers.0.procedure_codes.0', 'accounting_close_verification');
        $startClose->assertJsonPath('errors.blockers.0.procedure_codes.1', 'cash_close_verification');

        // The day must remain open so the blocker can be fixed and close retried.
        self::assertSame(AccountingDay::STATUS_OPEN, $day->refresh()->status);
        self::assertSame(0, DB::table('batch_runs')->where('accounting_day_id', $day->id)->count());
    }

    public function test_close_fails_when_required_close_control_procedures_are_missing(): void
    {
        $agency = $this->createAgency('AD-BATCH-MISS2');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');

        // Force the closing state directly: start-close now preflights missing
        // procedures, so this covers a day already trapped before the preflight
        // existed (or procedures deactivated mid-close).
        $this->setAccountingDayClosing($day);

        $close = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");
        $this->assertJsonError($close, 422);
        $close->assertJsonPath('errors.code', 'accounting_day_close_blocked');
        $close->assertJsonPath('errors.close_summary.close_control_batch_failures.0', 'accounting_close_verification');
    }

    public function test_start_close_is_blocked_by_unposted_journals(): void
    {
        $agency = $this->createAgency('AD-PRE-JOURNAL');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION');

        DB::table('journal_entries')->insert([
            'public_id' => (string) Str::ulid(),
            'reference' => 'JE-PRE-1',
            'business_date' => '2026-06-01',
            'agency_id' => $agency['id'],
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $startClose = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/start-close");

        $this->assertJsonError($startClose, 422);
        $startClose->assertJsonPath('errors.code', 'accounting_day_start_close_blocked');
        $startClose->assertJsonPath('errors.blockers.0.control', 'unposted_journals');
        self::assertSame(AccountingDay::STATUS_OPEN, $day->refresh()->status);
    }

    public function test_start_close_is_blocked_by_unreconciled_closed_sessions_until_reconciled(): void
    {
        $agency = $this->createAgency('AD-PRE-RECON');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $teller = $this->createUserWithRole('teller', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $tillId = $this->createTill($agency['id']);
        $sessionId = $this->createTellerSession($day, $tillId, $teller->id, 'closed');
        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION');

        $blocked = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/start-close");

        $this->assertJsonError($blocked, 422);
        $blocked->assertJsonPath('errors.code', 'accounting_day_start_close_blocked');
        $blocked->assertJsonPath('errors.blockers.0.control', 'unreconciled_closed_sessions');
        $blocked->assertJsonPath('errors.blockers.0.count', 1);
        self::assertSame(AccountingDay::STATUS_OPEN, $day->refresh()->status);

        // Recording the balanced reconciliation clears the blocker.
        $this->createBalancedReconciliation($sessionId, $teller->id);

        $startClose = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/start-close");
        $this->assertJsonSuccess($startClose, 200);
        $startClose->assertJsonPath('data.status', AccountingDay::STATUS_CLOSING);
    }

    public function test_cancel_close_returns_closing_day_to_open(): void
    {
        $agency = $this->createAgency('AD-CANCEL');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->setAccountingDayClosing($day);
        $lockBefore = $day->refresh()->write_lock_version;

        $cancel = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/cancel-close");

        $this->assertJsonSuccess($cancel, 200);
        $cancel->assertJsonPath('data.status', AccountingDay::STATUS_OPEN);
        $cancel->assertJsonPath('data.can_register', true);
        self::assertSame($lockBefore + 1, $day->refresh()->write_lock_version);
        $this->assertDatabaseHas('activity_log', ['event' => 'accounting_day.close_cancelled']);

        // Cancelling again is an invalid transition: the day is no longer closing.
        $again = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/cancel-close");
        $this->assertJsonError($again, 422);
        $again->assertJsonPath('errors.code', 'accounting_day_invalid_transition');
    }

    public function test_cancel_close_restores_reopened_status_for_reopened_days(): void
    {
        $agency = $this->createAgency('AD-CANCEL-REOPEN');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $admin = $this->createUserWithRole('platform-admin', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $day->forceFill([
            'status' => AccountingDay::STATUS_CLOSING,
            'reopened_by_user_id' => $admin->id,
            'reopen_reason' => 'Previously reopened for a correction.',
        ])->save();

        $cancel = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/cancel-close");

        $this->assertJsonSuccess($cancel, 200);
        $cancel->assertJsonPath('data.status', AccountingDay::STATUS_REOPENED);
    }

    public function test_cancel_close_requires_close_permission(): void
    {
        $agency = $this->createAgency('AD-CANCEL-PERM');
        $teller = $this->createUserWithRole('teller', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->setAccountingDayClosing($day);

        $cancel = $this->actingAsSanctum($teller)->postJson("/api/v1/accounting-days/{$day->public_id}/cancel-close");

        $cancel->assertStatus(403);
        self::assertSame(AccountingDay::STATUS_CLOSING, $day->refresh()->status);
    }

    public function test_start_close_is_blocked_by_open_teller_sessions_then_succeeds_after_session_closes(): void
    {
        $agency = $this->createAgency('AD-START-DEADLOCK');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $teller = $this->createUserWithRole('teller', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $tillId = $this->createTill($agency['id']);
        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION');

        $sessionId = $this->createTellerSession($day, $tillId, $teller->id, 'open');
        $sessionPublicId = DB::table('teller_sessions')->where('id', $sessionId)->value('public_id');

        $lockBefore = $day->refresh()->write_lock_version;

        $blocked = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/start-close");

        $this->assertJsonError($blocked, 422);
        $blocked->assertJsonPath('errors.code', 'open_teller_sessions');
        $blocked->assertJsonPath('errors.open_teller_sessions_count', 1);
        $blocked->assertJsonPath('errors.open_teller_sessions.0.teller_session_public_id', $sessionPublicId);

        // The day must remain open: no status change, no write-lock bump, no batch runs.
        $refreshed = $day->refresh();
        self::assertSame(AccountingDay::STATUS_OPEN, $refreshed->status);
        self::assertSame($lockBefore, $refreshed->write_lock_version);
        self::assertSame(0, DB::table('batch_runs')->where('accounting_day_id', $day->id)->count());

        // Once the teller session closes and is reconciled, start-close can proceed.
        DB::table('teller_sessions')->where('id', $sessionId)->update([
            'status' => 'closed',
            'closed_at' => now(),
            'closing_declaration_minor' => 0,
            'updated_at' => now(),
        ]);
        $this->createBalancedReconciliation($sessionId, $teller->id);

        $startClose = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/start-close");
        $this->assertJsonSuccess($startClose, 200);
        $startClose->assertJsonPath('data.status', AccountingDay::STATUS_CLOSING);
        self::assertSame($lockBefore + 1, $day->refresh()->write_lock_version);
    }

    public function test_teller_session_close_remains_available_while_day_is_open(): void
    {
        $agency = $this->createAgency('AD-TELLER-OPEN');
        $teller = $this->createUserWithRole('teller', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $tillId = $this->createTill($agency['id']);
        DB::table('tills')->where('id', $tillId)->update(['requires_denominations' => false]);
        $sessionId = $this->createTellerSession($day, $tillId, $teller->id, 'open');
        $sessionPublicId = DB::table('teller_sessions')->where('id', $sessionId)->value('public_id');
        self::assertIsString($sessionPublicId);

        $close = $this->actingAsSanctum($teller)->postJson("/api/v1/teller-sessions/{$sessionPublicId}/close", [
            'closing_declaration_minor' => 0,
        ]);

        $this->assertJsonSuccess($close);
        self::assertSame('closed', DB::table('teller_sessions')->where('id', $sessionId)->value('status'));
    }

    public function test_close_is_blocked_by_open_teller_sessions(): void
    {
        $agency = $this->createAgency('AD-OPEN-SESSION');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $teller = $this->createUserWithRole('teller', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $tillId = $this->createTill($agency['id']);
        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION');

        $this->createTellerSession($day, $tillId, $teller->id, 'open');
        $this->setAccountingDayClosing($day);

        $close = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");

        $this->assertJsonError($close, 422);
        $close->assertJsonPath('errors.close_summary.summary.open_teller_sessions', 1);
        $close->assertJsonPath('errors.close_summary.close_control_batch_failures.0', 'cash_close_verification');
    }

    public function test_close_is_blocked_by_pending_cash_transactions(): void
    {
        $agency = $this->createAgency('AD-PENDING-CASH');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $teller = $this->createUserWithRole('teller', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $tillId = $this->createTill($agency['id']);
        $sessionId = $this->createTellerSession($day, $tillId, $teller->id, 'closed');
        $this->createBalancedReconciliation($sessionId, $teller->id);
        $this->createTellerTransaction($day, $sessionId, $tillId, $agency['id'], 'pending_review');
        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION');
        $this->setAccountingDayClosing($day);

        $close = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");

        $this->assertJsonError($close, 422);
        $close->assertJsonPath('errors.close_summary.summary.pending_teller_transactions', 1);
        $close->assertJsonPath('errors.close_summary.close_control_batch_failures.0', 'cash_close_verification');
    }

    public function test_close_is_blocked_by_unreconciled_closed_sessions_then_succeeds_after_correction(): void
    {
        $agency = $this->createAgency('AD-RETRY-CLOSE');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $teller = $this->createUserWithRole('teller', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $tillId = $this->createTill($agency['id']);
        $sessionId = $this->createTellerSession($day, $tillId, $teller->id, 'closed');
        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION');
        $this->setAccountingDayClosing($day);

        $blocked = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");
        $this->assertJsonError($blocked, 422);
        $blocked->assertJsonPath('errors.close_summary.close_control_batch_failures.0', 'cash_close_verification');
        self::assertSame(AccountingDay::STATUS_CLOSING, $day->refresh()->status);

        $this->createBalancedReconciliation($sessionId, $teller->id);

        $retry = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");
        $this->assertJsonSuccess($retry, 200);
        $retry->assertJsonPath('data.status', AccountingDay::STATUS_CLOSED);

        $duplicate = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");
        $this->assertJsonSuccess($duplicate, 200);
        $duplicate->assertJsonPath('data.status', AccountingDay::STATUS_CLOSED);
    }

    public function test_close_control_batch_runs_follow_execution_priority(): void
    {
        $agency = $this->createAgency('AD-PRIORITY-CLOSE');
        $accountant = $this->createUserWithRole('accountant', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION', 20);
        $this->createBatchProcedure('CASH_CLOSE_VERIFICATION', 1);
        $this->setAccountingDayClosing($day);

        $close = $this->actingAsSanctum($accountant)->postJson("/api/v1/accounting-days/{$day->public_id}/close");

        $this->assertJsonSuccess($close);
        $close->assertJsonPath('data.close_summary.close_control_batches.0.procedure_code', 'cash_close_verification');
        $close->assertJsonPath('data.close_summary.close_control_batches.1.procedure_code', 'accounting_close_verification');
    }

    public function test_close_control_batch_run_cannot_be_manually_marked_succeeded(): void
    {
        $agency = $this->createAgency('AD-SPOOF');
        $admin = $this->createUserWithRole('platform-admin', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $procedurePublicId = $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');

        $createRun = $this->actingAsSanctum($admin)->postJson('/api/v1/batch-runs', [
            'batch_procedure_public_id' => $procedurePublicId,
            'business_date' => '2026-06-01',
            'agency_code' => $agency['code'],
            'accounting_day_public_id' => $day->public_id,
        ]);

        $this->assertJsonSuccess($createRun, 201);
        $runPublicId = $createRun->json('data.public_id');
        self::assertIsString($runPublicId);

        $running = $this->actingAsSanctum($admin)->patchJson('/api/v1/batch-runs/'.$runPublicId.'/status', [
            'status' => 'running',
        ]);
        $this->assertJsonSuccess($running);

        $spoofedSuccess = $this->actingAsSanctum($admin)->patchJson('/api/v1/batch-runs/'.$runPublicId.'/status', [
            'status' => 'succeeded',
            'summary_payload' => ['status' => 'passed'],
        ]);

        $this->assertJsonError(
            $spoofedSuccess,
            422,
            'Close-control batch runs linked to an accounting day must be executed, not manually marked succeeded.'
        );
    }

    public function test_reused_batch_run_is_linked_to_accounting_day(): void
    {
        $agency = $this->createAgency('AD-BATCH-REUSE');
        $admin = $this->createUserWithRole('platform-admin', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $procedurePublicId = $this->createBatchProcedure('ACCOUNTING_CLOSE_VERIFICATION');
        $procedureId = DB::table('batch_procedures')->where('public_id', $procedurePublicId)->value('id');

        $runPublicId = (string) Str::ulid();
        DB::table('batch_runs')->insert([
            'public_id' => $runPublicId,
            'batch_procedure_id' => $procedureId,
            'agency_id' => $agency['id'],
            'accounting_day_id' => null,
            'business_date' => '2026-06-01',
            'status' => 'pending',
            'operator_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAsSanctum($admin)->postJson('/api/v1/batch-runs', [
            'batch_procedure_public_id' => $procedurePublicId,
            'business_date' => '2026-06-01',
            'agency_code' => $agency['code'],
            'accounting_day_public_id' => $day->public_id,
        ]);

        $this->assertJsonSuccess($response, 200);
        $response->assertJsonPath('data.public_id', $runPublicId);
        $response->assertJsonPath('data.accounting_day_public_id', $day->public_id);
        $this->assertDatabaseHas('batch_runs', [
            'public_id' => $runPublicId,
            'accounting_day_id' => $day->id,
        ]);
    }

    public function test_registration_routes_are_blocked_in_consultation_mode(): void
    {
        $agency = $this->createAgency('AD-LOCK');
        $teller = $this->createUserWithRole('teller', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->closeAccountingDay($day);

        $response = $this->actingAsSanctum($teller)
            ->postJson('/api/v1/teller-sessions', []);

        $this->assertJsonError($response, 423);
        $response->assertJsonPath('errors.code', 'accounting_day_closed');
    }

    public function test_representative_registration_routes_across_modules_are_blocked_after_close(): void
    {
        $agency = $this->createAgency('AD-MODULE-LOCK');
        $actor = $this->createUserWithRole('agency-manager', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->closeAccountingDay($day);

        $routes = [
            'CRM client registration' => '/api/v1/clients',
            'Document upload' => '/api/v1/documents',
            'Customer account registration' => '/api/v1/customer-accounts',
            'Account hold registration' => '/api/v1/account-holds',
            'Credit product registration' => '/api/v1/loan-products',
            'Insurance claim registration' => '/api/v1/insurance-claims',
            'FX authorization registration' => '/api/v1/fx-authorizations',
            'HR employee registration' => '/api/v1/hr-employees',
            'Islamic product registration' => '/api/v1/islamic-products',
            'Report run generation' => '/api/v1/report-runs',
        ];

        foreach ($routes as $label => $uri) {
            $response = $this->actingAsSanctum($actor)->postJson($uri, []);

            $this->assertJsonError($response, 423);
            $response->assertJsonPath('errors.code', 'accounting_day_closed');
            $response->assertJsonPath('errors.accounting_day_public_id', $day->public_id);
        }
    }

    public function test_read_only_routes_remain_available_in_consultation_mode(): void
    {
        config(['security.accounting_day.auto_open_on_missing' => false]);

        $agency = $this->createAgency('AD-READ-OK');
        $actor = $this->createUserWithRole('agency-manager', $agency['code']);
        $day = $this->openAccountingDayForAgency($agency['id'], '2026-06-01');
        $this->closeAccountingDay($day);

        // A GET route carrying the registration-lock middleware is a non-mutating
        // consultation and must stay available once the day is closed.
        $response = $this->actingAsSanctum($actor)->getJson('/api/v1/notifications');

        // assertJsonSuccess requires HTTP 200, proving the lock did not block it.
        $this->assertJsonSuccess($response);
    }

    /**
     * @return array{id:int, code:string, public_id:string}
     */
    private function createAgency(string $code): array
    {
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $code.' Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publicId = DB::table('agencies')->where('id', $id)->value('public_id');
        self::assertIsString($publicId);

        return ['id' => $id, 'code' => $code, 'public_id' => $publicId];
    }

    private function createUserWithRole(string $role, string $agencyCode): User
    {
        $agency = DB::table('agencies')->where('code', $agencyCode)->first(['id', 'code', 'name']);
        self::assertIsObject($agency);
        $agencyValues = get_object_vars($agency);
        self::assertIsInt($agencyValues['id'] ?? null);
        self::assertIsString($agencyValues['code'] ?? null);
        self::assertIsString($agencyValues['name'] ?? null);
        $agencyId = $agencyValues['id'];
        $agencyCodeValue = $agencyValues['code'];
        $agencyName = $agencyValues['name'];

        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agencyId,
            'agency_code' => $agencyCodeValue,
            'agency_name' => $agencyName,
        ]);

        $user->assignRole($role);

        DB::table('staff_agency_assignments')->insert([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => $role,
            'starts_on' => now()->toDateString(),
            'is_primary' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }

    private function createBatchProcedure(string $code, ?int $executionPriority = null): string
    {
        $publicId = (string) Str::ulid();

        DB::table('batch_procedures')->insert([
            'public_id' => $publicId,
            'code' => $code,
            'name' => $code,
            'status' => 'active',
            'schedule_type' => 'manual',
            'execution_priority' => $executionPriority,
            'schedule_metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function createTill(int $agencyId): int
    {
        return DB::table('tills')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'TILL-'.Str::random(6),
            'name' => 'Test Till',
            'type' => 'counter',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTellerSession(AccountingDay $day, int $tillId, int $tellerUserId, string $status): int
    {
        return DB::table('teller_sessions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'till_id' => $tillId,
            'agency_id' => $day->agency_id,
            'accounting_day_id' => $day->id,
            'teller_user_id' => $tellerUserId,
            'business_date' => $day->business_date->toDateString(),
            'opened_at' => now(),
            'closed_at' => $status === 'closed' ? now() : null,
            'opening_declaration_minor' => 0,
            'closing_declaration_minor' => $status === 'closed' ? 0 : null,
            'currency' => 'XAF',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createTellerTransaction(AccountingDay $day, int $sessionId, int $tillId, int $agencyId, string $status): int
    {
        return DB::table('teller_transactions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $sessionId,
            'accounting_day_id' => $day->id,
            'agency_id' => $agencyId,
            'transaction_date' => $day->business_date->toDateString(),
            'till_id' => $tillId,
            'transaction_type' => 'cash_deposit',
            'amount_minor' => 1000,
            'currency' => 'XAF',
            'status' => $status,
            'reference' => 'TT-'.Str::ulid(),
            'initiator_type' => 'staff_on_behalf',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createBalancedReconciliation(int $sessionId, int $countedByUserId): int
    {
        return DB::table('till_reconciliations')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'teller_session_id' => $sessionId,
            'counted_by_user_id' => $countedByUserId,
            'counted_at' => now(),
            'reconciliation_date' => now(),
            'theoretical_balance_minor' => 0,
            'actual_balance_minor' => 0,
            'difference_minor' => 0,
            'currency' => 'XAF',
            'status' => 'balanced',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
