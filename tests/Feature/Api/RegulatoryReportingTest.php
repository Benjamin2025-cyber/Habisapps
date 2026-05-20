<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Reporting\MappingCompletenessGate;
use App\Models\LedgerAccount;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Tests\TestCase;

final class RegulatoryReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_regulatory_source_can_be_registered_with_checksum(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/regulatory-sources', [
                'authority' => 'cobac',
                'reference' => 'COBAC-EMF-R-2010/01',
                'title' => 'Plan Comptable des EMF',
                'effective_date' => '2010-01-01',
                'checksum' => str_repeat('a', 64),
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.authority', 'cobac');
        $response->assertJsonPath('data.reference', 'COBAC-EMF-R-2010/01');
        $this->assertDatabaseHas('regulatory_sources', [
            'authority' => 'cobac',
            'reference' => 'COBAC-EMF-R-2010/01',
        ]);
    }

    public function test_regulatory_source_rejects_duplicate_triple(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $payload = [
            'authority' => 'beac',
            'reference' => 'BEAC-INSTRUCTION-011-GR-2019',
            'title' => 'Conditions du change manuel',
            'effective_date' => '2019-06-10',
            'checksum' => str_repeat('b', 64),
        ];
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/regulatory-sources', $payload), 201);

        $dup = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/regulatory-sources', $payload);
        $this->assertJsonError($dup, 422);
    }

    public function test_emf_account_loader_imports_parent_child_hierarchy(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $sourcePublicId = $this->createSource($actor);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/regulatory-sources/'.$sourcePublicId.'/emf-accounts', [
                'accounts' => [
                    ['code' => 'CL1', 'name' => 'Class 1 — Capitaux propres', 'account_class' => 'liability'],
                    ['code' => 'CL1-101', 'name' => 'Capital social', 'parent_code' => 'CL1'],
                    ['code' => 'CL2', 'name' => 'Class 2 — Immobilisations', 'account_class' => 'asset'],
                ],
            ]);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.imported_count', 3);

        $child = DB::table('emf_regulatory_accounts')->where('code', 'CL1-101')->first();
        self::assertIsObject($child);
        self::assertNotNull(((array) $child)['parent_emf_regulatory_account_id']);
    }

    public function test_emf_account_loader_rejects_duplicate_code(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $sourcePublicId = $this->createSource($actor);

        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/regulatory-sources/'.$sourcePublicId.'/emf-accounts', [
                'accounts' => [['code' => 'DUP', 'name' => 'Original']],
            ]), 201);

        $dup = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/regulatory-sources/'.$sourcePublicId.'/emf-accounts', [
                'accounts' => [['code' => 'DUP', 'name' => 'Duplicate']],
            ]);
        $this->assertJsonError($dup, 422);
    }

    public function test_report_definition_create_increments_version_per_code(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $sourcePublicId = $this->createSource($actor);

        $v1 = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-definitions', [
                'code' => 'COBAC-MONTHLY',
                'name' => 'COBAC Monthly Statement',
                'report_type' => 'emf_trial_balance',
                'module' => 'reporting',
                'regulatory_source_public_id' => $sourcePublicId,
                'definition' => [
                    'lines' => [
                        ['source' => 'emf_regulatory_balance', 'fields' => ['emf_code', 'debit_total_minor', 'credit_total_minor']],
                    ],
                ],
            ]);
        $this->assertJsonSuccess($v1, 201);
        $v1->assertJsonPath('data.version', 1);

        $v2 = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-definitions', [
                'code' => 'COBAC-MONTHLY',
                'name' => 'COBAC Monthly Statement v2',
                'report_type' => 'emf_trial_balance',
                'module' => 'reporting',
                'regulatory_source_public_id' => $sourcePublicId,
            ]);
        $this->assertJsonSuccess($v2, 201);
        $v2->assertJsonPath('data.version', 2);

        self::assertSame(2, DB::table('report_definitions')->where('code', 'COBAC-MONTHLY')->count());
    }

    public function test_report_definition_requires_source_and_rejects_raw_table_definition(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $sourcePublicId = $this->createSource($actor);

        $missingSource = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-definitions', [
                'code' => 'COBAC-NO-SOURCE',
                'name' => 'No Source',
                'report_type' => 'emf_trial_balance',
            ]);
        $this->assertJsonError($missingSource, 422);

        $rawTable = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-definitions', [
                'code' => 'COBAC-RAW',
                'name' => 'Raw Table',
                'report_type' => 'emf_trial_balance',
                'regulatory_source_public_id' => $sourcePublicId,
                'definition' => [
                    'lines' => [
                        ['source' => 'ledger_balance', 'table' => 'journal_lines', 'field' => 'debit_total_minor'],
                    ],
                ],
            ]);
        $this->assertJsonError($rawTable, 422);
    }

    public function test_mapping_completeness_gate_blocks_when_mapping_missing(): void
    {
        $gate = app(MappingCompletenessGate::class);
        $agency = $this->createAgency('REG01');

        // No op code yet → fails
        $description = $gate->describe('nonexistent_op', $agency['id'], 'XAF');
        self::assertFalse($description['ready_for_posting']);
        self::assertStringContainsString('Operation code does not exist', $description['reason']);

        // Op code without mapping → fails
        DB::table('operation_codes')->insert([
            'public_id' => (string) Str::ulid(),
            'code' => 'naked_op',
            'label' => 'Naked op',
            'module' => 'test',
            'operation_type' => 'test',
            'direction' => 'mixed',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $description = $gate->describe('naked_op', $agency['id'], 'XAF');
        self::assertFalse($description['ready_for_posting']);
        self::assertStringContainsString('No active operation mapping', $description['reason']);

        $this->expectException(InvalidArgumentException::class);
        $gate->assertReadyForPosting('naked_op', $agency['id'], 'XAF');
    }

    public function test_mapping_completeness_gate_passes_when_mapping_present(): void
    {
        $gate = app(MappingCompletenessGate::class);
        $agency = $this->createAgency('REG02');
        $debit = $this->createLedgerAccount($agency['id']);
        $credit = $this->createLedgerAccount($agency['id']);

        $opCodeId = DB::table('operation_codes')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'full_op',
            'label' => 'Full op',
            'module' => 'test',
            'operation_type' => 'test',
            'direction' => 'mixed',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('operation_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $opCodeId,
            'debit_ledger_account_id' => $debit['id'],
            'credit_ledger_account_id' => $credit['id'],
            'currency' => null,
            'status' => 'active',
            'rules' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $description = $gate->describe('full_op', $agency['id'], 'XAF');
        self::assertTrue($description['ready_for_posting']);
        self::assertSame($debit['id'], $description['debit_ledger_account_id']);
        self::assertSame($credit['id'], $description['credit_ledger_account_id']);
    }

    public function test_report_run_review_requires_different_user_then_locks(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $runPublicId = $this->seedPendingReportRun($maker);

        // Maker self-review rejected.
        $self = $this->withApiHeaders()->actingAsSanctum($maker)
            ->postJson('/api/v1/report-runs/'.$runPublicId.'/review', ['decision' => 'approve']);
        $this->assertJsonError($self, 422);

        // Checker approves.
        $approve = $this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/report-runs/'.$runPublicId.'/review', ['decision' => 'approve', 'comments' => 'OK']);
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.review_status', 'approved');

        // Second review rejected.
        $second = $this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/report-runs/'.$runPublicId.'/review', ['decision' => 'reject']);
        $this->assertJsonError($second, 422);
    }

    public function test_report_run_submission_requires_prior_approval(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $runPublicId = $this->seedPendingReportRun($maker);

        // Submit before approval → fails.
        $tooEarly = $this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/report-runs/'.$runPublicId.'/submit', [
                'submission_channel' => 'cobac_portal',
                'submission_reference' => 'SUB-'.Str::random(6),
            ]);
        $this->assertJsonError($tooEarly, 422);

        // Approve then submit.
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/report-runs/'.$runPublicId.'/review', ['decision' => 'approve']));

        $submit = $this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/report-runs/'.$runPublicId.'/submit', [
                'submission_channel' => 'cobac_portal',
                'submission_reference' => 'SUB-12345',
                'submitted_at' => '2026-05-25T10:00:00Z',
            ]);
        $this->assertJsonSuccess($submit);
        $submit->assertJsonPath('data.submission_channel', 'cobac_portal');
        $submit->assertJsonPath('data.submission_reference', 'SUB-12345');
        $this->assertDatabaseHas('report_runs', [
            'public_id' => $runPublicId,
            'submitted_by_user_id' => $checker->id,
        ]);

        // Idempotent: re-submitting fails.
        $again = $this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/report-runs/'.$runPublicId.'/submit', [
                'submission_channel' => 'cobac_portal',
                'submission_reference' => 'SUB-12345',
            ]);
        $this->assertJsonError($again, 422);
    }

    public function test_inspect_mapping_endpoint_returns_completeness_description(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('REG-INSPECT');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/regulatory-mapping-inspection/missing_op?agency_id='.$agency['id'].'&currency=XAF');
        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.ready_for_posting', false);
        $reason = $response->json('data.reason');
        self::assertIsString($reason);
        self::assertStringContainsString('Operation code does not exist', $reason);
    }

    public function test_emf_report_run_snapshots_source_and_uses_posted_journals_only(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('REG-RUN');
        $sourcePublicId = $this->createSource($actor);
        $definitionPublicId = $this->createReportDefinition($actor, $sourcePublicId);
        $ledger = $this->createLedgerAccount($agency['id']);
        $this->mapLedgerToEmfAccount($ledger['id'], $sourcePublicId);

        $this->seedJournal($agency['id'], $ledger['id'], $actor->id, 1000, 0, 'posted');
        $this->seedJournal($agency['id'], $ledger['id'], $actor->id, 5000, 0, 'draft');

        $first = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($first, 201);
        $first->assertJsonPath('data.summary.debit_total_minor', 1000);
        $first->assertJsonPath('data.source_version_snapshot.source_reference', 'COBAC-EMF-TEST');

        $this->seedJournal($agency['id'], $ledger['id'], $actor->id, 250, 0, 'posted');
        $second = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($second, 201);
        $second->assertJsonPath('data.summary.debit_total_minor', 1250);
        self::assertNotSame($first->json('data.public_id'), $second->json('data.public_id'));
    }

    public function test_inactive_emf_account_cannot_be_used_for_new_mappings(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('REG-D2');
        $sourcePublicId = $this->createSource($actor);
        $ledger = $this->createLedgerAccount($agency['id']);

        $source = DB::table('regulatory_sources')->where('public_id', $sourcePublicId)->first(['id']);
        self::assertIsObject($source);
        $inactiveEmfPublicId = (string) Str::ulid();
        DB::table('emf_regulatory_accounts')->insert([
            'public_id' => $inactiveEmfPublicId,
            'regulatory_source_id' => (int) $source->id,
            'code' => 'INACT-01',
            'name' => 'Inactive EMF Account',
            'account_class' => 'asset',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/emf-ledger-account-mappings', [
                'emf_regulatory_account_public_id' => $inactiveEmfPublicId,
                'ledger_account_public_id' => $ledger['public_id'],
            ]);
        $this->assertJsonError($response, 422);
        $response->assertJsonPath('errors.emf_regulatory_account_public_id.0', 'The selected EMF regulatory account must be active.');
    }

    public function test_report_definition_version_is_immutable_no_patch_route_exists(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $sourcePublicId = $this->createSource($actor);

        $created = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-definitions', [
                'code' => 'IMMUT-TEST',
                'name' => 'Immutable Definition',
                'report_type' => 'emf_trial_balance',
                'module' => 'reporting',
                'regulatory_source_public_id' => $sourcePublicId,
            ]);
        $this->assertJsonSuccess($created, 201);
        $definitionPublicId = $this->requireStringJsonPath($created, 'data.public_id');
        $created->assertJsonPath('data.version', 1);

        // No PATCH/PUT route exists for report definitions — attempting to patch returns 404.
        $patch = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/report-definitions/'.$definitionPublicId, [
                'name' => 'Mutated Name',
            ]);
        $patch->assertStatus(404);

        // The original version 1 record in the database is untouched.
        $this->assertDatabaseHas('report_definitions', [
            'public_id' => $definitionPublicId,
            'code' => 'IMMUT-TEST',
            'version' => 1,
            'name' => 'Immutable Definition',
        ]);
    }

    public function test_same_period_and_source_produces_identical_totals_on_rerun(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('REG-D5');
        $sourcePublicId = $this->createSource($actor);
        $definitionPublicId = $this->createReportDefinition($actor, $sourcePublicId);
        $ledger = $this->createLedgerAccount($agency['id']);
        $this->mapLedgerToEmfAccount($ledger['id'], $sourcePublicId);

        $this->seedJournal($agency['id'], $ledger['id'], $actor->id, 2000, 0, 'posted');

        $run1 = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($run1, 201);

        // No data change between runs.
        $run2 = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($run2, 201);

        self::assertSame(2000, $run1->json('data.summary.debit_total_minor'));
        self::assertSame($run1->json('data.summary.debit_total_minor'), $run2->json('data.summary.debit_total_minor'));
        self::assertSame($run1->json('data.summary.credit_total_minor'), $run2->json('data.summary.credit_total_minor'));
        self::assertSame(
            $run1->json('data.source_version_snapshot.source_checksum'),
            $run2->json('data.source_version_snapshot.source_checksum'),
        );
        self::assertNotSame($run1->json('data.public_id'), $run2->json('data.public_id'));
    }

    private function createSource(User $actor): string
    {
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/regulatory-sources', [
                'authority' => 'cobac',
                'reference' => 'COBAC-EMF-TEST',
                'title' => 'EMF Source',
                'effective_date' => '2010-01-01',
                'checksum' => str_repeat('c', 64),
            ]);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function createReportDefinition(User $actor, string $sourcePublicId): string
    {
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/report-definitions', [
                'code' => 'COBAC-RUN-'.Str::random(4),
                'name' => 'COBAC Run',
                'report_type' => ReportDefinition::TYPE_EMF_TRIAL_BALANCE,
                'module' => 'reporting',
                'regulatory_source_public_id' => $sourcePublicId,
                'definition' => [
                    'lines' => [
                        ['source' => 'emf_regulatory_balance', 'fields' => ['emf_code', 'debit_total_minor', 'credit_total_minor']],
                    ],
                ],
            ]);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function mapLedgerToEmfAccount(int $ledgerAccountId, string $sourcePublicId): void
    {
        $source = DB::table('regulatory_sources')->where('public_id', $sourcePublicId)->first(['id']);
        self::assertIsObject($source);
        $emfId = DB::table('emf_regulatory_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'regulatory_source_id' => (int) $source->id,
            'code' => '101',
            'name' => 'Cash',
            'account_class' => 'asset',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('emf_ledger_account_mappings')->insert([
            'public_id' => (string) Str::ulid(),
            'emf_regulatory_account_id' => $emfId,
            'ledger_account_id' => $ledgerAccountId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedJournal(int $agencyId, int $ledgerAccountId, int $actorId, int $debitMinor, int $creditMinor, string $status): void
    {
        $journalId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'REG-JRN-'.Str::random(6),
            'business_date' => '2026-05-15',
            'posted_at' => $status === 'posted' ? now() : null,
            'agency_id' => $agencyId,
            'source_module' => 'test',
            'source_type' => 'regulatory_reporting_test',
            'status' => $status,
            'posted_by_user_id' => $status === 'posted' ? $actorId : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('journal_lines')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'journal_entry_id' => $journalId,
            'ledger_account_id' => $ledgerAccountId,
            'debit_minor' => $debitMinor,
            'credit_minor' => $creditMinor,
            'currency' => 'XAF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedPendingReportRun(User $maker): string
    {
        $defId = DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'TRIAL-'.Str::random(4),
            'version' => 1,
            'name' => 'Trial Balance',
            'report_type' => ReportDefinition::TYPE_TRIAL_BALANCE,
            'module' => 'reporting',
            'status' => 'active',
            'definition' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $publicId = (string) Str::ulid();
        DB::table('report_runs')->insert([
            'public_id' => $publicId,
            'report_definition_id' => $defId,
            'agency_id' => null,
            'period_starts_on' => '2026-05-01',
            'period_ends_on' => '2026-05-31',
            'status' => ReportRun::STATUS_COMPLETED,
            'review_status' => 'pending',
            'generated_at' => now(),
            'generated_by_user_id' => $maker->id,
            'parameters' => null,
            'summary' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
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
     * @return array{id:int, public_id:string}
     */
    private function createAgency(string $code): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('agencies')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'name' => 'Agency '.$code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createLedgerAccount(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('ledger_accounts')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'code' => 'LED-'.Str::ulid(),
            'name' => 'Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
