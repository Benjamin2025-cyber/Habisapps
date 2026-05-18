<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\AccountProduct;
use App\Models\Client;
use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Module3AccountingProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_manage_account_products(): void
    {
        $agency = $this->createAgency('AP01');
        $actor = $this->createUserWithRole('platform-admin');
        $ledger = $this->createLedgerAccount($agency['id']);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-create')->plainTextToken])
            ->postJson('/api/v1/account-products', [
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledger['public_id'],
                'code' => 'SAV-ORD',
                'name' => 'Ordinary Savings',
                'account_family' => AccountProduct::FAMILY_SAVINGS,
                'minimum_balance_minor' => 5000,
                'currency' => 'xaf',
                'allows_recovery_debit' => true,
                'is_ordinary_savings' => true,
                'rules' => ['minimum_balance_policy' => 'product_default'],
            ]);

        $this->assertJsonSuccess($create, 201);
        $productPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.code', 'SAV-ORD');
        $create->assertJsonPath('data.currency', 'XAF');
        $create->assertJsonPath('data.minimum_balance_minor', 5000);
        $create->assertJsonPath('data.ledger_account_public_id', $ledger['public_id']);

        $duplicate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-duplicate')->plainTextToken])
            ->postJson('/api/v1/account-products', [
                'agency_public_id' => $agency['public_id'],
                'code' => 'SAV-ORD',
                'name' => 'Duplicate Savings',
                'account_family' => AccountProduct::FAMILY_SAVINGS,
            ]);
        $this->assertJsonError($duplicate, 422, 'Account product code already exists for this agency scope.');

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-update')->plainTextToken])
            ->patchJson('/api/v1/account-products/'.$productPublicId, [
                'name' => 'Ordinary Savings Updated',
                'minimum_balance_minor' => 7500,
            ]);
        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.name', 'Ordinary Savings Updated');
        $update->assertJsonPath('data.minimum_balance_minor', 7500);

        $list = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-list')->plainTextToken])
            ->getJson('/api/v1/account-products?account_family=savings');
        $list->assertOk();
        $list->assertJsonPath('success', true);
        $list->assertJsonPath('message', 'Account products retrieved successfully');
        $list->assertJsonPath('errors', null);
        $list->assertJsonPath('data.account_products.0.public_id', $productPublicId);

        $archive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-product-archive')->plainTextToken])
            ->deleteJson('/api/v1/account-products/'.$productPublicId);
        $this->assertJsonSuccess($archive);

        $this->assertDatabaseHas('account_products', [
            'public_id' => $productPublicId,
            'status' => AccountProduct::STATUS_ARCHIVED,
        ]);
    }

    public function test_customer_account_creation_uses_active_account_product_rules(): void
    {
        $agency = $this->createAgency('AP02');
        $actor = $this->createUserWithRole('platform-admin');
        $client = $this->createVerifiedClient($agency['id']);
        $ledger = $this->createLedgerAccount($agency['id']);
        $productPublicId = (string) Str::ulid();

        DB::table('account_products')->insert([
            'public_id' => $productPublicId,
            'agency_id' => $agency['id'],
            'ledger_account_id' => $ledger['id'],
            'code' => 'REC-001',
            'name' => 'Recovery Account',
            'account_family' => AccountProduct::FAMILY_RECOVERY,
            'minimum_balance_minor' => 0,
            'currency' => 'XAF',
            'allows_recovery_debit' => true,
            'is_recovery_account' => true,
            'status' => AccountProduct::STATUS_ACTIVE,
        ]);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account-create')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'account_product_public_id' => $productPublicId,
                'account_number' => 'ACC-REC-001',
                'account_title' => 'Recovery Account',
                'opened_on' => '2026-05-11',
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.account_product_public_id', $productPublicId);
        $create->assertJsonPath('data.ledger_account_public_id', $ledger['public_id']);
        $create->assertJsonPath('data.account_type', AccountProduct::FAMILY_RECOVERY);
        $create->assertJsonPath('data.currency', 'XAF');

        $inactiveProductPublicId = (string) Str::ulid();
        DB::table('account_products')->insert([
            'public_id' => $inactiveProductPublicId,
            'agency_id' => $agency['id'],
            'code' => 'SAV-INACTIVE',
            'name' => 'Inactive Savings',
            'account_family' => AccountProduct::FAMILY_SAVINGS,
            'status' => AccountProduct::STATUS_INACTIVE,
        ]);

        $inactive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account-inactive')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client['public_id'],
                'agency_public_id' => $agency['public_id'],
                'account_product_public_id' => $inactiveProductPublicId,
                'account_number' => 'ACC-INACTIVE-001',
                'opened_on' => '2026-05-11',
            ]);

        $inactive->assertStatus(422);
        $inactive->assertJsonValidationErrors(['account_product_public_id']);
    }

    public function test_platform_admin_can_manage_emf_regulatory_account_hierarchy(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $parent = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-parent')->plainTextToken])
            ->postJson('/api/v1/emf-regulatory-accounts', [
                'code' => '10',
                'name' => 'Treasury And Cash',
                'account_class' => 'asset',
                'metadata' => ['source' => 'COBAC'],
            ]);
        $this->assertJsonSuccess($parent, 201);
        $parentPublicId = $this->requireStringJsonPath($parent, 'data.public_id');

        $child = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-child')->plainTextToken])
            ->postJson('/api/v1/emf-regulatory-accounts', [
                'parent_public_id' => $parentPublicId,
                'code' => '101',
                'name' => 'Cash In Till',
                'account_class' => 'asset',
            ]);
        $this->assertJsonSuccess($child, 201);
        $childPublicId = $this->requireStringJsonPath($child, 'data.public_id');
        $child->assertJsonPath('data.parent_public_id', $parentPublicId);

        $cycle = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-cycle')->plainTextToken])
            ->patchJson('/api/v1/emf-regulatory-accounts/'.$parentPublicId, [
                'parent_public_id' => $childPublicId,
            ]);
        $cycle->assertStatus(422);
        $cycle->assertJsonValidationErrors(['parent_public_id']);

        $parentArchive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-parent-archive')->plainTextToken])
            ->deleteJson('/api/v1/emf-regulatory-accounts/'.$parentPublicId);
        $this->assertJsonError($parentArchive, 422, 'EMF regulatory account cannot be archived while child accounts or ledger mappings exist.');

        $childArchive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-child-archive')->plainTextToken])
            ->deleteJson('/api/v1/emf-regulatory-accounts/'.$childPublicId);
        $this->assertJsonSuccess($childArchive);

        $this->assertDatabaseHas('emf_regulatory_accounts', [
            'public_id' => $childPublicId,
            'status' => 'archived',
        ]);
    }

    public function test_platform_admin_can_manage_emf_ledger_account_mappings(): void
    {
        $agency = $this->createAgency('EM01');
        $actor = $this->createUserWithRole('platform-admin');
        $ledger = $this->createLedgerAccount($agency['id']);
        $emfPublicId = (string) Str::ulid();

        DB::table('emf_regulatory_accounts')->insert([
            'public_id' => $emfPublicId,
            'code' => '201',
            'name' => 'Customer Deposits',
            'account_class' => 'liability',
            'status' => 'active',
        ]);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-create')->plainTextToken])
            ->postJson('/api/v1/emf-ledger-account-mappings', [
                'emf_regulatory_account_public_id' => $emfPublicId,
                'ledger_account_public_id' => $ledger['public_id'],
            ]);

        $this->assertJsonSuccess($create, 201);
        $mappingPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.emf_regulatory_account_public_id', $emfPublicId);
        $create->assertJsonPath('data.ledger_account_public_id', $ledger['public_id']);
        $create->assertJsonPath('data.ledger_account_agency_public_id', $agency['public_id']);

        $duplicate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-duplicate')->plainTextToken])
            ->postJson('/api/v1/emf-ledger-account-mappings', [
                'emf_regulatory_account_public_id' => $emfPublicId,
                'ledger_account_public_id' => $ledger['public_id'],
            ]);
        $this->assertJsonError($duplicate, 422, 'EMF ledger account mapping already exists.');

        $list = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-list')->plainTextToken])
            ->getJson('/api/v1/emf-ledger-account-mappings?ledger_account_public_id='.$ledger['public_id']);
        $list->assertOk();
        $list->assertJsonPath('data.emf_ledger_account_mappings.0.public_id', $mappingPublicId);

        $inactive = $this->createLedgerAccount($agency['id'], LedgerAccount::STATUS_INACTIVE);
        $inactiveResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-inactive')->plainTextToken])
            ->postJson('/api/v1/emf-ledger-account-mappings', [
                'emf_regulatory_account_public_id' => $emfPublicId,
                'ledger_account_public_id' => $inactive['public_id'],
            ]);
        $inactiveResponse->assertStatus(422);
        $inactiveResponse->assertJsonValidationErrors(['ledger_account_public_id']);

        $archive = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('emf-map-archive')->plainTextToken])
            ->deleteJson('/api/v1/emf-ledger-account-mappings/'.$mappingPublicId);
        $this->assertJsonSuccess($archive);

        $this->assertDatabaseHas('emf_ledger_account_mappings', [
            'public_id' => $mappingPublicId,
            'status' => 'archived',
        ]);
    }

    public function test_platform_admin_can_manage_operation_codes_and_account_mappings_without_posting(): void
    {
        $agency = $this->createAgency('OP01');
        $actor = $this->createUserWithRole('platform-admin');
        $debit = $this->createLedgerAccount($agency['id']);
        $credit = $this->createLedgerAccount($agency['id']);

        $codeResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-code-create')->plainTextToken])
            ->postJson('/api/v1/operation-codes', [
                'code' => 'LOAN_DISBURSEMENT',
                'label' => 'Loan disbursement',
                'module' => 'loan',
                'operation_type' => 'disbursement',
                'direction' => 'debit_credit',
                'metadata' => ['protected' => true],
            ]);

        $this->assertJsonSuccess($codeResponse, 201);
        $operationCodePublicId = $this->requireStringJsonPath($codeResponse, 'data.public_id');
        $codeResponse->assertJsonPath('data.module', 'loan');

        $mappingResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-map-create')->plainTextToken])
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $operationCodePublicId,
                'debit_ledger_account_public_id' => $debit['public_id'],
                'credit_ledger_account_public_id' => $credit['public_id'],
                'currency' => 'xaf',
                'rules' => ['source' => 'loan_disbursement_workflow'],
            ]);

        $this->assertJsonSuccess($mappingResponse, 201);
        $mappingPublicId = $this->requireStringJsonPath($mappingResponse, 'data.public_id');
        $mappingResponse->assertJsonPath('data.operation_code_public_id', $operationCodePublicId);
        $mappingResponse->assertJsonPath('data.currency', 'XAF');

        $crossAgencyCredit = $this->createLedgerAccount($this->createAgency('OP02')['id']);
        $crossAgencyResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-map-cross')->plainTextToken])
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $operationCodePublicId,
                'debit_ledger_account_public_id' => $debit['public_id'],
                'credit_ledger_account_public_id' => $crossAgencyCredit['public_id'],
            ]);
        $this->assertJsonError($crossAgencyResponse, 422, 'Debit and credit ledger accounts must belong to the same agency.');

        $codeArchiveBlocked = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-code-blocked')->plainTextToken])
            ->deleteJson('/api/v1/operation-codes/'.$operationCodePublicId);
        $this->assertJsonError($codeArchiveBlocked, 422, 'Operation code cannot be archived while active or inactive account mappings exist.');

        $archiveMapping = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('operation-map-archive')->plainTextToken])
            ->deleteJson('/api/v1/operation-account-mappings/'.$mappingPublicId);
        $this->assertJsonSuccess($archiveMapping);

        $this->assertDatabaseHas('operation_account_mappings', [
            'public_id' => $mappingPublicId,
            'status' => 'archived',
        ]);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertDatabaseCount('journal_lines', 0);
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
        ]);

        return ['id' => $id, 'public_id' => $publicId];
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
    private function createLedgerAccount(int $agencyId, string $status = LedgerAccount::STATUS_ACTIVE): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('ledger_accounts')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'code' => 'LA-'.Str::ulid(),
            'name' => 'Customer Control',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_ASSET,
            'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
            'status' => $status,
        ]);

        return ['id' => $id, 'public_id' => $publicId];
    }

    /**
     * @return array{id:int, public_id:string}
     */
    private function createVerifiedClient(int $agencyId): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('clients')->insertGetId([
            'public_id' => $publicId,
            'agency_id' => $agencyId,
            'client_reference' => 'CL-'.Str::ulid(),
            'first_name' => 'Account',
            'last_name' => 'Owner',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_VERIFIED,
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
