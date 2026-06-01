<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountHold;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountSignature;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Module3AccountingArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_create_and_view_ledger_account(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-01');

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-create')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '1000',
                'name' => 'Cash on Hand',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
                'status' => 'active',
            ]);

        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.public_id', fn (mixed $value): bool => is_string($value) && $value !== '');
        $create->assertJsonPath('data.code', '1000');

        $ledgerPublicId = $this->requireStringJsonPath($create, 'data.public_id');

        $show = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-show')->plainTextToken])
            ->getJson('/api/v1/ledger-accounts/'.$ledgerPublicId);

        $this->assertJsonSuccess($show);
        $show->assertJsonPath('data.code', '1000');
        $show->assertJsonPath('data.account_class', 'asset');
    }

    public function test_ledger_account_creation_requires_agency_scope(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-no-agency')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'code' => '1001',
                'name' => 'Global Ledger Attempt',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['agency_public_id']);
    }

    public function test_parent_account_must_exist_before_linking(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-02');

        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-parent')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '2000',
                'name' => 'Savings',
                'account_class' => 'asset',
                'parent_account_public_id' => (string) Str::ulid(),
                'normal_balance_side' => 'debit',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['parent_account_public_id']);
    }

    public function test_parent_account_is_persisted_when_creating_ledger_account(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-12');

        $parent = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-parent-create')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '2100',
                'name' => 'Parent Cash',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $parentPublicId = $this->requireStringJsonPath($parent, 'data.public_id');

        $child = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-child-create')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '2110',
                'name' => 'Child Cash',
                'account_class' => 'asset',
                'parent_account_public_id' => $parentPublicId,
                'normal_balance_side' => 'debit',
            ]);

        $this->assertJsonSuccess($child, 201);
        $child->assertJsonPath('data.parent_account_public_id', $parentPublicId);
    }

    public function test_platform_admin_can_create_sector_and_sub_sector(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $sectorCreate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('sector-create')->plainTextToken])
            ->postJson('/api/v1/sectors', [
                'code' => 'AGR',
                'name' => 'Agriculture',
                'status' => 'active',
            ]);

        $this->assertJsonSuccess($sectorCreate, 201);
        $sectorPublicId = $this->requireStringJsonPath($sectorCreate, 'data.public_id');
        $sectorCreate->assertJsonPath('data.code', 'AGR');

        $subSectorCreate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('sub-sector-create')->plainTextToken])
            ->postJson('/api/v1/sub-sectors', [
                'sector_public_id' => $sectorPublicId,
                'code' => 'AGR-01',
                'name' => 'Crop Production',
                'status' => 'active',
            ]);

        $this->assertJsonSuccess($subSectorCreate, 201);
        $subSectorCreate->assertJsonPath('data.sector_public_id', $sectorPublicId);
        $subSectorCreate->assertJsonPath('data.code', 'AGR-01');
    }

    public function test_platform_admin_can_create_customer_account_and_hold(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-03');

        $ledger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-for-account')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '3000',
                'name' => 'Customer Deposits',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $client = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);

        $customerAccount = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledgerPublicId,
                'account_number' => 'CA-1001',
                'opened_on' => now()->toDateString(),
                'status' => 'active',
            ]);

        $this->assertJsonSuccess($customerAccount, 201);
        $customerAccountPublicId = $this->requireStringJsonPath($customerAccount, 'data.public_id');

        $hold = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-hold')->plainTextToken])
            ->postJson('/api/v1/account-holds', [
                'customer_account_public_id' => $customerAccountPublicId,
                'amount_minor' => 1500,
                'currency' => 'XAF',
                'reason_type' => 'kyc_review',
            ]);

        $this->assertJsonSuccess($hold, 201);
        $hold->assertJsonPath('data.customer_account_public_id', $customerAccountPublicId);
        $hold->assertJsonPath('data.amount_minor', 1500);
    }

    public function test_platform_admin_can_manage_document_backed_account_signatures(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-SIG-01');
        $clientPublicId = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);
        $accountPublicId = $this->createCustomerAccount($agency['id'], $clientPublicId, 'SIG-ACC-001');
        $documentPublicId = $this->createDocument($agency['id'], 'account_signature');

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures', [
                'document_public_id' => $documentPublicId,
                'signature_type' => CustomerAccountSignature::TYPE_PRIMARY_HOLDER,
                'signer_name' => 'Client Account',
                'signer_role' => 'account_holder',
                'captured_on' => '2026-05-18',
                'metadata' => ['capture_channel' => 'branch_scan'],
            ]);

        $this->assertJsonSuccess($create, 201);
        $signaturePublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.customer_account_public_id', $accountPublicId);
        $create->assertJsonPath('data.document_public_id', $documentPublicId);
        $create->assertJsonPath('data.status', CustomerAccountSignature::STATUS_ACTIVE);
        $create->assertJsonMissingPath('data.path');

        $duplicateDocumentPublicId = $this->createDocument($agency['id'], 'signature_card');
        $duplicate = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures', [
                'document_public_id' => $duplicateDocumentPublicId,
                'signature_type' => CustomerAccountSignature::TYPE_PRIMARY_HOLDER,
            ]);
        $duplicate->assertStatus(422);
        $duplicate->assertJsonValidationErrors(['signature_type']);

        $verify = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures/'.$signaturePublicId.'/verify');
        $this->assertJsonSuccess($verify);
        $verify->assertJsonPath('data.verified_by_user_public_id', $actor->public_id);
        $verify->assertJsonPath('data.verified_at', fn (mixed $value): bool => is_string($value) && $value !== '');

        $list = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures');
        $list->assertOk();
        $list->assertJsonPath('data.signatures.0.public_id', $signaturePublicId);
        $list->assertJsonMissingPath('data.signatures.0.path');

        $revoke = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures/'.$signaturePublicId.'/revoke', [
                'reason' => 'Updated signature card provided.',
            ]);
        $this->assertJsonSuccess($revoke);
        $revoke->assertJsonPath('data.status', CustomerAccountSignature::STATUS_REVOKED);
        $revoke->assertJsonPath('data.revoked_by_user_public_id', $actor->public_id);

        $this->assertDatabaseHas('customer_account_signatures', [
            'public_id' => $signaturePublicId,
            'status' => CustomerAccountSignature::STATUS_REVOKED,
        ]);
    }

    public function test_account_signature_document_must_stay_in_account_agency_scope(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-SIG-02');
        $otherAgency = $this->createAgency('ACCT-SIG-03');
        $clientPublicId = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);
        $accountPublicId = $this->createCustomerAccount($agency['id'], $clientPublicId, 'SIG-ACC-002');
        $otherAgencyDocumentPublicId = $this->createDocument($otherAgency['id'], 'account_signature');
        $kycDocumentPublicId = $this->createDocument($agency['id'], 'kyc');

        $crossAgency = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures', [
                'document_public_id' => $otherAgencyDocumentPublicId,
                'signature_type' => CustomerAccountSignature::TYPE_PRIMARY_HOLDER,
            ]);
        $crossAgency->assertStatus(422);
        $crossAgency->assertJsonValidationErrors(['document_public_id']);

        $wrongCategory = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures', [
                'document_public_id' => $kycDocumentPublicId,
                'signature_type' => CustomerAccountSignature::TYPE_PRIMARY_HOLDER,
            ]);
        $wrongCategory->assertStatus(422);
        $wrongCategory->assertJsonValidationErrors(['document_public_id']);
    }

    public function test_proxy_account_signature_requires_verified_proxy_mandate(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-SIG-04');
        $clientPublicId = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);
        $clientId = DB::table('clients')->where('public_id', $clientPublicId)->value('id');
        self::assertIsInt($clientId);
        $accountPublicId = $this->createCustomerAccount($agency['id'], $clientPublicId, 'SIG-ACC-003');
        $accountId = DB::table('customer_accounts')->where('public_id', $accountPublicId)->value('id');
        self::assertIsInt($accountId);
        $documentPublicId = $this->createDocument($agency['id'], 'signature');
        $proxyPublicId = (string) Str::ulid();

        DB::table('client_proxies')->insert([
            'public_id' => $proxyPublicId,
            'agency_id' => $agency['id'],
            'client_id' => $clientId,
            'customer_account_id' => $accountId,
            'proxy_full_name' => 'Authorized Proxy',
            'mandate_type' => 'withdrawal',
            'status' => 'active',
            'verification_status' => 'verified',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $create = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures', [
                'document_public_id' => $documentPublicId,
                'client_proxy_public_id' => $proxyPublicId,
                'signature_type' => CustomerAccountSignature::TYPE_PROXY,
                'signer_name' => 'Authorized Proxy',
            ]);
        $this->assertJsonSuccess($create, 201);
        $create->assertJsonPath('data.client_proxy_public_id', $proxyPublicId);

        $missingProxyDocumentPublicId = $this->createDocument($agency['id'], 'signature');
        $missingProxy = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/customer-accounts/'.$accountPublicId.'/signatures', [
                'document_public_id' => $missingProxyDocumentPublicId,
                'signature_type' => CustomerAccountSignature::TYPE_MANDATE,
            ]);
        $missingProxy->assertStatus(422);
        $missingProxy->assertJsonValidationErrors(['client_proxy_public_id']);
    }

    public function test_unverified_client_cannot_open_customer_account(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-05');

        $ledger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-unverified')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '3100',
                'name' => 'Blocked Deposits',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $client = $this->createClient($agency['id'], Client::KYC_STATUS_DRAFT);

        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account-unverified')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledgerPublicId,
                'account_number' => 'CA-2001',
                'opened_on' => now()->toDateString(),
                'status' => 'active',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_public_id']);
    }

    public function test_customer_accounts_and_journal_lines_reject_inactive_ledger_accounts(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-19');

        $inactiveLedger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('inactive-ledger')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '5500',
                'name' => 'Inactive Ledger',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
                'status' => 'inactive',
            ]);
        $inactiveLedgerPublicId = $this->requireStringJsonPath($inactiveLedger, 'data.public_id');

        $activeLedger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('active-ledger')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '5600',
                'name' => 'Active Ledger',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $activeLedgerPublicId = $this->requireStringJsonPath($activeLedger, 'data.public_id');

        $client = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);

        $inactiveAccount = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('inactive-ledger-account')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $inactiveLedgerPublicId,
                'account_number' => 'CA-6001',
                'opened_on' => now()->toDateString(),
            ]);
        $inactiveAccount->assertStatus(422);
        $inactiveAccount->assertJsonValidationErrors(['ledger_account_public_id']);

        $account = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('active-ledger-account')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $activeLedgerPublicId,
                'account_number' => 'CA-6002',
                'opened_on' => now()->toDateString(),
            ]);
        $accountPublicId = $this->requireStringJsonPath($account, 'data.public_id');

        $inactiveUpdate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('inactive-ledger-account-update')->plainTextToken])
            ->patchJson('/api/v1/customer-accounts/'.$accountPublicId, [
                'ledger_account_public_id' => $inactiveLedgerPublicId,
            ]);
        $inactiveUpdate->assertStatus(422);
        $inactiveUpdate->assertJsonValidationErrors(['ledger_account_public_id']);

        $entry = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('inactive-ledger-entry')->plainTextToken])
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-4001',
                'business_date' => now()->toDateString(),
                'agency_public_id' => $agency['public_id'],
            ]);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');

        $line = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('inactive-ledger-line')->plainTextToken])
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $inactiveLedgerPublicId,
                'debit_minor' => 100,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ]);
        $line->assertStatus(422);
        $line->assertJsonValidationErrors(['ledger_account_public_id']);
    }

    public function test_customer_account_list_supports_filters(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-09');

        $ledger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-filter')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '5100',
                'name' => 'Filter Ledger',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $client = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);
        $accountA = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account-a')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledgerPublicId,
                'account_number' => 'CA-4001',
                'opened_on' => '2026-01-01',
                'status' => CustomerAccount::STATUS_ACTIVE,
            ]);
        $accountAPublicId = $this->requireStringJsonPath($accountA, 'data.public_id');

        $accountB = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account-b')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledgerPublicId,
                'account_number' => 'CA-4002',
                'opened_on' => '2026-02-01',
                'status' => CustomerAccount::STATUS_SUSPENDED,
            ]);
        $accountBPublicId = $this->requireStringJsonPath($accountB, 'data.public_id');

        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account-list')->plainTextToken])
            ->getJson('/api/v1/customer-accounts?status='.CustomerAccount::STATUS_SUSPENDED.'&account_number=CA-4002');

        $this->assertJsonSuccess($response);
        $response->assertJsonCount(1, 'data.customer_accounts');
        $response->assertJsonPath('data.customer_accounts.0.public_id', $accountBPublicId);
        $response->assertJsonMissing(['public_id' => $accountAPublicId]);
    }

    public function test_agency_user_customer_account_list_is_scoped_to_active_agency(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('ACCT-14');
        $agencyB = $this->createAgency('ACCT-15');
        $agencyUser = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);

        $ledgerA = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('ledger-agency-a')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyA['public_id'],
                'code' => '5200',
                'name' => 'Agency A Customer Ledger',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $ledgerAPublicId = $this->requireStringJsonPath($ledgerA, 'data.public_id');

        $ledgerB = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('ledger-agency-b')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyB['public_id'],
                'code' => '5300',
                'name' => 'Agency B Customer Ledger',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $ledgerBPublicId = $this->requireStringJsonPath($ledgerB, 'data.public_id');

        $clientA = $this->createClient($agencyA['id'], Client::KYC_STATUS_VERIFIED);
        $clientB = $this->createClient($agencyB['id'], Client::KYC_STATUS_VERIFIED);

        $accountA = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('account-agency-a')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $clientA,
                'agency_public_id' => $agencyA['public_id'],
                'ledger_account_public_id' => $ledgerAPublicId,
                'account_number' => 'CA-4101',
                'opened_on' => now()->toDateString(),
            ]);
        $accountAPublicId = $this->requireStringJsonPath($accountA, 'data.public_id');

        $accountB = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('account-agency-b')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $clientB,
                'agency_public_id' => $agencyB['public_id'],
                'ledger_account_public_id' => $ledgerBPublicId,
                'account_number' => 'CA-4102',
                'opened_on' => now()->toDateString(),
            ]);
        $accountBPublicId = $this->requireStringJsonPath($accountB, 'data.public_id');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($agencyUser)
            ->getJson('/api/v1/customer-accounts');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.customer_accounts.0.public_id', $accountAPublicId);
        $response->assertJsonMissing(['public_id' => $accountBPublicId]);
    }

    public function test_platform_admin_can_create_journal_entry_and_line(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $reversalApprover = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-04');

        $ledger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-ledger')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '4000',
                'name' => 'Suspense',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $entry = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-entry')->plainTextToken])
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-1001',
                'business_date' => now()->toDateString(),
                'agency_public_id' => $agency['public_id'],
                'description' => 'Initial journal entry',
            ]);

        $this->assertJsonSuccess($entry, 201);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');
        $entry->assertJsonPath('data.reference', 'JE-1001');

        $line = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-line')->plainTextToken])
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'debit_minor' => 2500,
                'credit_minor' => 0,
                'currency' => 'XAF',
                'line_memo' => 'Opening debit',
            ]);

        $this->assertJsonSuccess($line, 201);
        $line->assertJsonPath('data.journal_entry_public_id', $entryPublicId);
        $line->assertJsonPath('data.ledger_account_public_id', $ledgerPublicId);
        $line->assertJsonPath('data.debit_minor', 2500);

        $creditLine = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-line-credit')->plainTextToken])
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'credit_minor' => 2500,
                'debit_minor' => 0,
                'currency' => 'XAF',
                'line_memo' => 'Opening credit',
            ]);

        $this->assertJsonSuccess($creditLine, 201);

        $submit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-submit')->plainTextToken])
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/submit');

        $this->assertJsonSuccess($submit);
        $submit->assertJsonPath('data.status', JournalEntry::STATUS_PENDING_REVIEW);
        $submit->assertJsonPath('data.lines.0.journal_entry_public_id', $entryPublicId);
        $submit->assertJsonPath('data.lines.0.ledger_account_public_id', $ledgerPublicId);
        $submit->assertJsonPath('data.lines.1.journal_entry_public_id', $entryPublicId);
        $submit->assertJsonPath('data.lines.1.ledger_account_public_id', $ledgerPublicId);

        $approve = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/approve', [
                'comment' => 'Ready to post.',
            ]);
        $this->assertJsonSuccess($approve);
        $approve->assertJsonPath('data.status', JournalEntry::STATUS_APPROVED);

        $post = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/post');
        $this->assertJsonSuccess($post);
        $post->assertJsonPath('data.status', JournalEntry::STATUS_POSTED);

        $editPosted = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->patchJson('/api/v1/journal-entries/'.$entryPublicId, [
                'description' => 'Edited after posting',
            ]);
        $editPosted->assertStatus(422);

        $addPostedLine = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'debit_minor' => 1,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ]);
        $addPostedLine->assertStatus(422);

        $duplicatePost = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/post');
        $this->assertJsonSuccess($duplicatePost);
        $duplicatePost->assertJsonPath('data.status', JournalEntry::STATUS_POSTED);

        $reversal = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/reverse');

        $this->assertJsonSuccess($reversal, 201);
        $reversal->assertJsonPath('data.reversal_of_public_id', $entryPublicId);
        $reversal->assertJsonPath('data.status', JournalEntry::STATUS_SUBMITTED);
        $reversalPublicId = $this->requireStringJsonPath($reversal, 'data.public_id');

        $this->assertDatabaseHas('journal_entries', [
            'public_id' => $entryPublicId,
            'status' => JournalEntry::STATUS_POSTED,
        ]);

        $selfApproval = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$reversalPublicId.'/approve', [
                'comment' => 'Self approval should be blocked.',
            ]);
        $selfApproval->assertForbidden();

        $approveReversal = $this->withApiHeaders()
            ->actingAsSanctum($reversalApprover)
            ->postJson('/api/v1/journal-entries/'.$reversalPublicId.'/approve', [
                'comment' => 'Approve reversal.',
            ]);
        $this->assertJsonSuccess($approveReversal);
        $approveReversal->assertJsonPath('data.status', JournalEntry::STATUS_APPROVED);

        $postReversal = $this->withApiHeaders()
            ->actingAsSanctum($reversalApprover)
            ->postJson('/api/v1/journal-entries/'.$reversalPublicId.'/post');
        $this->assertJsonSuccess($postReversal);
        $postReversal->assertJsonPath('data.status', JournalEntry::STATUS_POSTED);

        $this->assertDatabaseHas('journal_entries', [
            'public_id' => $entryPublicId,
            'status' => JournalEntry::STATUS_REVERSED,
        ]);
    }

    public function test_journal_entries_cannot_forge_final_status_on_create(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-16');

        $entry = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-entry-forged')->plainTextToken])
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-3001',
                'business_date' => now()->toDateString(),
                'posted_at' => now()->toDateTimeString(),
                'agency_public_id' => $agency['public_id'],
                'status' => JournalEntry::STATUS_POSTED,
            ]);

        $entry->assertStatus(422);
        $entry->assertJsonValidationErrors(['status', 'posted_at']);
    }

    public function test_journal_review_workflow_requires_reviewer_and_valid_transitions(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-19');

        $ledger = $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('journal-review-ledger')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '4400',
                'name' => 'Review Ledger',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $entryPublicId = $this->createBalancedJournalEntry($maker, $agency['public_id'], $ledgerPublicId, 'JE-4001');

        $submit = $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('journal-review-submit')->plainTextToken])
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/submit');
        $this->assertJsonSuccess($submit);
        $submit->assertJsonPath('data.status', JournalEntry::STATUS_SUBMITTED);

        $makerApproval = $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('journal-review-maker-approve')->plainTextToken])
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/approve', [
                'comment' => 'Looks balanced.',
            ]);
        $makerApproval->assertForbidden();

        $approval = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/approve', [
                'comment' => 'Approved for posting.',
            ]);
        $this->assertJsonSuccess($approval);
        $approval->assertJsonPath('data.status', JournalEntry::STATUS_APPROVED);
        $approval->assertJsonPath('data.reviewed_by_user_public_id', $reviewer->public_id);
        $approval->assertJsonPath('data.review_comment', 'Approved for posting.');

        $rejectApproved = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/reject', [
                'reason' => 'Too late.',
            ]);
        $rejectApproved->assertStatus(422);

        $rejectedEntryPublicId = $this->createBalancedJournalEntry($maker, $agency['public_id'], $ledgerPublicId, 'JE-4002');
        $this->withApiHeaders(['Authorization' => 'Bearer '.$maker->createToken('journal-review-submit-reject')->plainTextToken])
            ->postJson('/api/v1/journal-entries/'.$rejectedEntryPublicId.'/submit')
            ->assertStatus(200);

        $rejection = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$rejectedEntryPublicId.'/reject', [
                'reason' => 'Missing supporting evidence.',
            ]);
        $this->assertJsonSuccess($rejection);
        $rejection->assertJsonPath('data.status', JournalEntry::STATUS_REJECTED);
        $rejection->assertJsonPath('data.rejection_reason', 'Missing supporting evidence.');
    }

    public function test_journal_lines_cannot_mutate_after_submit(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-17');

        $ledger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-mutability-ledger')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '4300',
                'name' => 'Mutability Ledger',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $entry = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-mutability-entry')->plainTextToken])
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-3002',
                'business_date' => now()->toDateString(),
                'agency_public_id' => $agency['public_id'],
            ]);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');

        $debitLine = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-mutability-debit')->plainTextToken])
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'debit_minor' => 1000,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ]);
        $debitLinePublicId = $this->requireStringJsonPath($debitLine, 'data.public_id');

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-mutability-credit')->plainTextToken])
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'debit_minor' => 0,
                'credit_minor' => 1000,
                'currency' => 'XAF',
            ]);

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-mutability-submit')->plainTextToken])
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/submit')
            ->assertStatus(200);

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-mutability-add')->plainTextToken])
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'debit_minor' => 1,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ])
            ->assertStatus(422);

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-mutability-delete')->plainTextToken])
            ->deleteJson('/api/v1/journal-lines/'.$debitLinePublicId)
            ->assertStatus(422);
    }

    public function test_database_rejects_unbalanced_non_draft_journal_entries_at_commit(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-BAL-DB');

        $ledger = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '5500',
                'name' => 'Balance DB Ledger',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');
        $ledgerId = DB::table('ledger_accounts')->where('public_id', $ledgerPublicId)->value('id');
        self::assertIsInt($ledgerId);

        $entryPublicId = $this->createBalancedJournalEntry($actor, $agency['public_id'], $ledgerPublicId, 'JE-BAL-DB-1');
        $entryId = DB::table('journal_entries')->where('public_id', $entryPublicId)->value('id');
        self::assertIsInt($entryId);

        // Draft entries may temporarily be unbalanced; raw insert into a draft entry must succeed.
        DB::transaction(function () use ($agency, $entryId, $ledgerId): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::table('journal_lines')->insert([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agency['id'],
                'journal_entry_id' => $entryId,
                'ledger_account_id' => $ledgerId,
                'debit_minor' => 250,
                'credit_minor' => 0,
                'currency' => 'XAF',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        self::assertSame('draft', DB::table('journal_entries')->where('id', $entryId)->value('status'));

        // Status transition to submitted with unbalanced lines must be rejected.
        $unbalancedSubmit = null;
        try {
            DB::transaction(function () use ($entryId): void {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::table('journal_entries')->where('id', $entryId)->update([
                    'status' => JournalEntry::STATUS_SUBMITTED,
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $unbalancedSubmit = $exception;
        }
        self::assertNotNull($unbalancedSubmit, 'Status update to submitted must be rejected when lines are unbalanced.');
        self::assertStringContainsString('unbalanced', strtolower($unbalancedSubmit->getMessage()));
        self::assertSame('draft', DB::table('journal_entries')->where('id', $entryId)->value('status'));

        // Bring the entry back to balance and submit cleanly.
        DB::transaction(function () use ($entryId): void {
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            DB::table('journal_lines')->where('journal_entry_id', $entryId)->where('debit_minor', 250)->delete();
            DB::table('journal_entries')->where('id', $entryId)->update([
                'status' => JournalEntry::STATUS_SUBMITTED,
                'updated_at' => now(),
            ]);
        });
        self::assertSame(JournalEntry::STATUS_SUBMITTED, DB::table('journal_entries')->where('id', $entryId)->value('status'));

        // Inserting an unbalancing line into a non-draft entry must be rejected.
        $postSubmitInsert = null;
        try {
            DB::transaction(function () use ($agency, $entryId, $ledgerId): void {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::table('journal_lines')->insert([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agency['id'],
                    'journal_entry_id' => $entryId,
                    'ledger_account_id' => $ledgerId,
                    'debit_minor' => 7,
                    'credit_minor' => 0,
                    'currency' => 'XAF',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $postSubmitInsert = $exception;
        }
        self::assertNotNull($postSubmitInsert, 'Inserting an unbalancing line into a non-draft entry must be rejected.');

        // Deleting a balancing line from a submitted entry must also be rejected.
        $oneCreditLineId = DB::table('journal_lines')->where('journal_entry_id', $entryId)->where('credit_minor', '>', 0)->value('id');
        self::assertIsInt($oneCreditLineId);
        $deleteRejected = null;
        try {
            DB::transaction(function () use ($oneCreditLineId): void {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                DB::table('journal_lines')->where('id', $oneCreditLineId)->delete();
            });
        } catch (\Throwable $exception) {
            $deleteRejected = $exception;
        }
        self::assertNotNull($deleteRejected, 'Deleting a balancing line from a non-draft entry must be rejected.');
    }

    public function test_database_blocks_journal_line_mutation_and_status_regression_on_terminal_entries(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-IMM');

        $cashLedger = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '6100',
                'name' => 'Immutability Cash',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $depositLedger = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '6200',
                'name' => 'Immutability Liability',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $cashLedgerPublicId = $this->requireStringJsonPath($cashLedger, 'data.public_id');
        $depositLedgerPublicId = $this->requireStringJsonPath($depositLedger, 'data.public_id');

        $entryPublicId = $this->createPostedJournalEntryWithLines(
            $maker,
            $reviewer,
            $agency['public_id'],
            'JE-IMM-1',
            now()->toDateString(),
            [
                ['ledger_account_public_id' => $cashLedgerPublicId, 'debit_minor' => 5000, 'credit_minor' => 0],
                ['ledger_account_public_id' => $depositLedgerPublicId, 'debit_minor' => 0, 'credit_minor' => 5000],
            ],
        );
        $entryId = DB::table('journal_entries')->where('public_id', $entryPublicId)->value('id');
        self::assertIsInt($entryId);

        // Posted entries cannot regress to draft via raw SQL.
        $regression = null;
        try {
            DB::transaction(function () use ($entryId): void {
                DB::table('journal_entries')->where('id', $entryId)->update([
                    'status' => JournalEntry::STATUS_DRAFT,
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $regression = $exception;
        }
        self::assertNotNull($regression, 'Posted journal entries must not regress to draft.');
        self::assertStringContainsString('posted', strtolower($regression->getMessage()));
        self::assertSame(JournalEntry::STATUS_POSTED, DB::table('journal_entries')->where('id', $entryId)->value('status'));

        // Lines under a posted entry are immutable to UPDATE / DELETE.
        $lineId = DB::table('journal_lines')->where('journal_entry_id', $entryId)->value('id');
        self::assertIsInt($lineId);

        $updateBlocked = null;
        try {
            DB::transaction(function () use ($lineId): void {
                DB::table('journal_lines')->where('id', $lineId)->update([
                    'line_memo' => 'tampering',
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $updateBlocked = $exception;
        }
        self::assertNotNull($updateBlocked, 'UPDATE on a posted entry line must be rejected.');

        $deleteBlocked = null;
        try {
            DB::transaction(function () use ($lineId): void {
                DB::table('journal_lines')->where('id', $lineId)->delete();
            });
        } catch (\Throwable $exception) {
            $deleteBlocked = $exception;
        }
        self::assertNotNull($deleteBlocked, 'DELETE on a posted entry line must be rejected.');

        // Posted entries also cannot leap to other non-reversed terminal states (e.g. submitted).
        $invalidLeap = null;
        try {
            DB::transaction(function () use ($entryId): void {
                DB::table('journal_entries')->where('id', $entryId)->update([
                    'status' => JournalEntry::STATUS_SUBMITTED,
                    'updated_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $invalidLeap = $exception;
        }
        self::assertNotNull($invalidLeap, 'Posted journal entries must not transition to submitted.');
        self::assertSame(JournalEntry::STATUS_POSTED, DB::table('journal_entries')->where('id', $entryId)->value('status'));
    }

    public function test_accounting_balances_are_derived_from_posted_journal_lines(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-20');

        $cashLedger = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '4500',
                'name' => 'Cash Balance Ledger',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $cashLedgerPublicId = $this->requireStringJsonPath($cashLedger, 'data.public_id');

        $depositLedger = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '2500',
                'name' => 'Customer Deposit Ledger',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $depositLedgerPublicId = $this->requireStringJsonPath($depositLedger, 'data.public_id');

        $clientPublicId = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);
        $customerAccount = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $depositLedgerPublicId,
                'account_number' => 'CA-BAL-1',
                'opened_on' => '2026-05-01',
            ]);
        $customerAccountPublicId = $this->requireStringJsonPath($customerAccount, 'data.public_id');

        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-BAL-1', '2026-05-01', [
            ['ledger_account_public_id' => $cashLedgerPublicId, 'debit_minor' => 10000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $depositLedgerPublicId, 'customer_account_public_id' => $customerAccountPublicId, 'debit_minor' => 0, 'credit_minor' => 10000],
        ]);
        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-BAL-2', '2026-05-02', [
            ['ledger_account_public_id' => $depositLedgerPublicId, 'customer_account_public_id' => $customerAccountPublicId, 'debit_minor' => 3000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cashLedgerPublicId, 'debit_minor' => 0, 'credit_minor' => 3000],
        ]);

        $draftOnlyEntry = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-BAL-DRAFT',
                'business_date' => '2026-05-03',
                'agency_public_id' => $agency['public_id'],
            ]);
        $draftOnlyEntryPublicId = $this->requireStringJsonPath($draftOnlyEntry, 'data.public_id');
        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $draftOnlyEntryPublicId,
                'ledger_account_public_id' => $cashLedgerPublicId,
                'debit_minor' => 50000,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ])
            ->assertStatus(201);

        $cashBalance = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$cashLedgerPublicId.'/balance?currency=XAF');
        $this->assertJsonSuccess($cashBalance);
        $cashBalance->assertJsonPath('data.scope', 'ledger_account');
        $cashBalance->assertJsonPath('data.debit_total_minor', 10000);
        $cashBalance->assertJsonPath('data.credit_total_minor', 3000);
        $cashBalance->assertJsonPath('data.balance_minor', 7000);

        $depositBalance = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$depositLedgerPublicId.'/balance?currency=XAF');
        $this->assertJsonSuccess($depositBalance);
        $depositBalance->assertJsonPath('data.debit_total_minor', 3000);
        $depositBalance->assertJsonPath('data.credit_total_minor', 10000);
        $depositBalance->assertJsonPath('data.balance_minor', 7000);
        $depositBalance->assertJsonPath('data.normal_balance_side', LedgerAccount::NORMAL_BALANCE_CREDIT);

        $customerBalance = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/customer-accounts/'.$customerAccountPublicId.'/balance?currency=XAF');
        $this->assertJsonSuccess($customerBalance);
        $customerBalance->assertJsonPath('data.scope', 'customer_account');
        $customerBalance->assertJsonPath('data.balance_minor', 7000);

        $accountProductId = DB::table('account_products')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'ledger_account_id' => DB::table('ledger_accounts')->where('public_id', $depositLedgerPublicId)->value('id'),
            'code' => 'SAV-BAL-1',
            'name' => 'Balance Savings',
            'account_family' => 'savings',
            'minimum_balance_minor' => 5000,
            'currency' => 'XAF',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerAccountId = DB::table('customer_accounts')->where('public_id', $customerAccountPublicId)->value('id');
        self::assertIsInt($customerAccountId);
        DB::table('customer_accounts')
            ->where('id', $customerAccountId)
            ->update([
                'account_product_id' => $accountProductId,
                'unavailable_amount_minor' => 500,
            ]);
        DB::table('account_holds')->insert([
            [
                'public_id' => (string) Str::ulid(),
                'customer_account_id' => $customerAccountId,
                'amount_minor' => 1000,
                'currency' => 'XAF',
                'reason_type' => 'legal_hold',
                'status' => AccountHold::STATUS_ACTIVE,
                'placed_at' => now(),
                'released_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'public_id' => (string) Str::ulid(),
                'customer_account_id' => $customerAccountId,
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'reason_type' => 'released_hold',
                'status' => AccountHold::STATUS_RELEASED,
                'placed_at' => now(),
                'released_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $availableBalance = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/customer-accounts/'.$customerAccountPublicId.'/available-balance?currency=XAF');
        $this->assertJsonSuccess($availableBalance);
        $availableBalance->assertJsonPath('data.accounting_balance_minor', 7000);
        $availableBalance->assertJsonPath('data.minimum_balance_minor', 5000);
        $availableBalance->assertJsonPath('data.unavailable_amount_minor', 500);
        $availableBalance->assertJsonPath('data.active_hold_amount_minor', 1000);
        $availableBalance->assertJsonPath('data.available_balance_minor', 500);

        $periodBalance = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/customer-accounts/'.$customerAccountPublicId.'/balance?currency=XAF&from=2026-05-02&to=2026-05-02');
        $this->assertJsonSuccess($periodBalance);
        $periodBalance->assertJsonPath('data.debit_total_minor', 3000);
        $periodBalance->assertJsonPath('data.credit_total_minor', 0);
        $periodBalance->assertJsonPath('data.balance_minor', -3000);

        $statement = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/customer-accounts/'.$customerAccountPublicId.'/statement?currency=XAF&from=2026-05-02&to=2026-05-02&per_page=1');
        $this->assertJsonSuccess($statement);
        $statement->assertJsonPath('data.statement.opening_balance_minor', 10000);
        $statement->assertJsonPath('data.statement.debit_total_minor', 3000);
        $statement->assertJsonPath('data.statement.credit_total_minor', 0);
        $statement->assertJsonPath('data.statement.closing_balance_minor', 7000);
        $statement->assertJsonPath('data.movements.0.signed_amount_minor', -3000);
        $statement->assertJsonPath('meta.pagination.total', 1);
        $statement->assertJsonMissing(['id' => 1]);

        $ledgerMovements = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$cashLedgerPublicId.'/movements?currency=XAF&per_page=1');
        $this->assertJsonSuccess($ledgerMovements);
        $ledgerMovements->assertJsonPath('data.statement.opening_balance_minor', 0);
        $ledgerMovements->assertJsonPath('data.statement.closing_balance_minor', 7000);
        $ledgerMovements->assertJsonPath('meta.pagination.total', 2);
        $ledgerMovements->assertJsonPath('meta.pagination.per_page', 1);

        $reportDefinitionId = DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'TB-TEST',
            'name' => 'Trial Balance Test',
            'report_type' => 'trial_balance',
            'module' => 'accounting',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $reportDefinition = DB::table('report_definitions')->where('id', $reportDefinitionId)->first(['public_id']);
        self::assertIsObject($reportDefinition);
        self::assertIsString($reportDefinition->public_id);
        $documentId = DB::table('documents')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'category' => 'report_export',
            'title' => 'Trial Balance Export',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $document = DB::table('documents')->where('id', $documentId)->first(['public_id']);
        self::assertIsObject($document);
        self::assertIsString($document->public_id);

        $reportRun = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $reportDefinition->public_id,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-02',
                'currency' => 'XAF',
                'document_public_id' => $document->public_id,
            ]);
        $this->assertJsonSuccess($reportRun, 201);
        $reportRun->assertJsonPath('data.status', 'completed');
        $reportRun->assertJsonPath('data.document_public_id', $document->public_id);
        $reportRun->assertJsonPath('data.summary.report_type', 'trial_balance');
        $reportRun->assertJsonPath('data.summary.debit_total_minor', 13000);
        $reportRun->assertJsonPath('data.summary.credit_total_minor', 13000);
        $reportRun->assertJsonPath('data.summary.row_count', 2);

        $generalLedgerDefinitionId = DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'GL-TEST',
            'name' => 'General Ledger Test',
            'report_type' => 'general_ledger',
            'module' => 'accounting',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $generalLedgerDefinition = DB::table('report_definitions')->where('id', $generalLedgerDefinitionId)->first(['public_id']);
        self::assertIsObject($generalLedgerDefinition);
        self::assertIsString($generalLedgerDefinition->public_id);

        $generalLedgerRun = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $generalLedgerDefinition->public_id,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-02',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($generalLedgerRun, 201);
        $generalLedgerRun->assertJsonPath('data.summary.report_type', 'general_ledger');
        $generalLedgerRun->assertJsonPath('data.summary.line_count', 4);
        $generalLedgerRun->assertJsonPath('data.summary.debit_total_minor', 13000);
        $generalLedgerRun->assertJsonPath('data.summary.credit_total_minor', 13000);

        $regulatorySourceId = DB::table('regulatory_sources')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'authority' => 'cobac',
            'reference' => 'COBAC-RPT-TEST',
            'title' => 'COBAC test reporting source',
            'effective_date' => '2026-01-01',
            'checksum' => hash('sha256', 'cobac-rpt-test'),
            'imported_by_user_id' => $reviewer->id,
            'imported_at' => now(),
            'metadata' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $emfDefinitionId = DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'regulatory_source_id' => $regulatorySourceId,
            'code' => 'EMF-TB-TEST',
            'version' => 1,
            'name' => 'EMF Trial Balance Test',
            'report_type' => 'emf_trial_balance',
            'module' => 'accounting',
            'status' => 'active',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $emfDefinition = DB::table('report_definitions')->where('id', $emfDefinitionId)->first(['public_id']);
        self::assertIsObject($emfDefinition);
        self::assertIsString($emfDefinition->public_id);

        $missingMappingRun = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $emfDefinition->public_id,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-02',
                'currency' => 'XAF',
            ]);
        $missingMappingRun->assertStatus(422);
        $missingMappingRun->assertJsonValidationErrors(['ledger_accounts']);

        $cashLedgerId = DB::table('ledger_accounts')->where('public_id', $cashLedgerPublicId)->value('id');
        $depositLedgerId = DB::table('ledger_accounts')->where('public_id', $depositLedgerPublicId)->value('id');
        self::assertIsInt($cashLedgerId);
        self::assertIsInt($depositLedgerId);
        $cashEmfId = DB::table('emf_regulatory_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'EMF-101',
            'name' => 'EMF Cash',
            'account_class' => 'asset',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $depositEmfId = DB::table('emf_regulatory_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'EMF-201',
            'name' => 'EMF Customer Deposits',
            'account_class' => 'liability',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('emf_ledger_account_mappings')->insert([
            [
                'public_id' => (string) Str::ulid(),
                'emf_regulatory_account_id' => $cashEmfId,
                'ledger_account_id' => $cashLedgerId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'public_id' => (string) Str::ulid(),
                'emf_regulatory_account_id' => $depositEmfId,
                'ledger_account_id' => $depositLedgerId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $emfRun = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $emfDefinition->public_id,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-02',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($emfRun, 201);
        $emfRun->assertJsonPath('data.summary.report_type', 'emf_trial_balance');
        $emfRun->assertJsonPath('data.summary.row_count', 2);
        $emfRun->assertJsonPath('data.summary.debit_total_minor', 13000);
        $emfRun->assertJsonPath('data.summary.credit_total_minor', 13000);
    }

    public function test_account_hold_can_be_released_once(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-08');

        $ledger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-hold-release')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '5000',
                'name' => 'Hold Ledger',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $client = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);
        $account = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('customer-account-hold-release')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledgerPublicId,
                'account_number' => 'CA-3001',
                'opened_on' => now()->toDateString(),
                'status' => 'active',
            ]);
        $customerAccountPublicId = $this->requireStringJsonPath($account, 'data.public_id');

        $hold = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-hold-create')->plainTextToken])
            ->postJson('/api/v1/account-holds', [
                'customer_account_public_id' => $customerAccountPublicId,
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'reason_type' => 'kyc_review',
            ]);
        $holdPublicId = $this->requireStringJsonPath($hold, 'data.public_id');

        $release = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-hold-release')->plainTextToken])
            ->postJson('/api/v1/account-holds/'.$holdPublicId.'/release', [
                'reference' => 'REL-1',
            ]);

        $this->assertJsonSuccess($release);
        $release->assertJsonPath('data.status', 'released');

        $secondRelease = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-hold-release-again')->plainTextToken])
            ->postJson('/api/v1/account-holds/'.$holdPublicId.'/release', [
                'reference' => 'REL-2',
            ]);
        $secondRelease->assertStatus(422);

        $editReleased = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('account-hold-edit-released')->plainTextToken])
            ->patchJson('/api/v1/account-holds/'.$holdPublicId, [
                'reference' => 'EDIT-AFTER-RELEASE',
            ]);
        $editReleased->assertStatus(422);
    }

    public function test_holds_reject_closed_accounts_and_invalid_status(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-18');

        $ledger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-closed-hold')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '5400',
                'name' => 'Closed Hold Ledger',
                'account_class' => 'liability',
                'normal_balance_side' => 'credit',
            ]);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $client = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);
        $account = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('closed-hold-account')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $ledgerPublicId,
                'account_number' => 'CA-5001',
                'opened_on' => now()->toDateString(),
            ]);
        $customerAccountPublicId = $this->requireStringJsonPath($account, 'data.public_id');

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('close-hold-account')->plainTextToken])
            ->patchJson('/api/v1/customer-accounts/'.$customerAccountPublicId, [
                'status' => CustomerAccount::STATUS_CLOSED,
                'closed_on' => now()->toDateString(),
            ])
            ->assertStatus(200);

        $closedAccountHold = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('closed-account-hold')->plainTextToken])
            ->postJson('/api/v1/account-holds', [
                'customer_account_public_id' => $customerAccountPublicId,
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'reason_type' => 'kyc_review',
            ]);
        $closedAccountHold->assertStatus(422);
        $closedAccountHold->assertJsonValidationErrors(['customer_account_public_id']);

        $invalidStatusHold = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('invalid-status-hold')->plainTextToken])
            ->postJson('/api/v1/account-holds', [
                'customer_account_public_id' => $customerAccountPublicId,
                'amount_minor' => 2000,
                'currency' => 'XAF',
                'reason_type' => 'kyc_review',
                'status' => AccountHold::STATUS_RELEASED,
            ]);
        $invalidStatusHold->assertStatus(422);
        $invalidStatusHold->assertJsonValidationErrors(['status']);
    }

    public function test_journal_lines_reject_cross_agency_accounts(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('ACCT-06');
        $agencyB = $this->createAgency('ACCT-07');

        $ledgerA = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-ledger-a')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyA['public_id'],
                'code' => '4100',
                'name' => 'Agency A Ledger',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $ledgerAPublicId = $this->requireStringJsonPath($ledgerA, 'data.public_id');

        $ledgerB = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-ledger-b')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyB['public_id'],
                'code' => '4200',
                'name' => 'Agency B Ledger',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $ledgerBPublicId = $this->requireStringJsonPath($ledgerB, 'data.public_id');

        $entry = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-entry-cross')->plainTextToken])
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-2001',
                'business_date' => now()->toDateString(),
                'agency_public_id' => $agencyA['public_id'],
            ]);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');

        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-line-cross')->plainTextToken])
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerBPublicId,
                'debit_minor' => 500,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ledger_account_public_id']);
    }

    public function test_operational_account_readers_can_view_accounts_and_balances_within_agency(): void
    {
        // FB-BAL-001 / FB-BAL-002: every default role that holds
        // customer.accounts.balance.view can read same-agency accounts and
        // current/available balances, without ledger or statement access.
        $agency = $this->createAgency('ACCT-BAL-ROLE');
        $clientPublicId = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);
        $accountPublicId = $this->createCustomerAccount($agency['id'], $clientPublicId, 'CA-ROLE-1');

        foreach (['teller', 'agency-manager', 'loan-officer', 'accountant', 'kyc-officer', 'user-admin'] as $role) {
            $actor = $this->createUserWithRole($role, $agency['code'], $agency['name']);

            // The deposit/withdrawal page lists a client's accounts by public id.
            $list = $this->withApiHeaders()->actingAsSanctum($actor)
                ->getJson('/api/v1/customer-accounts?client_public_id='.$clientPublicId);
            $this->assertJsonSuccess($list);
            $list->assertJsonPath('data.customer_accounts.0.public_id', $accountPublicId);

            $show = $this->withApiHeaders()->actingAsSanctum($actor)
                ->getJson('/api/v1/customer-accounts/'.$accountPublicId);
            $this->assertJsonSuccess($show);
            $show->assertJsonPath('data.public_id', $accountPublicId);

            $balance = $this->withApiHeaders()->actingAsSanctum($actor)
                ->getJson('/api/v1/customer-accounts/'.$accountPublicId.'/balance?currency=XAF');
            $this->assertJsonSuccess($balance);
            $balance->assertJsonPath('data.scope', 'customer_account');

            $available = $this->withApiHeaders()->actingAsSanctum($actor)
                ->getJson('/api/v1/customer-accounts/'.$accountPublicId.'/available-balance?currency=XAF');
            $this->assertJsonSuccess($available);
            $availableData = $available->json('data');
            self::assertIsArray($availableData);
            foreach (['currency', 'accounting_balance_minor', 'available_balance_minor', 'minimum_balance_minor', 'active_hold_amount_minor', 'unavailable_amount_minor'] as $field) {
                self::assertArrayHasKey($field, $availableData, "{$role} available-balance payload must include {$field}");
            }

            // Statements remain out of scope for operational account readers.
            $statement = $this->withApiHeaders()->actingAsSanctum($actor)
                ->getJson('/api/v1/customer-accounts/'.$accountPublicId.'/statement?currency=XAF');
            $statement->assertForbidden();
        }
    }

    public function test_customer_account_and_balance_access_is_scoped_by_permission_and_agency(): void
    {
        $agencyA = $this->createAgency('ACCT-SCOPE-A');
        $agencyB = $this->createAgency('ACCT-SCOPE-B');
        $clientPublicId = $this->createClient($agencyA['id'], Client::KYC_STATUS_VERIFIED);
        $accountPublicId = $this->createCustomerAccount($agencyA['id'], $clientPublicId, 'CA-SCOPE-1');

        // Cross-agency operational reader: denied account read and balances.
        $foreignTeller = $this->createUserWithRole('teller', $agencyB['code'], $agencyB['name']);
        $this->withApiHeaders()->actingAsSanctum($foreignTeller)
            ->getJson('/api/v1/customer-accounts/'.$accountPublicId)->assertForbidden();
        $this->withApiHeaders()->actingAsSanctum($foreignTeller)
            ->getJson('/api/v1/customer-accounts/'.$accountPublicId.'/balance?currency=XAF')->assertForbidden();
        $this->withApiHeaders()->actingAsSanctum($foreignTeller)
            ->getJson('/api/v1/customer-accounts/'.$accountPublicId.'/available-balance?currency=XAF')->assertForbidden();

        // Same-agency reader with account view but WITHOUT balance permission:
        // can show the account, but balance endpoints stay forbidden.
        $accountOnly = $this->createUserWithRole('staff', $agencyA['code'], $agencyA['name']);
        $accountOnly->givePermissionTo('customer.accounts.view');
        $this->assertJsonSuccess(
            $this->withApiHeaders()->actingAsSanctum($accountOnly)
                ->getJson('/api/v1/customer-accounts/'.$accountPublicId)
        );
        $this->withApiHeaders()->actingAsSanctum($accountOnly)
            ->getJson('/api/v1/customer-accounts/'.$accountPublicId.'/balance?currency=XAF')->assertForbidden();
        $this->withApiHeaders()->actingAsSanctum($accountOnly)
            ->getJson('/api/v1/customer-accounts/'.$accountPublicId.'/available-balance?currency=XAF')->assertForbidden();

        // Operational reader cannot fetch ledger-account balances (no ledger.accounts.view).
        $admin = $this->createUserWithRole('platform-admin');
        $ledger = $this->withApiHeaders()->actingAsSanctum($admin)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyA['public_id'],
                'code' => '7001',
                'name' => 'Scope Cash Ledger',
                'account_class' => 'asset',
                'normal_balance_side' => 'debit',
            ]);
        $this->assertJsonSuccess($ledger, 201);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $teller = $this->createUserWithRole('teller', $agencyA['code'], $agencyA['name']);
        $this->withApiHeaders()->actingAsSanctum($teller)
            ->getJson('/api/v1/ledger-accounts/'.$ledgerPublicId.'/balance?currency=XAF')->assertForbidden();
    }

    private function createUserWithRole(string $role, ?string $agencyCode = null, ?string $agencyName = null): User
    {
        $agency = null;
        if ($agencyCode !== null) {
            $agency = DB::table('agencies')
                ->where('code', $agencyCode)
                ->first(['id', 'code', 'name']);

            if ($agency === null) {
                $agency = (object) $this->createAgency($agencyCode, $agencyName);
            }
        }

        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agency->id ?? null,
            'agency_code' => $agency->code ?? null,
            'agency_name' => $agency->name ?? null,
        ]);

        $user->assignRole($role);

        if ($agency !== null) {
            DB::table('staff_agency_assignments')->insert([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'agency_id' => $agency->id,
                'role_at_agency' => $role,
                'starts_on' => now()->toDateString(),
                'is_primary' => true,
                'status' => 'active',
            ]);
        }

        return $user;
    }

    private function createBalancedJournalEntry(User $actor, string $agencyPublicId, string $ledgerPublicId, string $reference): string
    {
        $entry = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/journal-entries', [
                'reference' => $reference,
                'business_date' => now()->toDateString(),
                'agency_public_id' => $agencyPublicId,
            ]);
        $this->assertJsonSuccess($entry, 201);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'debit_minor' => 1000,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ])
            ->assertStatus(201);

        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'debit_minor' => 0,
                'credit_minor' => 1000,
                'currency' => 'XAF',
            ])
            ->assertStatus(201);

        return $entryPublicId;
    }

    /**
     * @param  array<int, array{ledger_account_public_id:string, customer_account_public_id?:string, debit_minor:int, credit_minor:int}>  $lines
     */
    private function createPostedJournalEntryWithLines(User $maker, User $reviewer, string $agencyPublicId, string $reference, string $businessDate, array $lines): string
    {
        $entry = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/journal-entries', [
                'reference' => $reference,
                'business_date' => $businessDate,
                'agency_public_id' => $agencyPublicId,
            ]);
        $this->assertJsonSuccess($entry, 201);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');

        foreach ($lines as $line) {
            $payload = [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $line['ledger_account_public_id'],
                'debit_minor' => $line['debit_minor'],
                'credit_minor' => $line['credit_minor'],
                'currency' => 'XAF',
            ];

            if (isset($line['customer_account_public_id'])) {
                $payload['customer_account_public_id'] = $line['customer_account_public_id'];
            }

            $this->withApiHeaders()
                ->actingAsSanctum($maker)
                ->postJson('/api/v1/journal-lines', $payload)
                ->assertStatus(201);
        }

        $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/submit')
            ->assertStatus(200);

        $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/approve')
            ->assertStatus(200);

        $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/post')
            ->assertStatus(200);

        return $entryPublicId;
    }

    /**
     * @return array{id:int, code:string, name:string, public_id:string}
     */
    private function createAgency(string $code, ?string $name = null): array
    {
        $name ??= $code.' Agency';
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $agency = DB::table('agencies')->where('id', $id)->first(['public_id']);

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'public_id' => is_object($agency) && is_string($agency->public_id) ? $agency->public_id : '',
        ];
    }

    private function createClient(int $agencyId, string $kycStatus = 'draft'): string
    {
        $clientId = DB::table('clients')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.Str::ulid(),
            'first_name' => 'Client',
            'last_name' => 'Account',
            'status' => 'active',
            'kyc_status' => $kycStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $client = DB::table('clients')->where('id', $clientId)->first(['public_id']);

        return is_object($client) && is_string($client->public_id) ? $client->public_id : '';
    }

    private function createCustomerAccount(int $agencyId, string $clientPublicId, string $accountNumber): string
    {
        $clientId = DB::table('clients')->where('public_id', $clientPublicId)->value('id');
        self::assertIsInt($clientId);

        $accountId = DB::table('customer_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'client_id' => $clientId,
            'agency_id' => $agencyId,
            'account_number' => $accountNumber,
            'account_type' => 'savings',
            'currency' => 'XAF',
            'opened_on' => '2026-05-18',
            'status' => CustomerAccount::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $account = DB::table('customer_accounts')->where('id', $accountId)->first(['public_id']);

        return is_object($account) && is_string($account->public_id) ? $account->public_id : '';
    }

    private function createDocument(int $agencyId, string $category): string
    {
        $documentId = DB::table('documents')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'category' => $category,
            'title' => 'Signature Evidence',
            'status' => Document::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $document = DB::table('documents')->where('id', $documentId)->first(['public_id']);

        return is_object($document) && is_string($document->public_id) ? $document->public_id : '';
    }

    private function requireStringJsonPath(mixed $response, string $path): string
    {
        $value = $response instanceof TestResponse ? $response->json($path) : null;
        self::assertIsString($value);

        return $value;
    }
}
