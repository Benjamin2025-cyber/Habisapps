<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Application\Reporting\MappingCompletenessGate;
use App\Models\AccountingDay;
use App\Models\LedgerAccount;
use App\Models\ReportDefinition;
use App\Models\ReportRun;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StandardReportDefinitionSeeder;
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

        // Regulatory POST routes run under the accounting-day registration lock,
        // so an open institution day is a precondition for registering sources,
        // report-definition versions, and report-run review/submission.
        $this->openAccountingDay();
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

        // The report-definition resource is read-only: the catalog exposes GET
        // (index/show) but no mutating verb, so PATCH is rejected as
        // method-not-allowed (405).
        $patch = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/report-definitions/'.$definitionPublicId, [
                'name' => 'Mutated Name',
            ]);
        $patch->assertStatus(405);

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

    // ─── Report definition catalog (FBI2-028) ───────────────────────────

    private function seedReportDefinitions(): void
    {
        $this->seed(StandardReportDefinitionSeeder::class);
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function insertDefinition(User $actor, string $code, string $type = 'trial_balance', string $status = 'active'): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('report_definitions')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'version' => 1,
            'name' => 'Test '.$code,
            'report_type' => $type,
            'module' => 'test',
            'status' => $status,
            'definition' => json_encode([
                'lines' => [
                    ['source' => 'ledger_balance', 'fields' => ['ledger_account_code', 'debit_total_minor', 'credit_total_minor']],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'public_id' => $publicId,
        ];
    }

    private function openAccountingDay(): void
    {
        AccountingDay::query()->create([
            'scope_type' => AccountingDay::SCOPE_INSTITUTION,
            'business_date' => now()->toDateString(),
            'calendar_opened_at' => now(),
            'status' => AccountingDay::STATUS_OPEN,
            'is_holiday' => false,
            'origin' => AccountingDay::ORIGIN_MANUAL,
            'write_lock_version' => 0,
        ]);
    }

    public function test_standard_report_definitions_are_seeded(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');

        $this->assertJsonSuccess($response);
        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);
        self::assertCount(6, $definitions);

        $codes = [];
        foreach ($definitions as $d) {
            /** @var array<string, mixed> $d */
            $codes[] = is_string($d['code'] ?? null) ? $d['code'] : '';
        }
        sort($codes);
        self::assertSame([
            'credit_collection_performance',
            'credit_par_delinquency',
            'credit_portfolio_outstanding',
            'emf_trial_balance',
            'general_ledger',
            'trial_balance',
        ], $codes);

        foreach ($definitions as $definition) {
            /** @var array<string, mixed> $definition */
            self::assertSame('active', $definition['status']);
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $first = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');
        $this->assertJsonSuccess($first);
        $firstCount = count((array) $first->json('data.report_definitions'));

        $this->seed(StandardReportDefinitionSeeder::class);

        $second = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');
        $this->assertJsonSuccess($second);
        $secondCount = count((array) $second->json('data.report_definitions'));

        self::assertSame($firstCount, $secondCount, 'Seeding twice must not create duplicates.');
    }

    public function test_seeder_does_not_change_public_id_on_re_run(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $firstResponse = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');
        $this->assertJsonSuccess($firstResponse);

        $firstPublicIds = [];
        $firstDefinitions = $firstResponse->json('data.report_definitions');
        self::assertIsArray($firstDefinitions);
        foreach ($firstDefinitions as $def) {
            /** @var array{code: string, public_id: string} $def */
            $firstPublicIds[$def['code']] = $def['public_id'];
        }

        $this->seed(StandardReportDefinitionSeeder::class);

        $secondResponse = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');
        $this->assertJsonSuccess($secondResponse);

        $secondDefinitions = $secondResponse->json('data.report_definitions');
        self::assertIsArray($secondDefinitions);
        foreach ($secondDefinitions as $def) {
            /** @var array{code: string, public_id: string} $def */
            $code = $def['code'];
            self::assertSame(
                $firstPublicIds[$code] ?? null,
                $def['public_id'],
                "Public ID for {$code} changed after re-seed."
            );
        }
    }

    public function test_index_returns_active_by_default(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');
        $this->insertDefinition($actor, 'INACTIVE-DEF', 'trial_balance', 'inactive');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');

        $this->assertJsonSuccess($response);

        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);

        foreach ($definitions as $definition) {
            /** @var array<string, mixed> $definition */
            self::assertSame('active', $definition['status'], 'Only active definitions should be returned by default.');
        }
    }

    public function test_index_includes_inactive_for_platform_admin(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');
        $this->insertDefinition($actor, 'INACTIVE-DEF', 'trial_balance', 'inactive');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?include_inactive=true');

        $this->assertJsonSuccess($response);

        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);

        $statuses = [];
        foreach ($definitions as $definition) {
            /** @var array{status: string} $definition */
            $statuses[] = $definition['status'];
        }
        self::assertContains('inactive', $statuses, 'Platform admin should see inactive definitions with include_inactive=true.');
    }

    public function test_index_include_inactive_blocked_for_non_platform(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('compliance-officer');
        $this->insertDefinition($actor, 'INACTIVE-DEF', 'trial_balance', 'inactive');

        $defaultResponse = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');

        $flaggedResponse = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?include_inactive=true');

        $this->assertJsonSuccess($defaultResponse);
        $this->assertJsonSuccess($flaggedResponse);

        $defaultDefinitions = $defaultResponse->json('data.report_definitions');
        $flaggedDefinitions = $flaggedResponse->json('data.report_definitions');
        self::assertIsArray($defaultDefinitions);
        self::assertIsArray($flaggedDefinitions);
        $defaultCount = count($defaultDefinitions);
        $flaggedCount = count($flaggedDefinitions);

        self::assertSame($defaultCount, $flaggedCount, 'Non-platform admin must not see additional definitions with include_inactive=true.');
    }

    public function test_index_requires_accounting_audit_view(): void
    {
        $this->seedReportDefinitions();
        $teller = $this->createUserWithRole('teller');

        $response = $this->withApiHeaders()->actingAsSanctum($teller)
            ->getJson('/api/v1/report-definitions');

        $this->assertJsonError($response, 403);
    }

    public function test_index_unknown_filter_returns_422(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?filter[xyz]=abc');

        $this->assertJsonError($response, 422);
        $response->assertJsonPath('message', 'Unsupported filter parameters.');
    }

    public function test_index_filters_by_report_type(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?filter[report_type]=trial_balance');

        $this->assertJsonSuccess($response);
        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);

        foreach ($definitions as $definition) {
            /** @var array<string, mixed> $definition */
            self::assertSame('trial_balance', $definition['report_type']);
        }
    }

    public function test_index_filters_by_module(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?filter[module]=credit');

        $this->assertJsonSuccess($response);
        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);

        foreach ($definitions as $definition) {
            /** @var array<string, mixed> $definition */
            self::assertSame('credit', $definition['module']);
        }
    }

    public function test_index_search(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?search=trial');

        $this->assertJsonSuccess($response);
        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);
        self::assertGreaterThan(0, count($definitions));
    }

    public function test_index_pagination(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?per_page=2');

        $this->assertJsonSuccess($response);
        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);
        self::assertCount(2, $definitions);

        $meta = $response->json('meta.pagination');
        self::assertIsArray($meta);
        self::assertArrayHasKey('current_page', $meta);
        self::assertArrayHasKey('per_page', $meta);
        self::assertArrayHasKey('total', $meta);
        self::assertArrayHasKey('last_page', $meta);
        self::assertSame(2, $meta['per_page']);
    }

    public function test_index_per_page_capped(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?per_page=200');

        $this->assertJsonSuccess($response);
        $meta = $response->json('meta.pagination');
        self::assertIsArray($meta);
        self::assertSame(100, $meta['per_page'], 'per_page must be capped at 100.');
    }

    public function test_show_returns_single_definition(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $indexResponse = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');
        $this->assertJsonSuccess($indexResponse);
        $firstPublicId = $this->requireStringJsonPath($indexResponse, 'data.report_definitions.0.public_id');

        $showResponse = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions/'.$firstPublicId);

        $this->assertJsonSuccess($showResponse);
        $showResponse->assertJsonPath('data.public_id', $firstPublicId);
        $showResponse->assertJsonPath('data.status', 'active');
    }

    public function test_show_requires_accounting_audit_view(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');
        $teller = $this->createUserWithRole('teller');

        $indexResponse = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');
        $this->assertJsonSuccess($indexResponse);
        $firstPublicId = $this->requireStringJsonPath($indexResponse, 'data.report_definitions.0.public_id');

        $response = $this->withApiHeaders()->actingAsSanctum($teller)
            ->getJson('/api/v1/report-definitions/'.$firstPublicId);

        $this->assertJsonError($response, 403);
    }

    public function test_index_agency_scope_enforces_agency_context(): void
    {
        $this->seedReportDefinitions();

        // A user with accounting.audit.view but WITHOUT institution scope
        // and WITHOUT an agency assignment should be rejected.
        $user = $this->createUserWithRole('teller');
        $user->givePermissionTo('accounting.audit.view');

        $response = $this->withApiHeaders()->actingAsSanctum($user)
            ->getJson('/api/v1/report-definitions');

        $this->assertJsonError($response, 403);
    }

    public function test_index_agency_scope_allows_institution_scope_without_agency(): void
    {
        $this->seedReportDefinitions();

        // Compliance-officer has accounting.audit.view + crm.scope.institution.read
        // without a primary agency assignment → should pass.
        $actor = $this->createUserWithRole('compliance-officer');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');

        $this->assertJsonSuccess($response);
        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);
        self::assertGreaterThan(0, count($definitions));
    }

    public function test_generation_from_catalog_definition(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        // Discover a definition from the catalog. Pick the trial-balance
        // definition explicitly so generation has no extra prerequisites
        // (credit reports need an approved formula policy, EMF needs a source).
        $catalog = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?filter[report_type]=trial_balance');
        $this->assertJsonSuccess($catalog);
        $definitionPublicId = $catalog->json('data.report_definitions.0.public_id');
        self::assertIsString($definitionPublicId);

        // Use it to generate a report run.
        $runResponse = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
            ]);
        $this->assertJsonSuccess($runResponse, 201);
        $runResponse->assertJsonPath('data.report_definition_public_id', $definitionPublicId);
    }

    public function test_report_definition_resource_fields(): void
    {
        $this->seedReportDefinitions();
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions');
        $this->assertJsonSuccess($response);

        $definition = $response->json('data.report_definitions.0');
        self::assertIsArray($definition);

        // Assert all required fields are present.
        self::assertArrayHasKey('public_id', $definition);
        self::assertArrayHasKey('code', $definition);
        self::assertArrayHasKey('name', $definition);
        self::assertArrayHasKey('report_type', $definition);
        self::assertArrayHasKey('module', $definition);
        self::assertArrayHasKey('status', $definition);
        self::assertArrayHasKey('version', $definition);
        self::assertArrayHasKey('effective_from', $definition);
        self::assertArrayHasKey('effective_to', $definition);
        self::assertArrayHasKey('supported_parameters', $definition);
        self::assertArrayHasKey('requires_agency', $definition);
        self::assertArrayHasKey('requires_currency', $definition);
        self::assertArrayHasKey('requires_period', $definition);
        self::assertArrayHasKey('description', $definition);

        self::assertIsString($definition['public_id']);
        self::assertIsInt($definition['version']);
        self::assertIsArray($definition['supported_parameters']);
        self::assertIsBool($definition['requires_agency']);
    }

    public function test_index_returns_only_latest_active_version_per_code(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        // Two active versions of the same code (versions are immutable and a
        // new version stays active alongside its predecessor).
        $v1PublicId = (string) Str::ulid();
        $v2PublicId = (string) Str::ulid();
        DB::table('report_definitions')->insert([
            [
                'public_id' => $v1PublicId,
                'code' => 'VERSIONED-DEF',
                'version' => 1,
                'name' => 'Versioned Definition v1',
                'report_type' => 'trial_balance',
                'module' => 'reporting',
                'status' => 'active',
                'definition' => null,
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'public_id' => $v2PublicId,
                'code' => 'VERSIONED-DEF',
                'version' => 2,
                'name' => 'Versioned Definition v2',
                'report_type' => 'trial_balance',
                'module' => 'reporting',
                'status' => 'active',
                'definition' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?filter[module]=reporting');
        $this->assertJsonSuccess($response);

        $definitions = $response->json('data.report_definitions');
        self::assertIsArray($definitions);

        /** @var array<int, array<string, mixed>> $definitions */
        $matching = array_values(array_filter(
            $definitions,
            static fn (array $d): bool => $d['code'] === 'VERSIONED-DEF',
        ));

        // The active default must collapse the code to a single latest version.
        self::assertCount(1, $matching, 'Catalog must not return duplicate versions of the same code.');
        self::assertSame(2, $matching[0]['version']);
        self::assertSame($v2PublicId, $matching[0]['public_id']);
    }

    public function test_index_latest_active_version_skips_inactive_newer_version(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        // v2 is inactive (deprecated); v1 remains the latest *active* version.
        $v1PublicId = (string) Str::ulid();
        DB::table('report_definitions')->insert([
            [
                'public_id' => $v1PublicId,
                'code' => 'DEPRECATED-NEWER',
                'version' => 1,
                'name' => 'Active v1',
                'report_type' => 'trial_balance',
                'module' => 'reporting',
                'status' => 'active',
                'definition' => null,
                'created_at' => now()->subMinute(),
                'updated_at' => now()->subMinute(),
            ],
            [
                'public_id' => (string) Str::ulid(),
                'code' => 'DEPRECATED-NEWER',
                'version' => 2,
                'name' => 'Inactive v2',
                'report_type' => 'trial_balance',
                'module' => 'reporting',
                'status' => 'inactive',
                'definition' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/report-definitions?filter[module]=reporting');
        $this->assertJsonSuccess($response);

        /** @var array<int, array<string, mixed>> $definitions */
        $definitions = $response->json('data.report_definitions');
        $matching = array_values(array_filter(
            $definitions,
            static fn (array $d): bool => $d['code'] === 'DEPRECATED-NEWER',
        ));

        self::assertCount(1, $matching, 'Only the latest active version should be returned.');
        self::assertSame(1, $matching[0]['version']);
        self::assertSame($v1PublicId, $matching[0]['public_id']);
    }
}
