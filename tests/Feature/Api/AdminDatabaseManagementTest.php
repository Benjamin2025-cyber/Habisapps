<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreOperation;
use App\Models\User;
use App\Support\DatabaseManagement\Contracts\DatabaseBackupRunner;
use App\Support\DatabaseManagement\Contracts\DatabaseRestoreRunner;
use App\Support\DatabaseManagement\DatabaseMaintenanceLock;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\DatabaseManagement\FakeDatabaseBackupRunner;
use Tests\Support\DatabaseManagement\FakeDatabaseRestoreRunner;
use Tests\TestCase;

final class AdminDatabaseManagementTest extends TestCase
{
    use RefreshDatabase;

    private FakeDatabaseBackupRunner $backupRunner;

    private FakeDatabaseRestoreRunner $restoreRunner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $this->backupRunner = new FakeDatabaseBackupRunner;
        $this->restoreRunner = new FakeDatabaseRestoreRunner;
        $this->app->instance(DatabaseBackupRunner::class, $this->backupRunner);
        $this->app->instance(DatabaseRestoreRunner::class, $this->restoreRunner);

        config([
            'database_management.enabled' => true,
            'database_management.disk' => 'local',
            'database_management.connection' => 'pgsql',
            'database.connections.pgsql.driver' => 'pgsql',
            'database_management.tools.pg_dump' => 'pg_dump',
            'database_management.tools.pg_restore' => 'pg_restore',
            'database_management.run_synchronously' => true,
            'database_management.restore.enabled' => true,
            'database_management.restore.require_pre_restore_backup' => true,
        ]);
    }

    // --- Authorization -----------------------------------------------------

    public function test_listing_backups_requires_authentication(): void
    {
        $this->getJson('/api/v1/database/backups')->assertUnauthorized();
    }

    public function test_non_admin_cannot_list_backups(): void
    {
        $actor = $this->createUserWithRole('teller');

        $this->actingWith($actor)->getJson('/api/v1/database/backups')->assertForbidden();
    }

    public function test_non_admin_cannot_request_backup(): void
    {
        $actor = $this->createUserWithRole('teller');

        $this->actingWith($actor)->postJson('/api/v1/database/backups')->assertForbidden();
    }

    // --- ADM-DB-003: backup creation --------------------------------------

    public function test_platform_admin_can_request_backup_and_it_completes(): void
    {
        $actor = $this->platformAdmin();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups');

        $this->assertJsonSuccess($response, 202);
        $publicId = $this->requireStringJsonPath($response, 'data.backup.public_id');

        $backup = DatabaseBackup::query()->where('public_id', $publicId)->firstOrFail();
        self::assertSame(DatabaseBackup::STATUS_COMPLETED, $backup->status);
        self::assertNotNull($backup->size_bytes);
        self::assertNotNull($backup->checksum_sha256);
        self::assertNotNull($backup->completed_at);
        self::assertTrue(Storage::disk('local')->exists($backup->path));

        $this->assertAuditLogged('database.backup.requested');
        $this->assertAuditLogged('database.backup.started');
        $this->assertAuditLogged('database.backup.completed');
    }

    public function test_duplicate_concurrent_backup_returns_conflict(): void
    {
        $actor = $this->platformAdmin();
        $this->makeBackupRow(DatabaseBackup::STATUS_RUNNING);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups');

        $this->assertJsonError($response, 409);
        $response->assertJsonPath('errors.code', 'backup_already_running');
    }

    public function test_backup_request_conflicts_when_operation_schedule_lock_is_held(): void
    {
        $actor = $this->platformAdmin();
        $lock = Cache::lock('database_management:operation_schedule:pgsql', 10);
        self::assertTrue($lock->get());

        try {
            $response = $this->actingWith($actor)->postJson('/api/v1/database/backups');
        } finally {
            $lock->release();
        }

        $this->assertJsonError($response, 409);
        $response->assertJsonPath('errors.code', 'database_operation_scheduling_locked');
        self::assertSame(0, DatabaseBackup::query()->getQuery()->count());
    }

    public function test_backup_request_returns_503_when_feature_disabled(): void
    {
        config(['database_management.enabled' => false]);
        $actor = $this->platformAdmin();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups');

        $this->assertJsonError($response, 503);
        $response->assertJsonPath('errors.code', 'database_management_disabled');
    }

    public function test_backup_request_returns_422_when_tool_path_missing(): void
    {
        config(['database_management.tools.pg_dump' => '']);
        $actor = $this->platformAdmin();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups');

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_tool_missing');
    }

    public function test_backup_request_returns_422_when_disk_is_public(): void
    {
        config(['database_management.disk' => 'public']);
        $actor = $this->platformAdmin();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups');

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_disk_not_private');
    }

    // --- ADM-DB-004: inventory / detail / download / delete ---------------

    public function test_index_filters_by_status_and_search(): void
    {
        $actor = $this->platformAdmin();
        $completed = $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED);
        $failed = $this->makeBackupRow(DatabaseBackup::STATUS_FAILED);

        $response = $this->actingWith($actor)->getJson('/api/v1/database/backups?status=completed');
        $this->assertJsonSuccess($response);
        $publicIds = array_column($this->arrayJsonPath($response, 'data.backups'), 'public_id');
        self::assertContains($completed->public_id, $publicIds);
        self::assertNotContains($failed->public_id, $publicIds);

        $searchResponse = $this->actingWith($actor)->getJson('/api/v1/database/backups?search='.$completed->public_id);
        $this->assertJsonSuccess($searchResponse);
        $searchIds = array_column($this->arrayJsonPath($searchResponse, 'data.backups'), 'public_id');
        self::assertSame([$completed->public_id], $searchIds);
    }

    public function test_detail_never_exposes_raw_path(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED);
        $backup->forceFill([
            'metadata' => [
                'note' => 'operator note',
                'path' => '/tmp/secret.dump',
                'password' => 'secret',
                'nested' => ['hidden' => true],
            ],
        ])->save();

        $response = $this->actingWith($actor)->getJson('/api/v1/database/backups/'.$backup->public_id);

        $this->assertJsonSuccess($response);
        $payload = $this->arrayJsonPath($response, 'data.backup');
        self::assertArrayNotHasKey('path', $payload);
        $metadata = $this->arrayJsonPath($response, 'data.backup.metadata');
        self::assertSame('operator note', $metadata['note'] ?? null);
        self::assertArrayNotHasKey('path', $metadata);
        self::assertArrayNotHasKey('password', $metadata);
        self::assertArrayNotHasKey('nested', $metadata);
        self::assertArrayHasKey('checksum_sha256', $payload);
        self::assertStringNotContainsString($backup->path, (string) json_encode($response->json()));
        self::assertStringNotContainsString('/tmp/secret.dump', (string) json_encode($response->json()));
        self::assertStringNotContainsString('secret', (string) json_encode($response->json()));
    }

    public function test_download_streams_completed_backup_and_audits(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->get('/api/v1/database/backups/'.$backup->public_id.'/download');

        $response->assertOk();
        self::assertSame('FAKE-PG-DUMP::'.$backup->public_id, $response->streamedContent());
        $this->assertAuditLogged('database.backup.downloaded');
    }

    public function test_download_denied_for_non_completed_backup(): void
    {
        $actor = $this->platformAdmin();
        foreach ([DatabaseBackup::STATUS_PENDING, DatabaseBackup::STATUS_RUNNING, DatabaseBackup::STATUS_FAILED, DatabaseBackup::STATUS_DELETED] as $status) {
            $backup = $this->makeBackupRow($status);
            $response = $this->actingWith($actor)->get('/api/v1/database/backups/'.$backup->public_id.'/download');
            $this->assertJsonError($response, 422);
        }
    }

    public function test_download_denied_when_artifact_missing(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();
        Storage::disk('local')->delete($backup->path);

        $response = $this->actingWith($actor)->get('/api/v1/database/backups/'.$backup->public_id.'/download');

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_artifact_missing');
    }

    public function test_download_denied_when_checksum_mismatches(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();
        Storage::disk('local')->put($backup->path, 'tampered-contents');

        $response = $this->actingWith($actor)->get('/api/v1/database/backups/'.$backup->public_id.'/download');

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_checksum_mismatch');
    }

    public function test_download_requires_download_permission(): void
    {
        $actor = $this->userWithDirectPermissions(['system.database.view']);
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->get('/api/v1/database/backups/'.$backup->public_id.'/download');

        $response->assertForbidden();
    }

    public function test_download_requires_passed_verification_when_strict_verification_enabled(): void
    {
        config(['database_management.strict_verification' => true]);
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->get('/api/v1/database/backups/'.$backup->public_id.'/download');

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_verification_required');
    }

    public function test_delete_tombstones_backup_and_audits(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->deleteJson('/api/v1/database/backups/'.$backup->public_id);

        $this->assertJsonSuccess($response);
        $backup->refresh();
        self::assertSame(DatabaseBackup::STATUS_DELETED, $backup->status);
        self::assertSame($actor->id, $backup->deleted_by_user_id);
        self::assertFalse(Storage::disk('local')->exists($backup->path));
        // Metadata retained as a tombstone row.
        $this->assertDatabaseHas('database_backups', ['id' => $backup->id, 'status' => DatabaseBackup::STATUS_DELETED]);
        $this->assertAuditLogged('database.backup.deleted');
    }

    public function test_delete_denied_for_running_backup(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->makeBackupRow(DatabaseBackup::STATUS_RUNNING);

        $response = $this->actingWith($actor)->deleteJson('/api/v1/database/backups/'.$backup->public_id);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_running');
    }

    public function test_delete_returns_503_when_feature_disabled(): void
    {
        config(['database_management.enabled' => false]);
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->deleteJson('/api/v1/database/backups/'.$backup->public_id);

        $this->assertJsonError($response, 503);
        $response->assertJsonPath('errors.code', 'database_management_disabled');
        self::assertSame(DatabaseBackup::STATUS_COMPLETED, $backup->refresh()->status);
    }

    // --- ADM-DB-005: verification -----------------------------------------

    public function test_verify_passes_for_matching_checksum(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups/'.$backup->public_id.'/verify');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.verification.passed', true);
        $backup->refresh();
        self::assertSame(DatabaseBackup::VERIFICATION_PASSED, $backup->verification_status);
        self::assertSame(DatabaseBackup::STATUS_VERIFIED, $backup->status);
        $this->assertAuditLogged('database.backup.verified');
    }

    public function test_verify_fails_for_missing_file_without_deleting(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();
        Storage::disk('local')->delete($backup->path);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups/'.$backup->public_id.'/verify');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.verification.passed', false);
        $backup->refresh();
        self::assertSame(DatabaseBackup::VERIFICATION_FAILED, $backup->verification_status);
        // Not auto-deleted.
        self::assertNotSame(DatabaseBackup::STATUS_DELETED, $backup->status);
        $this->assertAuditLogged('database.backup.verification_failed');
    }

    public function test_verify_requires_maintenance_permission(): void
    {
        $actor = $this->userWithDirectPermissions(['system.database.view']);
        $backup = $this->completedBackupWithArtifact();

        $this->actingWith($actor)
            ->postJson('/api/v1/database/backups/'.$backup->public_id.'/verify')
            ->assertForbidden();
    }

    public function test_audit_trail_never_records_raw_artifact_path(): void
    {
        $actor = $this->platformAdmin();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups');
        $this->assertJsonSuccess($response, 202);
        $backup = DatabaseBackup::query()
            ->where('public_id', $this->requireStringJsonPath($response, 'data.backup.public_id'))
            ->firstOrFail();

        // Neither the curated security events nor the model activity log may
        // carry the raw storage path (ADM-DB-002/011).
        foreach (DB::table('activity_log')->pluck('properties') as $properties) {
            self::assertStringNotContainsString($backup->path, (string) json_encode($properties));
        }
    }

    public function test_verify_fails_for_mismatched_checksum(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();
        Storage::disk('local')->put($backup->path, 'tampered');

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups/'.$backup->public_id.'/verify');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.verification.passed', false);
    }

    public function test_failed_reverification_demotes_verified_backup_status(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();
        $backup->forceFill([
            'status' => DatabaseBackup::STATUS_VERIFIED,
            'verification_status' => DatabaseBackup::VERIFICATION_PASSED,
            'verified_at' => Carbon::now()->subMinute(),
        ])->save();
        Storage::disk('local')->put($backup->path, 'tampered-after-verification');

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups/'.$backup->public_id.'/verify');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.verification.passed', false);
        $backup->refresh();
        self::assertSame(DatabaseBackup::STATUS_COMPLETED, $backup->status);
        self::assertSame(DatabaseBackup::VERIFICATION_FAILED, $backup->verification_status);
    }

    public function test_verify_denied_for_non_completed_backup(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->makeBackupRow(DatabaseBackup::STATUS_RUNNING);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups/'.$backup->public_id.'/verify');

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_not_verifiable');
    }

    public function test_verify_returns_503_when_feature_disabled(): void
    {
        config(['database_management.enabled' => false]);
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups/'.$backup->public_id.'/verify');

        $this->assertJsonError($response, 503);
        $response->assertJsonPath('errors.code', 'database_management_disabled');
        self::assertNull($backup->refresh()->verification_status);
    }

    // --- ADM-DB-006: restore planning -------------------------------------

    public function test_restore_plan_succeeds_for_dry_run(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_DRY_RUN,
        ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.plan.destructive', false);
        $response->assertJsonPath('data.plan.backup_checksum_sha256', $backup->checksum_sha256);
        self::assertNotEmpty($this->requireStringJsonPath($response, 'data.plan.execution_token'));

        $operationId = $this->requireStringJsonPath($response, 'data.restore_operation.public_id');
        $this->assertDatabaseHas('database_restore_operations', [
            'public_id' => $operationId,
            'status' => DatabaseRestoreOperation::STATUS_PLANNED,
        ]);
        $this->assertAuditLogged('database.restore.planned');
    }

    public function test_restore_plan_denied_for_failed_backup(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->makeBackupRow(DatabaseBackup::STATUS_FAILED);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_DRY_RUN,
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_not_restorable');
    }

    public function test_restore_plan_denied_for_unverified_backup_under_strict_verification(): void
    {
        config(['database_management.strict_verification' => true]);
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact(); // completed, not verified

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_verification_required');
    }

    public function test_restore_plan_denied_when_backup_artifact_is_missing(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();
        Storage::disk('local')->delete($backup->path);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_DRY_RUN,
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_artifact_missing');
    }

    public function test_restore_plan_denied_when_backup_checksum_mismatches(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();
        Storage::disk('local')->put($backup->path, 'tampered-after-completion');

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_DRY_RUN,
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_checksum_mismatch');
    }

    public function test_restore_plan_blocked_when_restore_disabled_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['database_management.restore.enabled' => false]);
        $actor = $this->platformAdmin();
        $backup = $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);

        $this->assertJsonError($response, 503);
        $response->assertJsonPath('errors.code', 'database_restore_disabled');
    }

    public function test_restore_plan_blocks_same_database_replace_in_production(): void
    {
        $this->app['env'] = 'production';
        config(['database_management.restore.allow_same_database_in_production' => false]);
        $actor = $this->platformAdmin();
        $backup = $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'same_database_restore_disabled');
    }

    public function test_restore_plan_rejects_unsupported_replacement_target(): void
    {
        $actor = $this->platformAdmin();
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_STAGING_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'restore_target_unsupported');
        $this->assertDatabaseMissing('database_restore_operations', [
            'database_backup_id' => $backup->id,
            'target' => DatabaseRestoreOperation::TARGET_STAGING_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);
    }

    // --- ADM-DB-007: restore execution ------------------------------------

    public function test_restore_execute_runs_fake_runner_and_takes_pre_restore_backup(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
            'password' => 'password',
        ]);

        $this->assertJsonSuccess($response, 202);
        $operation->refresh();
        self::assertSame(DatabaseRestoreOperation::STATUS_COMPLETED, $operation->status);
        self::assertSame('password', $operation->confirmation_method);
        self::assertNotNull($operation->pre_restore_backup_id);
        self::assertCount(1, $this->restoreRunner->calls);

        $this->assertAuditLogged('database.restore.requested');
        $this->assertAuditLogged('database.restore.started');
        $this->assertAuditLogged('database.restore.completed');
        $this->assertAuditLogged('database.maintenance.locked');
        $this->assertAuditLogged('database.maintenance.unlocked');
        $this->assertDatabaseHas('user_notifications', [
            'category' => 'database_management',
            'title' => 'Database restore started',
            'source_type' => 'database_restore_started',
            'source_public_id' => $operation->public_id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'category' => 'database_management',
            'title' => 'Database restore completed',
            'source_type' => 'database_restore_completed',
            'source_public_id' => $operation->public_id,
        ]);

        // Lock released after a synchronous restore.
        self::assertFalse(app(DatabaseMaintenanceLock::class)->isActive());
    }

    public function test_restore_execute_requires_prior_plan(): void
    {
        $actor = $this->platformAdmin();
        // An operation that is not in the planned state cannot be executed.
        $operation = DatabaseRestoreOperation::query()->create([
            'public_id' => (string) Str::ulid(),
            'database_backup_id' => $this->completedBackupWithArtifact()->id,
            'status' => DatabaseRestoreOperation::STATUS_COMPLETED,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
            'password' => 'password',
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'restore_not_planned');
    }

    public function test_restore_execute_rejects_expired_plan(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);
        $operation->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
            'password' => 'password',
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'restore_plan_expired');
    }

    public function test_restore_execute_blocked_when_restore_is_disabled_after_plan(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);
        config(['database_management.restore.enabled' => false]);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
            'password' => 'password',
        ]);

        $this->assertJsonError($response, 503);
        $response->assertJsonPath('errors.code', 'database_restore_disabled');
        self::assertSame(DatabaseRestoreOperation::STATUS_PLANNED, $operation->refresh()->status);
        self::assertCount(0, $this->restoreRunner->calls);
    }

    public function test_restore_execute_rejects_wrong_password(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
            'password' => 'wrong-password',
        ]);

        $this->assertJsonError($response, 422);
        $operation->refresh();
        self::assertSame(DatabaseRestoreOperation::STATUS_PLANNED, $operation->status);
        self::assertCount(0, $this->restoreRunner->calls);
    }

    public function test_restore_execute_revalidates_artifact_integrity_after_plan(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);
        self::assertNotNull($operation->database_backup_id);
        $backup = DatabaseBackup::findOrFail($operation->database_backup_id);
        Storage::disk('local')->put($backup->path, 'tampered-between-plan-and-execute');

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
            'password' => 'password',
        ]);

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'backup_checksum_mismatch');
        self::assertSame(DatabaseRestoreOperation::STATUS_PLANNED, $operation->refresh()->status);
        self::assertCount(0, $this->restoreRunner->calls);
    }

    public function test_restore_runner_failure_records_bounded_reason_audit_and_notification(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);
        $this->restoreRunner->shouldFail = true;

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
            'password' => 'password',
        ]);

        $this->assertJsonSuccess($response, 202);
        $operation->refresh();
        self::assertSame(DatabaseRestoreOperation::STATUS_FAILED, $operation->status);
        self::assertSame('Simulated restore failure.', $operation->failure_reason);
        self::assertLessThanOrEqual(500, strlen($operation->failure_reason));
        self::assertCount(1, $this->restoreRunner->calls);
        self::assertFalse(app(DatabaseMaintenanceLock::class)->isActive());

        $this->assertAuditLogged('database.restore.failed');
        $this->assertDatabaseHas('user_notifications', [
            'category' => 'database_management',
            'title' => 'Database restore failed',
            'source_type' => 'database_restore_failed',
            'source_public_id' => $operation->public_id,
        ]);
    }

    public function test_restore_execute_conflicts_with_another_active_operation(): void
    {
        $actor = $this->platformAdmin();
        // Another restore already running.
        DatabaseRestoreOperation::query()->create([
            'public_id' => (string) Str::ulid(),
            'database_backup_id' => $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED)->id,
            'status' => DatabaseRestoreOperation::STATUS_RUNNING,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);

        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
            'password' => 'password',
        ]);

        $this->assertJsonError($response, 409);
        $response->assertJsonPath('errors.code', 'database_operation_active');
    }

    public function test_restore_execute_conflicts_when_operation_schedule_lock_is_held(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);
        $lock = Cache::lock('database_management:operation_schedule:pgsql', 10);
        self::assertTrue($lock->get());

        try {
            $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/execute', [
                'password' => 'password',
            ]);
        } finally {
            $lock->release();
        }

        $this->assertJsonError($response, 409);
        $response->assertJsonPath('errors.code', 'database_operation_scheduling_locked');
        self::assertSame(DatabaseRestoreOperation::STATUS_PLANNED, $operation->refresh()->status);
        self::assertCount(0, $this->restoreRunner->calls);
    }

    public function test_backup_request_conflicts_with_active_restore(): void
    {
        $actor = $this->platformAdmin();
        DatabaseRestoreOperation::query()->create([
            'public_id' => (string) Str::ulid(),
            'database_backup_id' => $this->completedBackupWithArtifact()->id,
            'status' => DatabaseRestoreOperation::STATUS_RUNNING,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups');

        $this->assertJsonError($response, 409);
        $response->assertJsonPath('errors.code', 'database_restore_active');
    }

    // --- ADM-DB-008: restore history --------------------------------------

    public function test_restore_history_list_and_detail(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_DRY_RUN);

        $list = $this->actingWith($actor)->getJson('/api/v1/database/restores');
        $this->assertJsonSuccess($list);
        $ids = array_column($this->arrayJsonPath($list, 'data.restore_operations'), 'public_id');
        self::assertContains($operation->public_id, $ids);

        $detail = $this->actingWith($actor)->getJson('/api/v1/database/restores/'.$operation->public_id);
        $this->assertJsonSuccess($detail);
        $detail->assertJsonPath('data.restore_operation.public_id', $operation->public_id);
        $detail->assertJsonPath('data.restore_operation.backup_public_id', $operation->backup?->public_id);
    }

    public function test_restore_can_be_cancelled_before_runner_starts(): void
    {
        $actor = $this->platformAdmin();
        $operation = $this->planRestore($actor, DatabaseRestoreOperation::MODE_REPLACE);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/cancel');

        $this->assertJsonSuccess($response);
        $operation->refresh();
        self::assertSame(DatabaseRestoreOperation::STATUS_CANCELLED, $operation->status);
        $this->assertAuditLogged('database.restore.cancelled');
    }

    public function test_completed_restore_cannot_be_cancelled(): void
    {
        $actor = $this->platformAdmin();
        $operation = DatabaseRestoreOperation::query()->create([
            'public_id' => (string) Str::ulid(),
            'database_backup_id' => $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED)->id,
            'status' => DatabaseRestoreOperation::STATUS_COMPLETED,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => DatabaseRestoreOperation::MODE_REPLACE,
        ]);

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/'.$operation->public_id.'/cancel');

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.code', 'restore_not_cancellable');
    }

    // --- ADM-DB-009: storage & retention ----------------------------------

    public function test_storage_status_reports_inventory_without_secrets(): void
    {
        $actor = $this->platformAdmin();
        $this->completedBackupWithArtifact();
        app(DatabaseMaintenanceLock::class)->engage(
            ownerPublicId: $actor->public_id,
            ownerName: $actor->name,
            reason: 'Database restore in progress',
            restorePublicId: 'restore-lock-public-id',
        );

        $response = $this->actingWith($actor)->getJson('/api/v1/database/storage');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.storage.disk', 'local');
        $response->assertJsonPath('data.storage.is_private', true);
        $response->assertJsonPath('data.storage.maintenance_lock.owner_public_id', $actor->public_id);
        $response->assertJsonPath('data.storage.maintenance_lock.reason', 'Database restore in progress');
        $response->assertJsonPath('data.storage.maintenance_lock.restore_public_id', 'restore-lock-public-id');
        $storage = $this->arrayJsonPath($response, 'data.storage');
        self::assertArrayHasKey('retention_policy', $storage);
        self::assertArrayHasKey('backup_count', $storage);
        self::assertArrayHasKey('maintenance_lock', $storage);
        // No absolute filesystem path is leaked anywhere in the payload.
        self::assertStringNotContainsString(storage_path(), (string) json_encode($response->json()));
    }

    public function test_retention_dry_run_reports_candidates_without_deleting(): void
    {
        config([
            'database_management.retention.max_age_days' => 1,
            'database_management.retention.min_protected' => 0,
            'database_management.retention.max_count' => 0,
            'database_management.retention.keep_last_verified' => false,
        ]);
        $actor = $this->platformAdmin();
        $old = $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(10));

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups/retention/run', ['dry_run' => true]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.dry_run', true);
        $candidateIds = array_column($this->arrayJsonPath($response, 'data.candidates'), 'public_id');
        self::assertContains($old->public_id, $candidateIds);
        // Not deleted.
        self::assertSame(DatabaseBackup::STATUS_COMPLETED, $old->refresh()->status);
    }

    public function test_retention_run_deletes_expired_and_preserves_minimum(): void
    {
        config([
            'database_management.retention.max_age_days' => 1,
            'database_management.retention.min_protected' => 1,
            'database_management.retention.max_count' => 0,
            'database_management.retention.keep_last_verified' => false,
        ]);
        $actor = $this->platformAdmin();
        $newest = $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(2));
        $older = $this->makeBackupRow(DatabaseBackup::STATUS_COMPLETED, Carbon::now()->subDays(10));

        $response = $this->actingWith($actor)->postJson('/api/v1/database/backups/retention/run', ['dry_run' => false]);

        $this->assertJsonSuccess($response);
        self::assertSame(DatabaseBackup::STATUS_DELETED, $older->refresh()->status);
        self::assertSame(DatabaseBackup::STATUS_COMPLETED, $newest->refresh()->status);
        $this->assertAuditLogged('database.retention.run');
    }

    public function test_retention_run_requires_maintenance_permission(): void
    {
        $actor = $this->createUserWithRole('teller');

        $this->actingWith($actor)
            ->postJson('/api/v1/database/backups/retention/run', ['dry_run' => true])
            ->assertForbidden();
    }

    // --- ADM-DB-010: maintenance lock -------------------------------------

    public function test_active_restore_lock_blocks_registration_writes_but_allows_reads(): void
    {
        $actor = $this->platformAdmin();

        app(DatabaseMaintenanceLock::class)->engage(
            ownerPublicId: $actor->public_id,
            ownerName: $actor->name,
            reason: 'Database restore in progress',
            restorePublicId: 'restore-test',
        );

        // Registration write is refused with a clear 503.
        $blocked = $this->actingWith($actor)->postJson('/api/v1/clients', []);
        $blocked->assertStatus(503);
        $blocked->assertJsonPath('errors.code', 'database_restore_in_progress');

        // Read-only status endpoints stay available.
        $this->actingWith($actor)->getJson('/api/v1/database/storage')->assertOk();

        // After release, registration is no longer blocked by the lock.
        app(DatabaseMaintenanceLock::class)->release();
        $afterRelease = $this->actingWith($actor)->postJson('/api/v1/clients', []);
        self::assertNotSame('database_restore_in_progress', $afterRelease->json('errors.code'));
    }

    // --- Helpers ----------------------------------------------------------

    private function platformAdmin(): User
    {
        return $this->createUserWithRole('platform-admin');
    }

    private function createUserWithRole(string $role): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWithDirectPermissions(array $permissions): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function actingWith(User $actor): self
    {
        return $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('db-test')->plainTextToken,
        ]);
    }

    private function makeBackupRow(string $status, ?Carbon $createdAt = null): DatabaseBackup
    {
        $publicId = (string) Str::ulid();
        $backup = DatabaseBackup::query()->create([
            'public_id' => $publicId,
            'filename' => 'backup_'.$publicId.'.dump',
            'disk' => 'local',
            'path' => 'database-backups/backup_'.$publicId.'.dump',
            'status' => $status,
            'database_connection' => 'pgsql',
            'database_driver' => 'pgsql',
            'size_bytes' => 2048,
            'checksum_sha256' => hash('sha256', $publicId),
        ]);

        if ($createdAt !== null) {
            $backup->forceFill(['created_at' => $createdAt])->save();
        }

        return $backup->refresh();
    }

    private function completedBackupWithArtifact(): DatabaseBackup
    {
        $publicId = (string) Str::ulid();
        $path = 'database-backups/backup_'.$publicId.'.dump';
        $contents = 'FAKE-PG-DUMP::'.$publicId;
        Storage::disk('local')->put($path, $contents);

        return DatabaseBackup::query()->create([
            'public_id' => $publicId,
            'filename' => 'backup_'.$publicId.'.dump',
            'disk' => 'local',
            'path' => $path,
            'status' => DatabaseBackup::STATUS_COMPLETED,
            'database_connection' => 'pgsql',
            'database_driver' => 'pgsql',
            'size_bytes' => strlen($contents),
            'checksum_sha256' => hash('sha256', $contents),
            'completed_at' => Carbon::now(),
        ]);
    }

    private function planRestore(User $actor, string $mode): DatabaseRestoreOperation
    {
        $backup = $this->completedBackupWithArtifact();

        $response = $this->actingWith($actor)->postJson('/api/v1/database/restores/plan', [
            'backup_public_id' => $backup->public_id,
            'target' => DatabaseRestoreOperation::TARGET_SAME_DATABASE,
            'mode' => $mode,
        ]);

        $this->assertJsonSuccess($response, 201);
        $publicId = $this->requireStringJsonPath($response, 'data.restore_operation.public_id');

        return DatabaseRestoreOperation::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function assertAuditLogged(string $event): void
    {
        $this->assertDatabaseHas('activity_log', ['event' => $event]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function arrayJsonPath(TestResponse $response, string $path): array
    {
        $value = $response->json($path);
        self::assertIsArray($value);

        return $value;
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
