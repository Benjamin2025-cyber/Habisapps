<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Covers GitHub issue #11 admin-dashboard real-data endpoints:
 * timeseries (GHI-011A), agency performance (GHI-011B), and operational loan
 * counts plus the loans in-arrears filter (GHI-011C).
 */
final class AdminDashboardRealDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    // ----- GHI-011A: timeseries -----

    public function test_timeseries_buckets_balance_and_windowed_collections_with_zero_buckets(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('TS-ADMIN');
        $product = $this->createLoanProduct('TS-PROD');

        $loan = $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', [
            ['due_date' => '2026-06-01', 'principal_minor' => 1000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        $this->seedPostedRepayment($agency['id'], $loan['client_id'], $loan['loan_id'], '2026-06-02', [
            ['line_id' => $loan['line_ids'][0], 'component' => 'principal', 'amount' => 250],
        ]);

        $response = $this->actingWith($actor)->getJson('/api/v1/dashboards/operational/timeseries?'
            .'agency_public_id='.$agency['public_id']
            .'&period_starts_on=2026-06-01&period_ends_on=2026-06-03&granularity=day');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.granularity', 'day');
        $response->assertJsonPath('data.period.from', '2026-06-01');
        $response->assertJsonPath('data.period.to', '2026-06-03');

        $points = $this->arrayJsonPath($response, 'data.points');
        self::assertCount(3, $points);

        // Balance is the historical as-of-bucket outstanding: payments only
        // reduce the balance from their own bucket onward (no retro-shrinking).
        $response->assertJsonPath('data.points.0.bucket', '2026-06-01T00:00:00+00:00');
        $response->assertJsonPath('data.points.0.balance_minor', 1000);
        $response->assertJsonPath('data.points.0.collection_minor', 0);
        // The 2026-06-02 collection lands only in its own bucket and reduces the balance from there.
        $response->assertJsonPath('data.points.1.collection_minor', 250);
        $response->assertJsonPath('data.points.1.balance_minor', 750);
        // 2026-06-03 is a zero collection bucket but still present; balance stays reduced.
        $response->assertJsonPath('data.points.2.collection_minor', 0);
        $response->assertJsonPath('data.points.2.balance_minor', 750);
    }

    public function test_timeseries_honors_loan_status_filter(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('TS-FILTER');
        $product = $this->createLoanProduct('TS-FPROD');

        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', [
            ['due_date' => '2026-06-01', 'principal_minor' => 1000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'rescheduled', [
            ['due_date' => '2026-06-01', 'principal_minor' => 4000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $response = $this->actingWith($actor)->getJson('/api/v1/dashboards/operational/timeseries?'
            .'agency_public_id='.$agency['public_id']
            .'&period_starts_on=2026-06-01&period_ends_on=2026-06-01&granularity=day&loan_status=active');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.loan_status', 'active');
        $response->assertJsonPath('data.points.0.balance_minor', 1000);
    }

    public function test_timeseries_restricts_agency_manager_to_their_scope(): void
    {
        $manager = $this->createUserWithRole('agency-manager');
        $home = $this->createAgency('TS-HOME');
        $other = $this->createAgency('TS-OTHER');
        $this->assignStaffToAgency($manager, $home['id']);

        $own = $this->actingWith($manager)->getJson('/api/v1/dashboards/operational/timeseries?period=week');
        $this->assertJsonSuccess($own);
        $own->assertJsonPath('data.agency_public_id', $home['public_id']);

        $cross = $this->actingWith($manager)->getJson('/api/v1/dashboards/operational/timeseries?agency_public_id='.$other['public_id']);
        $this->assertJsonError($cross, 403);
    }

    public function test_timeseries_rejects_unsupported_granularity(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->actingWith($actor)->getJson('/api/v1/dashboards/operational/timeseries?granularity=decade');
        $this->assertJsonError($response, 422);
    }

    // ----- GHI-011B: agency performance -----

    public function test_agencies_performance_returns_all_agencies_for_platform_admin(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('PERF-A');
        $agencyB = $this->createAgency('PERF-B');
        $product = $this->createLoanProduct('PERF-PROD');
        $agent = $this->createUserWithRole('loan-officer', $agencyA['id']);

        // Overdue, unpaid loan (delinquent) in agency A.
        $this->seedLoanWithSchedule($agencyA['id'], $product['id'], 'active', [
            ['due_date' => '2026-05-01', 'principal_minor' => 5000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        // Healthy future-dated loan in agency A with a posted collection by the agent.
        $healthyLoan = $this->seedLoanWithSchedule($agencyA['id'], $product['id'], 'active', [
            ['due_date' => '2026-07-01', 'principal_minor' => 3000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        $this->seedPostedRepayment($agencyA['id'], $healthyLoan['client_id'], $healthyLoan['loan_id'], '2026-06-05', [
            ['line_id' => $healthyLoan['line_ids'][0], 'component' => 'principal', 'amount' => 1000],
        ], $agent->id);

        $response = $this->actingWith($actor)->getJson('/api/v1/dashboards/agencies-performance?'
            .'period_starts_on=2026-06-01&period_ends_on=2026-06-06');

        $this->assertJsonSuccess($response);
        $rows = $this->arrayJsonPath($response, 'data.agencies');
        $byPublicId = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $publicId = $row['agency_public_id'];
            self::assertIsString($publicId);
            $byPublicId[$publicId] = $row;
        }

        self::assertArrayHasKey($agencyA['public_id'], $byPublicId);
        self::assertArrayHasKey($agencyB['public_id'], $byPublicId);

        $rowA = $byPublicId[$agencyA['public_id']];
        self::assertSame(1000, $rowA['collections_minor']);
        self::assertSame(2, $rowA['loans_count']);
        // Principal outstanding includes both loans, irrespective of whether an
        // installment is due: 5000 + 3000 - 1000 repaid.
        self::assertSame(7000, $rowA['loans_amount_minor']);
        self::assertSame(1, $rowA['delinquent_count']);
        self::assertSame(5000, $rowA['delinquent_amount_minor']);
        self::assertSame($agent->public_id, $rowA['best_agent_public_id']);
        self::assertSame($agent->name, $rowA['best_agent_name']);

        // Empty agency still produces a zeroed row with a null best agent.
        $rowB = $byPublicId[$agencyB['public_id']];
        self::assertSame(0, $rowB['collections_minor']);
        self::assertSame(0, $rowB['loans_count']);
        self::assertSame(0, $rowB['delinquent_count']);
        self::assertNull($rowB['best_agent_public_id']);
        self::assertNull($rowB['best_agent_name']);
    }

    public function test_agencies_performance_scopes_agency_manager_to_single_row_and_denies_cross_agency(): void
    {
        $manager = $this->createUserWithRole('agency-manager');
        $home = $this->createAgency('PERF-HOME');
        $other = $this->createAgency('PERF-OTHER');
        $this->assignStaffToAgency($manager, $home['id']);

        $own = $this->actingWith($manager)->getJson('/api/v1/dashboards/agencies-performance');
        $this->assertJsonSuccess($own);
        $rows = $this->arrayJsonPath($own, 'data.agencies');
        self::assertCount(1, $rows);
        $own->assertJsonPath('data.agencies.0.agency_public_id', $home['public_id']);

        $cross = $this->actingWith($manager)->getJson('/api/v1/dashboards/agencies-performance?agency_public_id='.$other['public_id']);
        $this->assertJsonError($cross, 403);
    }

    // ----- GHI-011C: operational loan counts + in-arrears filter -----

    public function test_operational_dashboard_reports_zero_delinquent_when_no_arrears(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CNT-NONE');
        $product = $this->createLoanProduct('CNT-NP');

        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', [
            ['due_date' => '2026-12-01', 'principal_minor' => 2000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $response = $this->actingWith($actor)->getJson('/api/v1/dashboards/operational?agency_public_id='.$agency['public_id']);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.active_loan_count', 1);
        $response->assertJsonPath('data.delinquent_loan_count', 0);

        // Counts are also discoverable as dashboard sections.
        $keys = array_column($this->arrayJsonPath($response, 'data.dashboard_sections'), 'key');
        self::assertContains('active_loan_count', $keys);
        self::assertContains('delinquent_loan_count', $keys);
    }

    public function test_operational_dashboard_counts_one_delinquent_loan_with_multiple_overdue_lines(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CNT-DELQ');
        $product = $this->createLoanProduct('CNT-DP');

        // One loan with two overdue unpaid lines -> a single delinquent loan.
        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', [
            ['due_date' => '2026-04-01', 'principal_minor' => 1500, 'interest_minor' => 0, 'penalty_minor' => 0],
            ['due_date' => '2026-04-15', 'principal_minor' => 2500, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        // A second, healthy loan keeps active_loan_count distinct from delinquent_loan_count.
        $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', [
            ['due_date' => '2026-12-01', 'principal_minor' => 1000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $response = $this->actingWith($actor)->getJson('/api/v1/dashboards/operational?agency_public_id='.$agency['public_id']);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.active_loan_count', 2);
        $response->assertJsonPath('data.delinquent_loan_count', 1);
    }

    public function test_operational_dashboard_counts_respect_agency_scope(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('CNT-SCOPE-A');
        $agencyB = $this->createAgency('CNT-SCOPE-B');
        $product = $this->createLoanProduct('CNT-SP');

        $this->seedLoanWithSchedule($agencyA['id'], $product['id'], 'active', [
            ['due_date' => '2026-04-01', 'principal_minor' => 9000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $responseB = $this->actingWith($actor)->getJson('/api/v1/dashboards/operational?agency_public_id='.$agencyB['public_id']);
        $this->assertJsonSuccess($responseB);
        $responseB->assertJsonPath('data.active_loan_count', 0);
        $responseB->assertJsonPath('data.delinquent_loan_count', 0);

        $responseA = $this->actingWith($actor)->getJson('/api/v1/dashboards/operational?agency_public_id='.$agencyA['public_id']);
        $this->assertJsonSuccess($responseA);
        $responseA->assertJsonPath('data.active_loan_count', 1);
        $responseA->assertJsonPath('data.delinquent_loan_count', 1);
    }

    public function test_loans_in_arrears_filter_returns_only_delinquent_loans(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ARR-FLT');
        $product = $this->createLoanProduct('ARR-FP');

        $delinquent = $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', [
            ['due_date' => '2026-04-01', 'principal_minor' => 5000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        $healthy = $this->seedLoanWithSchedule($agency['id'], $product['id'], 'active', [
            ['due_date' => '2026-12-01', 'principal_minor' => 3000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $delinquentPublicId = $this->loanPublicId($delinquent['loan_id']);
        $healthyPublicId = $this->loanPublicId($healthy['loan_id']);

        $filtered = $this->actingWith($actor)->getJson('/api/v1/loans?filter[in_arrears]=true&per_page=100');
        $this->assertJsonSuccess($filtered);
        $filtered->assertJsonFragment(['public_id' => $delinquentPublicId]);
        $filtered->assertJsonMissing(['public_id' => $healthyPublicId]);

        $unfiltered = $this->actingWith($actor)->getJson('/api/v1/loans?per_page=100');
        $this->assertJsonSuccess($unfiltered);
        $unfiltered->assertJsonFragment(['public_id' => $healthyPublicId]);
    }

    // ----- helpers -----

    private function actingWith(User $actor): self
    {
        return $this->withApiHeaders()->actingAsSanctum($actor);
    }

    private function createUserWithRole(string $role, ?int $agencyId = null): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agencyId,
        ]);
        $user->assignRole($role);

        if ($agencyId !== null) {
            $this->assignStaffToAgency($user, $agencyId, $role);
        }

        return $user;
    }

    private function assignStaffToAgency(User $user, int $agencyId, string $role = 'agency-manager'): void
    {
        StaffAgencyAssignment::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => $role,
            'starts_on' => now()->subDay()->toDateString(),
            'is_primary' => true,
            'status' => StaffAgencyAssignment::STATUS_ACTIVE,
        ]);
    }

    /**
     * @return array{id:int, public_id:string, code:string, name:string}
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

        return ['id' => $id, 'public_id' => $publicId, 'code' => $code, 'name' => 'Agency '.$code];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createLoanProduct(string $code): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('loan_products')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'name' => 'Product '.$code,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    private function seedClient(int $agencyId): int
    {
        return DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Loan',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
            'phone_number' => '+237600000088',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array{due_date:string, principal_minor:int, interest_minor:int, penalty_minor:int}>  $lines
     * @return array{loan_id:int, client_id:int, line_ids:list<int>}
     */
    private function seedLoanWithSchedule(int $agencyId, int $productId, string $status, array $lines): array
    {
        $clientId = $this->seedClient($agencyId);
        $principalMinor = array_sum(array_column($lines, 'principal_minor'));
        $loanId = DB::table('loans')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $productId,
            'loan_number' => 'LOAN-'.Str::ulid(),
            'requested_amount_minor' => $principalMinor,
            'approved_principal_minor' => $principalMinor,
            'currency' => 'XAF',
            'applied_on' => '2026-01-01',
            'approved_on' => '2026-01-02',
            'disbursed_on' => '2026-01-03',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $snapshotId = DB::table('loan_schedule_snapshots')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loanId,
            'formula_engine_key' => 'test',
            'formula_engine_version' => '1',
            'policy_snapshot_hash' => str_repeat('a', 64),
            'generated_at' => now(),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $lineIds = [];
        foreach ($lines as $index => $line) {
            $lineIds[] = DB::table('loan_schedule_lines')->insertGetId([
                'loan_schedule_snapshot_id' => $snapshotId,
                'installment_number' => $index + 1,
                'due_date' => $line['due_date'],
                'principal_minor' => $line['principal_minor'],
                'interest_minor' => $line['interest_minor'],
                'fees_minor' => 0,
                'insurance_minor' => 0,
                'tax_minor' => 0,
                'penalty_minor' => $line['penalty_minor'],
                'currency' => 'XAF',
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return ['loan_id' => $loanId, 'client_id' => $clientId, 'line_ids' => $lineIds];
    }

    /**
     * @param  list<array{line_id:int, component:string, amount:int}>  $allocations
     */
    private function seedPostedRepayment(int $agencyId, int $clientId, int $loanId, string $paidOn, array $allocations, ?int $postedByUserId = null): void
    {
        $total = 0;
        foreach ($allocations as $allocation) {
            $total += $allocation['amount'];
        }

        $ledgerAccountId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'L-'.Str::ulid(),
            'name' => 'Repayment Ledger',
            'account_class' => 'asset',
            'normal_balance_side' => 'debit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerAccountId = DB::table('customer_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'ledger_account_id' => $ledgerAccountId,
            'account_number' => 'CA-'.Str::upper(Str::random(8)),
            'account_type' => 'savings',
            'opened_on' => '2026-01-01',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $journalEntryId = DB::table('journal_entries')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'reference' => 'JE-'.Str::upper(Str::random(8)),
            'business_date' => $paidOn,
            'agency_id' => $agencyId,
            'status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $repaymentId = DB::table('loan_repayments')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'loan_id' => $loanId,
            'journal_entry_id' => $journalEntryId,
            'customer_account_id' => $customerAccountId,
            'received_amount_minor' => $total,
            'allocated_amount_minor' => $total,
            'currency' => 'XAF',
            'paid_on' => $paidOn,
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by_user_id' => $postedByUserId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($allocations as $allocation) {
            DB::table('loan_repayment_allocations')->insert([
                'loan_repayment_id' => $repaymentId,
                'loan_schedule_line_id' => $allocation['line_id'],
                'component' => $allocation['component'],
                'amount_minor' => $allocation['amount'],
                'currency' => 'XAF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function loanPublicId(int $loanId): string
    {
        $value = DB::table('loans')->where('id', $loanId)->value('public_id');
        self::assertIsString($value);

        return $value;
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
}
