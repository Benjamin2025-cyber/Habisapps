<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class HrPayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_employee_creation_and_immutable_public_id(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR01');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-employees', [
                'agency_public_id' => $agency['public_id'],
                'first_name' => 'Marie',
                'last_name' => 'Durand',
                'base_salary_minor' => 250000,
            ]);
        $this->assertJsonSuccess($response, 201);
        self::assertIsString($response->json('data.public_id'));
        self::assertIsString($response->json('data.employee_number'));
        $response->assertJsonPath('data.base_salary_minor', 250000);
        $response->assertJsonPath('data.agency_public_id', $agency['public_id']);
        self::assertNull($response->json('data.agency_id'));

        $publicId = $this->requireStringJsonPath($response, 'data.public_id');
        $this->assertDatabaseHas('hr_employees', [
            'public_id' => $publicId,
            'agency_id' => $agency['id'],
        ]);
        $employeeId = DB::table('hr_employees')->where('public_id', $publicId)->value('id');
        $this->assertDatabaseHas('hr_employee_agency_history', [
            'hr_employee_id' => $employeeId,
            'agency_id' => $agency['id'],
            'reason' => 'hire',
        ]);
    }

    public function test_employee_document_attachment_requires_same_agency(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-DOC');
        $employeePublicId = $this->createEmployee($actor, $agency);
        $documentPublicId = $this->seedDocument($agency['id']);
        $otherAgency = $this->createAgency('HR-OTHER');
        $crossDocPublicId = $this->seedDocument($otherAgency['id']);

        $ok = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-employees/'.$employeePublicId.'/documents', [
                'document_public_id' => $documentPublicId,
                'document_type' => 'national_id',
            ]);
        $this->assertJsonSuccess($ok, 201);

        $cross = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-employees/'.$employeePublicId.'/documents', [
                'document_public_id' => $crossDocPublicId,
            ]);
        $this->assertJsonError($cross, 422);
    }

    public function test_contract_document_requires_employee_agency_document(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-CDOC');
        $otherAgency = $this->createAgency('HR-CDOX');
        $employeePublicId = $this->createEmployee($actor, $agency);
        $crossDocPublicId = $this->seedDocument($otherAgency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-employees/'.$employeePublicId.'/contracts', [
                'contract_type' => 'CDD',
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-12-31',
                'document_public_id' => $crossDocPublicId,
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_contract_renewal_increments_version_and_supersedes_previous(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-CTR');
        $employeePublicId = $this->createEmployee($actor, $agency);

        $v1 = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-employees/'.$employeePublicId.'/contracts', [
                'contract_type' => 'CDD',
                'starts_on' => '2026-01-01',
                'ends_on' => '2026-12-31',
                'base_salary_minor' => 200000,
            ]);
        $this->assertJsonSuccess($v1, 201);
        $v1->assertJsonPath('data.version', 1);

        $v2 = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-employees/'.$employeePublicId.'/contracts', [
                'contract_type' => 'CDI',
                'starts_on' => '2027-01-01',
                'base_salary_minor' => 240000,
            ]);
        $this->assertJsonSuccess($v2, 201);
        $v2->assertJsonPath('data.version', 2);

        // Previous v1 superseded
        $this->assertDatabaseHas('hr_contracts', [
            'version' => 1,
            'status' => 'superseded',
        ]);
        $this->assertDatabaseHas('hr_contracts', [
            'version' => 2,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'category' => 'contract_expiry',
            'channel' => 'internal',
            'status' => 'pending',
        ]);
    }

    public function test_leave_request_pending_then_approved_by_different_user(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-LEAVE');
        $employeePublicId = $this->createEmployee($maker, $agency);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-employees/'.$employeePublicId.'/leave-requests', [
                'leave_type' => 'annual',
                'starts_on' => '2026-06-01',
                'ends_on' => '2026-06-10',
                'reason' => 'Vacation',
            ]);
        $this->assertJsonSuccess($create, 201);
        $leavePublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.status', 'pending');

        // Maker self-review rejected.
        $self = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-leave-requests/'.$leavePublicId.'/review', ['decision' => 'approve']);
        $this->assertJsonError($self, 422);

        // Checker approves.
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/hr-leave-requests/'.$leavePublicId.'/review', ['decision' => 'approve']);
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'approved');
    }

    public function test_formula_set_creation_and_activation_with_maker_checker(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $sourcePublicId = $this->seedRegulatorySource();

        $create = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-formula-sets', [
                'code' => 'CM-CNPS-2026',
                'effective_from' => '2026-01-01',
                'regulatory_source_public_id' => $sourcePublicId,
                'rates' => [
                    ['branch' => 'pvid', 'payer' => 'employee', 'rate' => 0.042, 'ceiling_minor' => 75000000],
                    ['branch' => 'pvid', 'payer' => 'employer', 'rate' => 0.042, 'ceiling_minor' => 75000000],
                ],
            ]);
        $this->assertJsonSuccess($create, 201);
        $setPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.status', 'draft');

        // Maker cannot approve their own set.
        $selfApprove = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-formula-sets/'.$setPublicId.'/activate');
        $this->assertJsonError($selfApprove, 422);

        // Checker activates.
        $activate = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/hr-payroll-formula-sets/'.$setPublicId.'/activate');
        $this->assertJsonSuccess($activate);
        $activate->assertJsonPath('data.status', 'active');
    }

    public function test_payroll_formula_set_requires_regulatory_source(): void
    {
        $maker = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-formula-sets', [
                'code' => 'CM-NO-SOURCE',
                'effective_from' => '2026-01-01',
                'rates' => [
                    ['branch' => 'pvid', 'payer' => 'employee', 'rate' => 0.042],
                ],
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_payroll_run_without_active_formula_set_is_rejected(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-NOFORM');
        $employeePublicId = $this->createEmployee($actor, $agency);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-payroll-runs', [
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'employees' => [
                    ['employee_public_id' => $employeePublicId, 'gross_amount_minor' => 200000],
                ],
            ]);
        $this->assertJsonError($response, 422);
    }

    public function test_draft_payroll_has_no_journal_then_approval_posts_journal_with_mappings(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-PAY');
        $employeePublicId = $this->createEmployee($maker, $agency);
        $this->seedActiveFormulaSet($maker, $checker);
        $this->seedPayrollMappings($agency['id']);

        $run = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-runs', [
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'employees' => [
                    ['employee_public_id' => $employeePublicId, 'gross_amount_minor' => 200000],
                ],
            ]);
        $this->assertJsonSuccess($run, 201);
        $runPublicId = $this->requireStringJsonPath($run, 'data.public_id');
        $run->assertJsonPath('data.status', 'draft');
        $run->assertJsonPath('data.journal_entry_id', null);

        // No journal at draft time
        $this->assertDatabaseMissing('journal_entries', [
            'source_module' => 'hr',
            'source_public_id' => $runPublicId,
        ]);

        // Approve
        $approve = $this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/hr-payroll-runs/'.$runPublicId.'/approve');
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('journal_entries', [
            'source_module' => 'hr',
            'source_public_id' => $runPublicId,
            'status' => JournalEntry::STATUS_POSTED,
        ]);

        // Snapshot of formula set is persisted on run.
        $runRow = DB::table('hr_payroll_runs')->where('public_id', $runPublicId)->first();
        self::assertIsObject($runRow);
        $snapshot = json_decode((string) (((array) $runRow)['formula_snapshot'] ?? '{}'), true);
        self::assertIsArray($snapshot);
        self::assertSame('CM-CNPS-2026', $snapshot['formula_set_code'] ?? null);
    }

    public function test_payroll_approval_requires_different_user_and_absence_deduction_requires_approved_leave(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-ABS');
        $employeePublicId = $this->createEmployee($maker, $agency);
        $this->seedActiveFormulaSet($maker, $checker);
        $this->seedPayrollMappings($agency['id']);

        $pendingLeave = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-employees/'.$employeePublicId.'/leave-requests', [
                'leave_type' => 'absence',
                'starts_on' => '2026-05-10',
                'ends_on' => '2026-05-10',
            ]);
        $this->assertJsonSuccess($pendingLeave, 201);

        $blocked = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-runs', [
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'employees' => [
                    [
                        'employee_public_id' => $employeePublicId,
                        'gross_amount_minor' => 200000,
                        'absence_deduction_minor' => 10000,
                        'approved_leave_public_id' => $this->requireStringJsonPath($pendingLeave, 'data.public_id'),
                    ],
                ],
            ]);
        $this->assertJsonError($blocked, 422);

        $leavePublicId = $this->requireStringJsonPath($pendingLeave, 'data.public_id');
        $this->assertJsonSuccess($this->withApiHeaders()
            ->actingAsSanctum($checker)
            ->postJson('/api/v1/hr-leave-requests/'.$leavePublicId.'/review', ['decision' => 'approve']));

        $run = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-runs', [
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'employees' => [
                    [
                        'employee_public_id' => $employeePublicId,
                        'gross_amount_minor' => 200000,
                        'absence_deduction_minor' => 10000,
                        'approved_leave_public_id' => $leavePublicId,
                    ],
                ],
            ]);
        $this->assertJsonSuccess($run, 201);
        $run->assertJsonPath('data.deduction_amount_minor', 18400);

        $selfApprove = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-runs/'.$this->requireStringJsonPath($run, 'data.public_id').'/approve');
        $this->assertJsonError($selfApprove, 422);
    }

    public function test_correction_run_reverses_prior_payroll_journal_and_posts_adjustment(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-CORR');
        $employeePublicId = $this->createEmployee($maker, $agency);
        $this->seedActiveFormulaSet($maker, $checker);
        $this->seedPayrollMappings($agency['id']);

        $original = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-runs', [
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'employees' => [
                    ['employee_public_id' => $employeePublicId, 'gross_amount_minor' => 200000],
                ],
            ]);
        $this->assertJsonSuccess($original, 201);
        $originalPublicId = $this->requireStringJsonPath($original, 'data.public_id');
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/hr-payroll-runs/'.$originalPublicId.'/approve'));

        $correction = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-runs/'.$originalPublicId.'/corrections', [
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'employees' => [
                    ['employee_public_id' => $employeePublicId, 'gross_amount_minor' => 210000],
                ],
            ]);
        $this->assertJsonSuccess($correction, 201);
        $correctionPublicId = $this->requireStringJsonPath($correction, 'data.public_id');
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/hr-payroll-runs/'.$correctionPublicId.'/approve'));

        $this->assertDatabaseHas('hr_payroll_runs', [
            'public_id' => $originalPublicId,
            'status' => 'corrected',
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'source_type' => 'hr_payroll_run_reversal',
            'status' => JournalEntry::STATUS_POSTED,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'source_module' => 'hr',
            'source_public_id' => $correctionPublicId,
            'status' => JournalEntry::STATUS_POSTED,
        ]);
    }

    public function test_unapproved_run_cannot_produce_declaration_export(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $checker = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('HR-EXP');
        $employeePublicId = $this->createEmployee($actor, $agency);
        $this->seedActiveFormulaSet($actor, $checker);
        $this->seedPayrollMappings($agency['id']);

        $run = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-payroll-runs', [
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-31',
                'employees' => [
                    ['employee_public_id' => $employeePublicId, 'gross_amount_minor' => 200000],
                ],
            ]);
        $this->assertJsonSuccess($run, 201);
        $runPublicId = $this->requireStringJsonPath($run, 'data.public_id');

        $earlyExport = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/hr-payroll-runs/'.$runPublicId.'/declaration-export');
        $this->assertJsonError($earlyExport, 422);

        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/hr-payroll-runs/'.$runPublicId.'/approve'));

        $export = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/hr-payroll-runs/'.$runPublicId.'/declaration-export');
        $this->assertJsonSuccess($export);
        self::assertIsString($export->json('data.checksum'));
        $export->assertJsonPath('data.source_payroll_run_public_id', $runPublicId);
    }

    /**
     * @param  array{id:int, public_id:string}  $agency
     */
    private function createEmployee(User $actor, array $agency): string
    {
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/hr-employees', [
                'agency_public_id' => $agency['public_id'],
                'first_name' => 'Test',
                'last_name' => 'Employee',
                'base_salary_minor' => 200000,
            ]);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function seedDocument(int $agencyId): string
    {
        $publicId = (string) Str::ulid();
        $suffix = Str::ulid();
        DB::table('documents')->insert([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'category' => 'hr_document',
            'title' => 'Doc '.$suffix,
            'disk' => 'local',
            'path' => 'hr/'.$suffix.'.pdf',
            'original_name' => 'doc.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1000,
            'checksum_sha256' => str_pad((string) $suffix, 64, '0'),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function seedActiveFormulaSet(User $maker, User $checker): void
    {
        $sourcePublicId = $this->seedRegulatorySource();
        $create = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/hr-payroll-formula-sets', [
                'code' => 'CM-CNPS-2026',
                'effective_from' => '2026-01-01',
                'regulatory_source_public_id' => $sourcePublicId,
                'rates' => [
                    ['branch' => 'pvid', 'payer' => 'employee', 'rate' => 0.042, 'ceiling_minor' => 75000000],
                    ['branch' => 'pvid', 'payer' => 'employer', 'rate' => 0.042, 'ceiling_minor' => 75000000],
                ],
            ]);
        $this->assertJsonSuccess($create, 201);
        $setPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/hr-payroll-formula-sets/'.$setPublicId.'/activate'));
    }

    private function seedRegulatorySource(): string
    {
        $publicId = (string) Str::ulid();
        DB::table('regulatory_sources')->insert([
            'public_id' => $publicId,
            'authority' => 'cnps',
            'reference' => 'CNPS-'.Str::ulid(),
            'title' => 'CNPS payroll source',
            'effective_date' => '2026-01-01',
            'checksum' => str_repeat('d', 64),
            'imported_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $publicId;
    }

    private function seedPayrollMappings(int $agencyId): void
    {
        foreach (['hr_salary_expense', 'hr_net_payable', 'hr_deductions_payable'] as $code) {
            $debit = $this->seedLedger($agencyId);
            $credit = $this->seedLedger($agencyId);
            $opCodeId = DB::table('operation_codes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => $code,
                'label' => str_replace('_', ' ', $code),
                'module' => 'hr',
                'operation_type' => 'payroll',
                'direction' => 'mixed',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('operation_account_mappings')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_code_id' => $opCodeId,
                'debit_ledger_account_id' => $debit,
                'credit_ledger_account_id' => $credit,
                'currency' => null,
                'status' => 'active',
                'rules' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function seedLedger(int $agencyId): int
    {
        return DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'HRL-'.Str::ulid(),
            'name' => 'HR Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_LIABILITY,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_CREDIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
