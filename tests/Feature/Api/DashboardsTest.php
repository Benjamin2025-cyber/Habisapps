<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\StaffAgencyAssignment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_operational_dashboard_returns_metrics_with_freshness_timestamp(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('DSH01');
        $this->seedInsuranceClaim($agency['id'], 'pending');
        $this->seedInsuranceClaim($agency['id'], 'settled');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/dashboards/operational?agency_public_id='.$agency['public_id']);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.agency_public_id', $agency['public_id']);
        $response->assertJsonPath('data.currency', 'XAF');
        self::assertIsString($response->json('data.data_freshness_at'));
        $response->assertJsonStructure([
            'data' => [
                'portfolio_outstanding_minor',
                'par' => ['par30_outstanding_at_risk_minor', 'par60_outstanding_at_risk_minor', 'par90_outstanding_at_risk_minor'],
                'collections' => ['expected_collection_minor', 'actual_collection_minor', 'performance_ratio'],
                'cash_position_minor',
                'teller_variances' => ['closed_count', 'variance_count', 'variance_total_abs_minor'],
                'insurance_premiums' => ['assessed_minor', 'paid_minor', 'due_count', 'paid_count'],
                'claims_by_status',
            ],
        ]);
        $response->assertJsonPath('data.claims_by_status.pending', 1);
        $response->assertJsonPath('data.claims_by_status.settled', 1);
    }

    public function test_operational_dashboard_par_reconciles_to_reporting_policy_and_filters_product_status(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('DSH-PAR');
        $productA = $this->createLoanProduct('DSH-PROD-A');
        $productB = $this->createLoanProduct('DSH-PROD-B');

        $this->seedLoanWithSchedule($agency['id'], $productA['id'], 'active', [
            ['due_date' => '2026-04-30', 'principal_minor' => 500, 'interest_minor' => 0, 'penalty_minor' => 0],
            ['due_date' => '2026-05-20', 'principal_minor' => 1000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);
        $this->seedLoanWithSchedule($agency['id'], $productB['id'], 'rescheduled', [
            ['due_date' => '2026-04-30', 'principal_minor' => 9000, 'interest_minor' => 0, 'penalty_minor' => 0],
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/dashboards/operational?agency_public_id='.$agency['public_id']
                .'&period_ends_on=2026-05-31&loan_product_public_id='.$productA['public_id'].'&loan_status=active');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.portfolio_outstanding_minor', 1500);
        $response->assertJsonPath('data.par.par30_outstanding_at_risk_minor', 1500);
        $response->assertJsonPath('data.par.par60_outstanding_at_risk_minor', 0);
        $response->assertJsonPath('data.loan_product_public_id', $productA['public_id']);
        $response->assertJsonPath('data.loan_status', 'active');
        $response->assertJsonPath('data.metric_sources.par', 'credit_par_delinquency');
    }

    public function test_operational_dashboard_enforces_agency_scope_for_non_admin(): void
    {
        $agencyManager = $this->createUserWithRole('agency-manager');
        $homeAgency = $this->createAgency('DSH-HOME');
        $otherAgency = $this->createAgency('DSH-OTHER');
        $this->assignStaffToAgency($agencyManager, $homeAgency['id']);

        // No agency filter → falls back to assigned agency (200)
        $own = $this->withApiHeaders()
            ->actingAsSanctum($agencyManager)
            ->getJson('/api/v1/dashboards/operational');
        $this->assertJsonSuccess($own);
        $own->assertJsonPath('data.agency_public_id', $homeAgency['public_id']);

        // Asking for a different agency → 403
        $cross = $this->withApiHeaders()
            ->actingAsSanctum($agencyManager)
            ->getJson('/api/v1/dashboards/operational?agency_public_id='.$otherAgency['public_id']);
        $this->assertJsonError($cross, 403);
    }

    public function test_executive_dashboard_excludes_client_pii(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('DSH-EXEC');
        $this->seedClient($agency['id'], 'Identifiable', 'Client');
        $this->seedInsuranceClaim($agency['id'], 'approved');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/dashboards/executive');

        $this->assertJsonSuccess($response);
        $payload = $response->json('data');
        self::assertIsArray($payload);

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Identifiable', $json);
        self::assertStringNotContainsString('client_name', $json);
        self::assertStringNotContainsString('first_name', $json);
        self::assertStringNotContainsString('last_name', $json);
        self::assertStringNotContainsString('phone_number', $json);

        $response->assertJsonStructure([
            'data' => [
                'portfolio_outstanding_minor',
                'par_total_minor',
                'par_buckets',
                'collections',
                'insurance_premium_totals',
                'claim_counts',
                'data_freshness_at',
            ],
        ]);
    }

    public function test_executive_dashboard_denies_non_platform_admin(): void
    {
        $agencyManager = $this->createUserWithRole('agency-manager');
        $agency = $this->createAgency('DSH-EXEC-DENY');
        $this->assignStaffToAgency($agencyManager, $agency['id']);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($agencyManager)
            ->getJson('/api/v1/dashboards/executive');
        $this->assertJsonError($response, 403);
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

    private function assignStaffToAgency(User $user, int $agencyId): void
    {
        StaffAgencyAssignment::query()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'role_at_agency' => 'agency-manager',
            'starts_on' => now()->subDay()->toDateString(),
            'is_primary' => true,
            'status' => StaffAgencyAssignment::STATUS_ACTIVE,
        ]);
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

    private function seedClient(int $agencyId, string $firstName, string $lastName): int
    {
        return DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'status' => 'active',
            'kyc_status' => 'verified',
            'phone_number' => '+237600000099',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    /**
     * @param list<array{due_date:string, principal_minor:int, interest_minor:int, penalty_minor:int}> $lines
     */
    private function seedLoanWithSchedule(int $agencyId, int $productId, string $status, array $lines): int
    {
        $clientId = $this->seedClient($agencyId, 'Loan', 'Client');
        $loanId = DB::table('loans')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'loan_product_id' => $productId,
            'loan_number' => 'LOAN-'.Str::ulid(),
            'requested_amount_minor' => 10000,
            'approved_principal_minor' => 10000,
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

        foreach ($lines as $index => $line) {
            DB::table('loan_schedule_lines')->insert([
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

        return $loanId;
    }

    private function seedInsuranceClaim(int $agencyId, string $status): void
    {
        $clientId = $this->seedClient($agencyId, 'Insurance', 'Holder');

        $partnerId = DB::table('insurance_partners')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => 'INS-'.Str::random(4),
            'name' => 'Partner',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = DB::table('insurance_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'insurance_partner_id' => $partnerId,
            'code' => 'INSP-'.Str::random(4),
            'name' => 'Product',
            'product_type' => 'health',
            'currency' => 'XAF',
            'is_refundable' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $subscriptionId = DB::table('insurance_subscriptions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'insurance_product_id' => $productId,
            'subscription_number' => 'SUB-'.Str::ulid(),
            'currency' => 'XAF',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('insurance_claims')->insert([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'insurance_subscription_id' => $subscriptionId,
            'claim_number' => 'CLM-'.Str::ulid(),
            'claim_type' => 'health',
            'status' => $status,
            'currency' => 'XAF',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
