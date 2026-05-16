<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class InsuranceModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_create_insurance_product_and_process_claim_lifecycle(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('INS01');
        $ledger = $this->createLedgerAccount($agency['id']);
        $client = $this->createClient($agency['id']);

        $partner = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-partners', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'INS-PARTNER-1',
                'name' => 'Insurance Partner',
                'email' => 'claims@example.test',
            ]);
        $this->assertJsonSuccess($partner, 201);
        $partnerPublicId = $this->requireStringJsonPath($partner, 'data.public_id');

        $product = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-products', [
                'insurance_partner_public_id' => $partnerPublicId,
                'code' => 'LOAN-COVER-1',
                'name' => 'Loan Cover',
                'product_type' => 'loan_insurance',
                'premium_calculation_type' => 'percentage',
                'premium_rate' => '2.000000',
                'currency' => 'XAF',
                'payment_mode' => 'upfront',
                'coverages' => [
                    [
                        'coverage_code' => 'DEATH',
                        'coverage_name' => 'Death cover',
                    ],
                ],
            ]);
        $this->assertJsonSuccess($product, 201);
        $productPublicId = $this->requireStringJsonPath($product, 'data.public_id');

        $subscription = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-subscriptions', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'insurance_product_public_id' => $productPublicId,
                'starts_on' => '2026-05-13',
                'coverage_amount_minor' => 500000,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($subscription, 201);
        $subscriptionPublicId = $this->requireStringJsonPath($subscription, 'data.public_id');

        $claim = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims', [
                'insurance_subscription_public_id' => $subscriptionPublicId,
                'claim_type' => 'death',
                'incident_date' => '2026-05-14',
                'claimed_amount_minor' => 300000,
                'currency' => 'XAF',
                'description' => 'Borrower claim file opened.',
            ]);
        $this->assertJsonSuccess($claim, 201);
        $claimPublicId = $this->requireStringJsonPath($claim, 'data.public_id');
        $claim->assertJsonPath('data.status', 'pending');

        $settled = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/insurance-claims/'.$claimPublicId.'/decision', [
                'decision' => 'settle',
                'indemnified_amount_minor' => 250000,
                'settled_on' => '2026-05-20',
            ]);
        $this->assertJsonSuccess($settled);
        $settled->assertJsonPath('data.status', 'settled');
        $settled->assertJsonPath('data.indemnified_amount_minor', 250000);

        $this->assertDatabaseHas('insurance_product_coverages', [
            'coverage_code' => 'DEATH',
            'coverage_name' => 'Death cover',
        ]);
        $this->assertDatabaseHas('insurance_claims', [
            'public_id' => $claimPublicId,
            'status' => 'settled',
            'indemnified_amount_minor' => 250000,
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

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
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
            'code' => 'INS-'.Str::ulid(),
            'name' => 'Insurance Ledger',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_LIABILITY,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_CREDIT,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createClient(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('clients')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Insurance',
            'last_name' => 'Client',
            'status' => 'active',
            'kyc_status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }
}
