<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountHold;
use App\Models\AccountingDay;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountSignature;
use App\Models\Document;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\ReportDefinition;
use App\Models\User;
use Database\Seeders\BatchProcedureSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\StandardReportDefinitionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use Tests\Traits\OpensAccountingDay;

final class Module3AccountingArchitectureTest extends TestCase
{
    use OpensAccountingDay;
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
                'account_class' => 'capitaux_permanents',
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
        $show->assertJsonPath('data.account_class', 'capitaux_permanents');
    }

    public function test_ledger_account_creation_requires_agency_scope(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('ledger-no-agency')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/ledger-accounts', [
                'code' => '1001',
                'name' => 'Global Ledger Attempt',
                'account_class' => 'capitaux_permanents',
                'normal_balance_side' => 'debit',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['agency_public_id']);
        $response->assertJsonPath('errors.agency_public_id.0', 'Un compte du grand livre au niveau agence doit être rattaché à une agence.');
    }

    public function test_parent_account_must_exist_before_linking(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('ACCT-02');

        $response = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('ledger-parent')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '2000',
                'name' => 'Savings',
                'account_class' => 'valeurs_immobilisees',
                'parent_account_public_id' => (string) Str::ulid(),
                'normal_balance_side' => 'debit',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['parent_account_public_id']);
        $response->assertJsonPath('errors.parent_account_public_id.0', 'La valeur sélectionnée pour parent account public id est invalide.');
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
                'account_class' => 'valeurs_immobilisees',
                'normal_balance_side' => 'debit',
            ]);
        $parentPublicId = $this->requireStringJsonPath($parent, 'data.public_id');

        $child = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('ledger-child-create')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '2110',
                'name' => 'Child Cash',
                'account_class' => 'valeurs_immobilisees',
                'parent_account_public_id' => $parentPublicId,
                'normal_balance_side' => 'debit',
            ]);

        $this->assertJsonSuccess($child, 201);
        $child->assertJsonPath('data.parent_account_public_id', $parentPublicId);

        $selfReference = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('ledger-parent-self-reference')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->patchJson('/api/v1/ledger-accounts/'.$parentPublicId, [
                'parent_account_public_id' => $parentPublicId,
            ]);

        $selfReference->assertStatus(422);
        $selfReference->assertJsonValidationErrors(['parent_account_public_id']);
        $selfReference->assertJsonPath('errors.parent_account_public_id.0', 'Le compte parent ne peut pas se référencer lui-même.');
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
                'account_class' => 'operations_clientele',
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
                'account_class' => 'operations_clientele',
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
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'credit',
                'status' => 'inactive',
            ]);
        $inactiveLedgerPublicId = $this->requireStringJsonPath($inactiveLedger, 'data.public_id');

        $activeLedger = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('active-ledger')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '5600',
                'name' => 'Active Ledger',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'credit',
            ]);
        $activeLedgerPublicId = $this->requireStringJsonPath($activeLedger, 'data.public_id');

        $client = $this->createClient($agency['id'], Client::KYC_STATUS_VERIFIED);

        $inactiveAccount = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('inactive-ledger-account')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $inactiveLedgerPublicId,
                'account_number' => 'CA-6001',
                'opened_on' => now()->toDateString(),
            ]);
        $inactiveAccount->assertStatus(422);
        $inactiveAccount->assertJsonValidationErrors(['ledger_account_public_id']);
        $inactiveAccount->assertJsonPath('errors.ledger_account_public_id.0', 'Le compte du grand livre sélectionné doit être actif.');

        $account = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('active-ledger-account')->plainTextToken])
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $client,
                'agency_public_id' => $agency['public_id'],
                'ledger_account_public_id' => $activeLedgerPublicId,
                'account_number' => 'CA-6002',
                'opened_on' => now()->toDateString(),
            ]);
        $accountPublicId = $this->requireStringJsonPath($account, 'data.public_id');

        $inactiveUpdate = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('inactive-ledger-account-update')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->patchJson('/api/v1/customer-accounts/'.$accountPublicId, [
                'ledger_account_public_id' => $inactiveLedgerPublicId,
            ]);
        $inactiveUpdate->assertStatus(422);
        $inactiveUpdate->assertJsonValidationErrors(['ledger_account_public_id']);
        $inactiveUpdate->assertJsonPath('errors.ledger_account_public_id.0', 'Le compte du grand livre sélectionné doit être actif.');

        $entry = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('inactive-ledger-entry')->plainTextToken])
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-4001',
                'business_date' => now()->toDateString(),
                'agency_public_id' => $agency['public_id'],
            ]);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');

        $line = $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('inactive-ledger-line')->plainTextToken,
            'X-Locale' => 'fr',
        ])
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $inactiveLedgerPublicId,
                'debit_minor' => 100,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ]);
        $line->assertStatus(422);
        $line->assertJsonValidationErrors(['ledger_account_public_id']);
        $line->assertJsonPath('errors.ledger_account_public_id.0', 'Le compte du grand livre sélectionné doit être actif.');
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
                'account_class' => 'tresorerie_interbancaire',
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
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'credit',
            ]);
        $ledgerAPublicId = $this->requireStringJsonPath($ledgerA, 'data.public_id');

        $ledgerB = $this->withApiHeaders(['Authorization' => 'Bearer '.$admin->createToken('ledger-agency-b')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyB['public_id'],
                'code' => '5300',
                'name' => 'Agency B Customer Ledger',
                'account_class' => 'tresorerie_interbancaire',
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
                'account_class' => 'tiers',
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

        $editPosted = $this->withApiHeaders(['X-Locale' => 'fr'])
            ->actingAsSanctum($reviewer)
            ->patchJson('/api/v1/journal-entries/'.$entryPublicId, [
                'description' => 'Edited after posting',
            ]);
        $editPosted->assertStatus(422);
        $editPosted->assertJsonPath('errors.journal_entry.0', 'Seules les écritures comptables brouillon peuvent être modifiées.');

        $addPostedLine = $this->withApiHeaders(['X-Locale' => 'fr'])
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $ledgerPublicId,
                'debit_minor' => 1,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ]);
        $addPostedLine->assertStatus(422);
        $addPostedLine->assertJsonPath('errors.journal_entry_public_id.0', 'Seules les écritures comptables en brouillon peuvent recevoir des lignes d\'écriture.');

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
                'account_class' => 'tiers',
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

        $rejectApproved = $this->withApiHeaders(['X-Locale' => 'fr'])
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/reject', [
                'reason' => 'Too late.',
            ]);
        $rejectApproved->assertStatus(422);
        $rejectApproved->assertJsonPath('errors.journal_entry.0', 'Seules les écritures comptables soumises peuvent être rejetées.');

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
                'account_class' => 'tiers',
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
                'account_class' => 'tresorerie_interbancaire',
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
                'account_class' => 'charges',
                'normal_balance_side' => 'debit',
            ]);
        $depositLedger = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '6200',
                'name' => 'Immutability Liability',
                'account_class' => 'charges',
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
        $this->openInstitutionAccountingDay('2026-05-01');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        $cashLedger = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '4500',
                'name' => 'Cash Balance Ledger',
                'account_class' => 'tiers',
                'normal_balance_side' => 'debit',
            ]);
        $cashLedgerPublicId = $this->requireStringJsonPath($cashLedger, 'data.public_id');

        $depositLedger = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '2500',
                'name' => 'Customer Deposit Ledger',
                'account_class' => 'valeurs_immobilisees',
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

        $this->ensureOpenAccountingDay($agency['id'], '2026-05-03');
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

        $secondAccountingDayQuery = AccountingDay::query()->where('agency_id', $agency['id']);
        $secondAccountingDayQuery->getQuery()->whereDate('business_date', '2026-05-02');
        $secondAccountingDay = $secondAccountingDayQuery->firstOrFail();
        $secondAccountingDay->forceFill([
            'status' => AccountingDay::STATUS_CLOSED,
            'calendar_closed_at' => now(),
        ])->save();

        $statementByAccountingDay = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/customer-accounts/'.$customerAccountPublicId.'/statement?currency=XAF&accounting_day_public_id='.$secondAccountingDay->public_id);
        $this->assertJsonSuccess($statementByAccountingDay);
        $statementByAccountingDay->assertJsonPath('data.statement.accounting_day_public_id', $secondAccountingDay->public_id);
        $statementByAccountingDay->assertJsonPath('data.statement.accounting_day_status', AccountingDay::STATUS_CLOSED);
        $statementByAccountingDay->assertJsonPath('data.statement.accounting_day_final', true);
        $statementByAccountingDay->assertJsonPath('data.statement.debit_total_minor', 3000);
        $statementByAccountingDay->assertJsonPath('data.statement.credit_total_minor', 0);
        $statementByAccountingDay->assertJsonPath('data.statement.closing_balance_minor', 7000);
        $statementByAccountingDay->assertJsonPath('data.movements.0.accounting_day_public_id', $secondAccountingDay->public_id);
        $statementByAccountingDay->assertJsonPath('data.movements.0.accounting_day_status', AccountingDay::STATUS_CLOSED);
        $statementByAccountingDay->assertJsonPath('data.movements.0.accounting_day_final', true);
        $statementByAccountingDay->assertJsonPath('meta.pagination.total', 1);

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

        $reportRunByAccountingDay = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $reportDefinition->public_id,
                'agency_public_id' => $agency['public_id'],
                'accounting_day_public_id' => $secondAccountingDay->public_id,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($reportRunByAccountingDay, 201);
        $reportRunByAccountingDay->assertJsonPath('data.parameters.accounting_day_public_id', $secondAccountingDay->public_id);
        $reportRunByAccountingDay->assertJsonPath('data.period_starts_on', '2026-05-02');
        $reportRunByAccountingDay->assertJsonPath('data.period_ends_on', '2026-05-02');
        $reportRunByAccountingDay->assertJsonPath('data.summary.accounting_day_public_id', $secondAccountingDay->public_id);
        $reportRunByAccountingDay->assertJsonPath('data.summary.accounting_day_status', AccountingDay::STATUS_CLOSED);
        $reportRunByAccountingDay->assertJsonPath('data.summary.accounting_day_final', true);
        $reportRunByAccountingDay->assertJsonPath('data.summary.debit_total_minor', 3000);
        $reportRunByAccountingDay->assertJsonPath('data.summary.credit_total_minor', 3000);
        $reportRunByAccountingDay->assertJsonPath('data.summary.row_count', 2);

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
            'account_class' => 'tresorerie_interbancaire',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $depositEmfId = DB::table('emf_regulatory_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'EMF-201',
            'name' => 'EMF Customer Deposits',
            'account_class' => 'operations_clientele',
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
                'account_class' => 'tresorerie_interbancaire',
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
                'account_class' => 'tresorerie_interbancaire',
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
                'account_class' => 'tiers',
                'normal_balance_side' => 'debit',
            ]);
        $ledgerAPublicId = $this->requireStringJsonPath($ledgerA, 'data.public_id');

        $ledgerB = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('journal-ledger-b')->plainTextToken])
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyB['public_id'],
                'code' => '4200',
                'name' => 'Agency B Ledger',
                'account_class' => 'tiers',
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
                'account_class' => 'produits',
                'normal_balance_side' => 'debit',
            ]);
        $this->assertJsonSuccess($ledger, 201);
        $ledgerPublicId = $this->requireStringJsonPath($ledger, 'data.public_id');

        $teller = $this->createUserWithRole('teller', $agencyA['code'], $agencyA['name']);
        $this->withApiHeaders()->actingAsSanctum($teller)
            ->getJson('/api/v1/ledger-accounts/'.$ledgerPublicId.'/balance?currency=XAF')->assertForbidden();
    }

    public function test_institution_level_grouping_account_can_be_created_without_an_agency(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'institution',
                'code' => '571000',
                'name' => 'Caisse Globale',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.scope', 'institution');
        $response->assertJsonPath('data.agency_public_id', null);
        $response->assertJsonPath('data.is_postable', false);
    }

    public function test_institution_grouping_account_cannot_be_asked_to_be_postable(): void
    {
        $actor = $this->createUserWithRole('platform-admin');

        // The UI hides the Nature field for institution scope, so this is the
        // API-level refusal behind that: a grouping account cannot be requested
        // as an entry target, and the check constraint would refuse it anyway.
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'institution',
                'code' => '571150',
                'name' => 'Institution Postable Attempt',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
                'is_postable' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['is_postable']);
        self::assertSame(0, DB::table('ledger_accounts')->where('code', '571150')->count());
    }

    public function test_institution_grouping_account_requires_institution_scope_permission(): void
    {
        // The accountant role already maintains its own agency's chart; the
        // institution grouping chart is a separate, protected surface.
        $agency = $this->createAgency('INST-PERM');
        $accountant = $this->createUserWithRole('accountant', $agency['code'], $agency['name']);

        $this->withApiHeaders()
            ->actingAsSanctum($accountant)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'institution',
                'code' => '571100',
                'name' => 'Unauthorised Grouping Account',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ])
            ->assertForbidden();
    }

    public function test_institution_grouping_account_cannot_be_attached_to_an_agency(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('INST-01');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'institution',
                'agency_public_id' => $agency['public_id'],
                'code' => '571200',
                'name' => 'Contradictory Scope',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['agency_public_id']);
    }

    public function test_agency_accounts_from_different_agencies_share_one_institution_parent(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('CONS-A');
        $agencyB = $this->createAgency('CONS-B');

        $parentPublicId = $this->createInstitutionLedgerAccount($actor, '571000', 'Caisse Globale');

        foreach ([[$agencyA, '571001'], [$agencyB, '571002']] as [$agency, $code]) {
            $child = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/ledger-accounts', [
                    'agency_public_id' => $agency['public_id'],
                    'code' => $code,
                    'name' => 'Caisse '.$agency['code'],
                    'account_class' => 'tresorerie_interbancaire',
                    'normal_balance_side' => 'debit',
                    'parent_account_public_id' => $parentPublicId,
                ]);

            $this->assertJsonSuccess($child, 201);
            $child->assertJsonPath('data.scope', 'agency');
            $child->assertJsonPath('data.parent_account_public_id', $parentPublicId);
            $child->assertJsonPath('data.is_postable', true);
        }
    }

    public function test_parent_account_from_another_agency_is_still_refused(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('CONS-C');
        $agencyB = $this->createAgency('CONS-D');

        $parent = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyA['public_id'],
                'code' => '572001',
                'name' => 'Agency A Cash',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ]);
        $parentPublicId = $this->requireStringJsonPath($parent, 'data.public_id');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agencyB['public_id'],
                'code' => '572002',
                'name' => 'Agency B Cash',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
                'parent_account_public_id' => $parentPublicId,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['parent_account_public_id']);
    }

    public function test_institution_account_cannot_be_grouped_under_an_agency_account(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-E');

        $institutionPublicId = $this->createInstitutionLedgerAccount($actor, '573000', 'Institution Grouping');
        $agencyAccount = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '573001',
                'name' => 'Agency Detail',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ]);
        $agencyAccountPublicId = $this->requireStringJsonPath($agencyAccount, 'data.public_id');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$institutionPublicId, [
                'parent_account_public_id' => $agencyAccountPublicId,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['parent_account_public_id']);
    }

    public function test_adding_a_sub_account_turns_the_parent_into_a_grouping_account(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-F');

        $parent = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '574000',
                'name' => 'Agency Grouping Candidate',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ]);
        $parent->assertJsonPath('data.is_postable', true);
        $parentPublicId = $this->requireStringJsonPath($parent, 'data.public_id');

        $this->assertJsonSuccess($this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '574001',
                'name' => 'Agency Detail',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
                'parent_account_public_id' => $parentPublicId,
            ]), 201);

        $reloaded = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/ledger-accounts/'.$parentPublicId);
        $this->assertJsonSuccess($reloaded);
        $reloaded->assertJsonPath('data.is_postable', false);

        // A grouping account cannot be turned back into a posting target while
        // it still has children.
        $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$parentPublicId, ['is_postable' => true])
            ->assertStatus(422);
    }

    public function test_account_carrying_movements_cannot_become_a_grouping_account(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-G');
        $this->openInstitutionAccountingDay('2026-05-01');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        $posted = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '575000', 'Posted Cash');
        $counterpart = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '575900', 'Counterpart');
        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-GROUP-1', '2026-05-01', [
            ['ledger_account_public_id' => $posted, 'debit_minor' => 5000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 0, 'credit_minor' => 5000],
        ]);

        $response = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '575001',
                'name' => 'Late Sub Account',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
                'parent_account_public_id' => $posted,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['parent_account_public_id']);
    }

    public function test_journal_lines_cannot_post_to_a_grouping_account(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-H');
        $this->openInstitutionAccountingDay('2026-05-01');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        $institutionPublicId = $this->createInstitutionLedgerAccount($actor, '576000', 'Caisse Globale');

        $entry = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-GROUPING-REJECT',
                'business_date' => '2026-05-01',
                'agency_public_id' => $agency['public_id'],
            ]);
        $this->assertJsonSuccess($entry, 201);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $entryPublicId,
                'ledger_account_public_id' => $institutionPublicId,
                'debit_minor' => 1000,
                'credit_minor' => 0,
                'currency' => 'XAF',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ledger_account_public_id']);
        self::assertSame(0, DB::table('journal_lines')->count());
    }

    public function test_operation_account_mapping_cannot_target_a_grouping_account(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-U');

        // An operation mapping drives automatic postings, so it must not be able
        // to aim at a total either — otherwise the refusal would only surface
        // later, at posting time, as a confusing resolver error.
        $institutionPublicId = $this->createInstitutionLedgerAccount($actor, '583000', 'Caisse Globale');
        $detailPublicId = $this->createAgencyLedgerAccount($actor, $agency['public_id'], '583001', 'Caisse Agence');

        $code = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/operation-codes', [
                'code' => 'CONSO_GROUPING_TARGET',
                'label' => 'Grouping target attempt',
                'module' => 'accounting',
                'operation_type' => 'adjustment',
                'direction' => 'debit_credit',
            ]);
        $this->assertJsonSuccess($code, 201);
        $operationCodePublicId = $this->requireStringJsonPath($code, 'data.public_id');

        foreach ([
            ['debit_ledger_account_public_id' => $institutionPublicId, 'credit_ledger_account_public_id' => $detailPublicId],
            ['debit_ledger_account_public_id' => $detailPublicId, 'credit_ledger_account_public_id' => $institutionPublicId],
        ] as $legs) {
            $response = $this->withApiHeaders()
                ->actingAsSanctum($actor)
                ->postJson('/api/v1/operation-account-mappings', [
                    'operation_code_public_id' => $operationCodePublicId,
                    ...$legs,
                    'currency' => 'XAF',
                ]);

            // assertStatus() accepts no message, so a custom one would be silently
            // discarded. On failure Laravel dumps the response, which names the
            // offending legs, so the index is recoverable without it.
            $response->assertStatus(422);
        }

        self::assertSame(0, DB::table('operation_account_mappings')->count());
    }

    public function test_institution_account_balance_consolidates_its_agency_children(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('CONS-I');
        $agencyB = $this->createAgency('CONS-J');
        $this->openInstitutionAccountingDay('2026-05-01');
        $this->ensureOpenAccountingDay($agencyA['id'], '2026-05-01');
        $this->ensureOpenAccountingDay($agencyB['id'], '2026-05-01');

        $institutionPublicId = $this->createInstitutionLedgerAccount($maker, '577000', 'Caisse Globale');
        $cashA = $this->createAgencyLedgerAccount($maker, $agencyA['public_id'], '577001', 'Caisse Agence A', $institutionPublicId);
        $cashB = $this->createAgencyLedgerAccount($maker, $agencyB['public_id'], '577002', 'Caisse Agence B', $institutionPublicId);
        $counterpartA = $this->createAgencyLedgerAccount($maker, $agencyA['public_id'], '577901', 'Counterpart A');
        $counterpartB = $this->createAgencyLedgerAccount($maker, $agencyB['public_id'], '577902', 'Counterpart B');

        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agencyA['public_id'], 'JE-CONS-A', '2026-05-01', [
            ['ledger_account_public_id' => $cashA, 'debit_minor' => 10000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpartA, 'debit_minor' => 0, 'credit_minor' => 10000],
        ]);
        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agencyB['public_id'], 'JE-CONS-B', '2026-05-01', [
            ['ledger_account_public_id' => $cashB, 'debit_minor' => 4000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpartB, 'debit_minor' => 0, 'credit_minor' => 4000],
        ]);

        // A grouping account consolidates by default: no client change needed.
        $consolidated = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$institutionPublicId.'/balance?currency=XAF');
        $this->assertJsonSuccess($consolidated);
        $consolidated->assertJsonPath('data.scope', 'ledger_account_consolidated');
        $consolidated->assertJsonPath('data.debit_total_minor', 14000);
        $consolidated->assertJsonPath('data.balance_minor', 14000);

        // Its own movements are nil, which is what makes it a receptacle.
        $ownOnly = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$institutionPublicId.'/balance?currency=XAF&consolidated=0');
        $this->assertJsonSuccess($ownOnly);
        $ownOnly->assertJsonPath('data.scope', 'ledger_account');
        $ownOnly->assertJsonPath('data.balance_minor', 0);

        // Detail accounts keep reporting their own movements.
        $detail = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$cashA.'/balance?currency=XAF');
        $this->assertJsonSuccess($detail);
        $detail->assertJsonPath('data.scope', 'ledger_account');
        $detail->assertJsonPath('data.balance_minor', 10000);

        // The statement consolidates the subtree too, and has to say so: a client
        // that showed these figures unlabelled would present a consolidation as
        // the account's own activity.
        $statement = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$institutionPublicId.'/movements?currency=XAF');
        $this->assertJsonSuccess($statement);
        $statement->assertJsonPath('data.statement.scope', 'ledger_account_consolidated');
        $statement->assertJsonPath('data.statement.debit_total_minor', 14000);

        $detailStatement = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$cashA.'/movements?currency=XAF');
        $this->assertJsonSuccess($detailStatement);
        $detailStatement->assertJsonPath('data.statement.scope', 'ledger_account');
    }

    public function test_an_agency_account_created_without_a_parent_still_rolls_up_to_the_institution(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-ORPH');
        $this->openInstitutionAccountingDay('2026-05-01');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        $institutionPublicId = $this->createInstitutionLedgerAccount($maker, '578', 'Caisse Globale');

        // Created without naming a parent. In the seeded chart a grouping
        // account is the shorter code (571 totalises 571001), so the code alone
        // already says where this belongs.
        // The accounting team's answer to question 1: « les totaux par agence
        // remontent automatiquement dans les comptes globaux au niveau du siège ».
        // Automatically is the operative word: if attaching the parent were left
        // to whoever fills the form, one forgotten field would quietly keep an
        // account's money out of the institution total for good, and nothing on
        // either screen would look wrong.
        $created = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'agency',
                'agency_public_id' => $agency['public_id'],
                'code' => '578001',
                'name' => 'Caisse Agence Sans Parent',
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
                'normal_balance_side' => 'debit',
            ]);
        $this->assertJsonSuccess($created, 201);
        $created->assertJsonPath('data.parent_account_public_id', $institutionPublicId);

        $orphan = $this->requireStringJsonPath($created, 'data.public_id');
        $counterpart = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '578901', 'Counterpart');

        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-ORPH', '2026-05-01', [
            ['ledger_account_public_id' => $orphan, 'debit_minor' => 7000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 0, 'credit_minor' => 7000],
        ]);

        $consolidated = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$institutionPublicId.'/balance?currency=XAF');
        $this->assertJsonSuccess($consolidated);
        $consolidated->assertJsonPath('data.debit_total_minor', 7000);

        // An explicit parent still wins: derivation fills a gap, it does not
        // overrule a choice.
        $explicit = $this->withApiHeaders()
            ->actingAsSanctum($maker)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'agency',
                'agency_public_id' => $agency['public_id'],
                'code' => '578002',
                'name' => 'Caisse Agence Parent Explicite',
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
                'normal_balance_side' => 'debit',
                'parent_account_public_id' => null,
            ]);
        $this->assertJsonSuccess($explicit, 201);
        $explicit->assertJsonPath('data.parent_account_public_id', $institutionPublicId);
    }

    public function test_consolidated_trial_balance_rolls_agency_accounts_into_their_institution_parent(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('CONS-K');
        $agencyB = $this->createAgency('CONS-L');
        $this->openInstitutionAccountingDay('2026-05-01');
        $this->ensureOpenAccountingDay($agencyA['id'], '2026-05-01');
        $this->ensureOpenAccountingDay($agencyB['id'], '2026-05-01');
        $this->seed(StandardReportDefinitionSeeder::class);

        $institutionPublicId = $this->createInstitutionLedgerAccount($maker, '578000', 'Caisse Globale');
        $cashA = $this->createAgencyLedgerAccount($maker, $agencyA['public_id'], '578001', 'Caisse Agence A', $institutionPublicId);
        $cashB = $this->createAgencyLedgerAccount($maker, $agencyB['public_id'], '578002', 'Caisse Agence B', $institutionPublicId);
        $counterpartA = $this->createAgencyLedgerAccount($maker, $agencyA['public_id'], '578901', 'Counterpart A');
        $counterpartB = $this->createAgencyLedgerAccount($maker, $agencyB['public_id'], '578902', 'Counterpart B');

        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agencyA['public_id'], 'JE-TB-A', '2026-05-01', [
            ['ledger_account_public_id' => $cashA, 'debit_minor' => 10000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpartA, 'debit_minor' => 0, 'credit_minor' => 10000],
        ]);
        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agencyB['public_id'], 'JE-TB-B', '2026-05-01', [
            ['ledger_account_public_id' => $cashB, 'debit_minor' => 4000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpartB, 'debit_minor' => 0, 'credit_minor' => 4000],
        ]);

        $definitionPublicId = DB::table('report_definitions')->where('code', 'trial_balance')->value('public_id');
        self::assertIsString($definitionPublicId);

        $run = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
                'currency' => 'XAF',
                'parameters' => ['consolidated' => true],
            ]);
        $this->assertJsonSuccess($run, 201);
        $run->assertJsonPath('data.summary.consolidated', true);

        $rows = $run->json('data.summary.rows');
        self::assertIsArray($rows);

        // The institution parent aggregates both agencies.
        $institutionRow = $this->consolidatedRow($rows, '578000');
        self::assertSame('institution', $institutionRow['scope']);
        self::assertFalse($institutionRow['is_postable']);
        self::assertSame(14000, $institutionRow['debit_total_minor']);
        self::assertSame(14000, $institutionRow['balance_minor']);

        // Detail rows stay on their own agency.
        $agencyARow = $this->consolidatedRow($rows, '578001');
        self::assertSame(10000, $agencyARow['debit_total_minor']);
        self::assertSame($agencyA['public_id'], $agencyARow['agency_public_id']);

        $agencyBRow = $this->consolidatedRow($rows, '578002');
        self::assertSame(4000, $agencyBRow['debit_total_minor']);
        self::assertSame($institutionPublicId, $agencyBRow['parent_account_public_id']);

        // Grand totals count the movements once, not once per level.
        $run->assertJsonPath('data.summary.debit_total_minor', 14000);
        $run->assertJsonPath('data.summary.credit_total_minor', 14000);

        // Without the flag the report keeps its flat, posted-accounts-only shape.
        $flat = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($flat, 201);
        $flat->assertJsonPath('data.summary.consolidated', false);
        $flatRows = $flat->json('data.summary.rows');
        self::assertIsArray($flatRows);
        $flatCodes = [];
        foreach ($flatRows as $flatRow) {
            self::assertIsArray($flatRow);
            $flatCodes[] = $flatRow['ledger_account_code'] ?? null;
        }
        self::assertNotContains('578000', $flatCodes);
        $flat->assertJsonPath('data.summary.debit_total_minor', 14000);
    }

    public function test_ledger_codes_are_unique_per_agency_and_across_the_institution(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('CONS-M');
        $agencyB = $this->createAgency('CONS-N');

        $this->createInstitutionLedgerAccount($actor, '579000', 'Caisse Globale');

        // The same code may be reused across agencies: each agency owns its own
        // namespace, which is what UNIQUE (agency_id, code) has always allowed.
        $this->createAgencyLedgerAccount($actor, $agencyA['public_id'], '579100', 'Shared Code A');
        $this->createAgencyLedgerAccount($actor, $agencyB['public_id'], '579100', 'Shared Code B');
        self::assertSame(2, DB::table('ledger_accounts')->where('code', '579100')->count());

        // Institution codes have a single namespace, guarded by the partial
        // unique index that Postgres' NULL-distinct rule would otherwise skip.
        $this->expectException(QueryException::class);
        DB::table('ledger_accounts')->insert([
            'public_id' => (string) Str::ulid(),
            'agency_id' => null,
            'code' => '579000',
            'name' => 'Duplicate Institution Code',
            'account_class' => 'tresorerie_interbancaire',
            'is_postable' => false,
            'normal_balance_side' => 'debit',
            'status' => 'active',
        ]);
    }

    public function test_duplicate_ledger_code_is_a_validation_error_not_a_database_failure(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-DUP');

        $this->createAgencyLedgerAccount($actor, $agency['public_id'], '579200', 'Contrepartie');
        $this->createInstitutionLedgerAccount($actor, '579300', 'Caisse Globale');

        // Reaching the partial unique index raises a QueryException, which
        // surfaces as a 500 carrying the failing SQL — the connection, database
        // name and inserted values included. Both namespaces must answer with a
        // field error on `code` instead.
        $duplicateInAgency = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'agency',
                'agency_public_id' => $agency['public_id'],
                'code' => '579200',
                'name' => 'Contrepartie bis',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'credit',
            ]);
        $duplicateInAgency->assertStatus(422);
        $duplicateInAgency->assertJsonValidationErrors(['code']);

        $duplicateAtInstitution = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'institution',
                'code' => '579300',
                'name' => 'Caisse Globale bis',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ]);
        $duplicateAtInstitution->assertStatus(422);
        $duplicateAtInstitution->assertJsonValidationErrors(['code']);

        // The same code in a *different* agency stays legal: each agency chart
        // owns its own namespace.
        $otherAgency = $this->createAgency('CONS-DUP2');
        $reused = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'agency',
                'agency_public_id' => $otherAgency['public_id'],
                'code' => '579200',
                'name' => 'Contrepartie autre agence',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'credit',
            ]);
        $this->assertJsonSuccess($reused, 201);
    }

    public function test_a_bivalent_account_can_be_configured_through_the_api(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-BIV');

        // The accounting team named eight bivalent roots and flagged five more as
        // candidates (37, 46, 53, 54, 55). Promoting one of those must not mean
        // editing seed data and re-seeding, so `null` has to be expressible here.
        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'agency',
                'agency_public_id' => $agency['public_id'],
                'code' => '451000',
                'name' => 'Opérations de liaison siège et agences',
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TIERS,
                'normal_balance_side' => null,
            ]);
        $this->assertJsonSuccess($created, 201);
        $created->assertJsonPath('data.normal_balance_side', null);

        $publicId = $this->requireStringJsonPath($created, 'data.public_id');
        self::assertNull(DB::table('ledger_accounts')->where('public_id', $publicId)->value('normal_balance_side'));

        // An existing account can be made bivalent, and back again.
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$publicId, ['normal_balance_side' => 'debit']));
        $backToBivalent = $this->withApiHeaders()->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$publicId, ['normal_balance_side' => null]);
        $this->assertJsonSuccess($backToBivalent);
        $backToBivalent->assertJsonPath('data.normal_balance_side', null);
    }

    public function test_the_trial_balance_reports_the_side_each_account_actually_lands_on(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-ARR');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // « calculer le signe du solde (D ou C) selon la position réelle à
        // chaque arrêté ». The trial balance is the arrêté, so it is the one
        // place that sentence has to hold. It reported normal_balance_side —
        // the side an account was told to sit on — and never the side it
        // actually reached, which for a bivalent account is the only side there
        // is.
        $liaison = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '575100', 'Liaison');
        DB::table('ledger_accounts')->where('public_id', $liaison)->update(['normal_balance_side' => null]);
        $counterpart = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '575101', 'Contrepartie');

        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-ARR', '2026-05-01', [
            ['ledger_account_public_id' => $liaison, 'debit_minor' => 9000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 0, 'credit_minor' => 9000],
        ]);

        $definitionId = DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'TB-ARRETE',
            'name' => 'Trial Balance Arrete',
            'report_type' => 'trial_balance',
            'module' => 'accounting',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $definition = DB::table('report_definitions')->where('id', $definitionId)->first(['public_id']);
        self::assertIsObject($definition);
        self::assertIsString($definition->public_id);

        $run = $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definition->public_id,
                'agency_public_id' => $agency['public_id'],
                'period_starts_on' => '2026-05-01',
                'period_ends_on' => '2026-05-01',
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($run, 201);

        $rows = $run->json('data.summary.rows');
        self::assertIsArray($rows);

        $seen = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $code = $row['ledger_account_code'] ?? null;
            if (! is_string($code)) {
                continue;
            }
            $normal = $row['normal_balance_side'] ?? null;
            $position = $row['balance_side'] ?? null;
            $seen[] = $code.':'.(is_string($normal) ? $normal : 'none').'/'.(is_string($position) ? $position : 'none');
        }
        sort($seen);

        // The bivalent account has no imposed side and lands on debit; its
        // counterpart was given debit and sits on credit — exactly the case the
        // team described for 37, 46, 53, 54 and 55, and the reason the imposed
        // side alone cannot be read as the position.
        self::assertSame(['575100:none/debit', '575101:debit/credit'], $seen);
    }

    public function test_a_single_sided_account_still_accepts_the_opposite_side(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-DOM');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // The accounting team's « remarques libres »: 37, 46, 53, 54 and 55 also
        // mix the two sides, and they kept the dominant one (C) « pour
        // simplifier », to be revisited « si des écritures dans l'autre sens
        // sont bloquées lors des tests ».
        //
        // This is that test, and it answers their condition: a debit on a
        // credit-side account posts exactly like any other. Nothing validates an
        // entry against normal_balance_side — the column is read only to decide
        // which way round to present a total — so no écriture will ever be
        // blocked for being on the "wrong" side, on these five or on any other
        // account. Their trigger cannot fire, so the five must be judged on the
        // figures instead.
        $mixed = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '530000', 'Autres valeurs reçues ou données');
        $counterpart = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '530001', 'Contrepartie');

        DB::table('ledger_accounts')->where('public_id', $mixed)
            ->update(['normal_balance_side' => LedgerAccount::NORMAL_BALANCE_CREDIT]);

        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-DOM-D', '2026-05-01', [
            ['ledger_account_public_id' => $mixed, 'debit_minor' => 12000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 0, 'credit_minor' => 12000],
        ]);

        $balance = $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$mixed.'/balance?currency=XAF');
        $this->assertJsonSuccess($balance);

        // Accepted, and the position is reported honestly. balance_minor is
        // signed against the *imposed* side, so a credit-side account sitting on
        // debit reports a negative total — which is why balance_side exists and
        // why a reader must take the side from it rather than from the sign.
        $balance->assertJsonPath('data.debit_total_minor', 12000);
        $balance->assertJsonPath('data.balance_minor', -12000);
        $balance->assertJsonPath('data.normal_balance_side', LedgerAccount::NORMAL_BALANCE_CREDIT);
        $balance->assertJsonPath('data.balance_side', LedgerAccount::NORMAL_BALANCE_DEBIT);
    }

    public function test_a_bivalent_account_takes_entries_on_either_side_and_reports_where_it_lands(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-BOTH');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        $liaison = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '571600', 'Liaison');
        DB::table('ledger_accounts')->where('public_id', $liaison)->update(['normal_balance_side' => null]);
        $counterpart = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '571601', 'Contrepartie');

        // « le système doit accepter des écritures aussi bien au débit qu'au
        // crédit sur chacun d'eux […] sans blocage ni rejet d'écriture pour
        // non-conformité de sens » — so post both directions to the same
        // account and require both to succeed. Asserting only one direction, or
        // only that no code reads the side, would not have shown this.
        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-BOTH-CR', '2026-05-01', [
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 9000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $liaison, 'debit_minor' => 0, 'credit_minor' => 9000],
        ]);

        $afterCredit = $this->withApiHeaders()->actingAsSanctum($maker)
            ->getJson('/api/v1/ledger-accounts/'.$liaison.'/balance?currency=XAF');
        $this->assertJsonSuccess($afterCredit);
        $afterCredit->assertJsonPath('data.balance_side', 'credit');

        // The other direction, on the same account, in a second entry.
        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-BOTH-DR', '2026-05-01', [
            ['ledger_account_public_id' => $liaison, 'debit_minor' => 14000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 0, 'credit_minor' => 14000],
        ]);

        $afterDebit = $this->withApiHeaders()->actingAsSanctum($maker)
            ->getJson('/api/v1/ledger-accounts/'.$liaison.'/balance?currency=XAF');
        $this->assertJsonSuccess($afterDebit);
        // 14 000 debit against 9 000 credit: the position moved to debit, which
        // is the "signe du solde selon la position réelle" they asked for.
        $afterDebit->assertJsonPath('data.debit_total_minor', 14000);
        $afterDebit->assertJsonPath('data.credit_total_minor', 9000);
        $afterDebit->assertJsonPath('data.balance_side', 'debit');

        // And the statement — the arrêté — agrees.
        $statement = $this->withApiHeaders()->actingAsSanctum($maker)
            ->getJson('/api/v1/ledger-accounts/'.$liaison.'/movements?currency=XAF');
        $this->assertJsonSuccess($statement);
        $statement->assertJsonPath('data.statement.balance_side', 'debit');

        // Equal in both directions is a position on neither side, not a default.
        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-BOTH-EQ', '2026-05-01', [
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 5000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $liaison, 'debit_minor' => 0, 'credit_minor' => 5000],
        ]);
        $levelled = $this->withApiHeaders()->actingAsSanctum($maker)
            ->getJson('/api/v1/ledger-accounts/'.$liaison.'/balance?currency=XAF');
        $this->assertJsonSuccess($levelled);
        $levelled->assertJsonPath('data.balance_side', null);
        $levelled->assertJsonPath('data.balance_minor', 0);
    }

    public function test_a_bivalent_balance_names_the_side_it_actually_sits_on(): void
    {
        $maker = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-POS');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        $liaison = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '571500', 'Liaison');
        DB::table('ledger_accounts')->where('public_id', $liaison)->update(['normal_balance_side' => null]);
        $counterpart = $this->createAgencyLedgerAccount($maker, $agency['public_id'], '571501', 'Contrepartie');

        // A transfer *out*: the liaison account is credited, so it sits on the
        // credit side this period even though no side was imposed on it.
        $this->createPostedJournalEntryWithLines($maker, $reviewer, $agency['public_id'], 'JE-BIV', '2026-05-01', [
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 7500, 'credit_minor' => 0],
            ['ledger_account_public_id' => $liaison, 'debit_minor' => 0, 'credit_minor' => 7500],
        ]);

        $balance = $this->withApiHeaders()->actingAsSanctum($maker)
            ->getJson('/api/v1/ledger-accounts/'.$liaison.'/balance?currency=XAF');
        $this->assertJsonSuccess($balance);
        $balance->assertJsonPath('data.normal_balance_side', null);
        // Reported rather than left for the reader to infer from a sign.
        $balance->assertJsonPath('data.balance_side', 'credit');
        $balance->assertJsonPath('data.credit_total_minor', 7500);
    }

    public function test_a_mistyped_account_class_is_correctable_until_the_account_has_movements(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-CLS');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // A class contradicting the code is refused outright: in PCEMF the class
        // *is* the leading digit, so 571901 can never be class 4. This is what
        // stops the mistake being made at all — it used to be unrecoverable,
        // because the code it occupies comes from the regulated chart.
        $mismatch = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'agency',
                'agency_public_id' => $agency['public_id'],
                'code' => '571901',
                'name' => 'Contrepartie',
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TIERS,
                'normal_balance_side' => 'credit',
            ]);
        $mismatch->assertStatus(422);
        $mismatch->assertJsonValidationErrors(['account_class']);

        // Rows that predate the rule — or arrive from a seeded chart — can still
        // carry a wrong class, so the correction path has to keep working. Insert
        // one straight into the table, since the API now refuses to create it.
        $publicId = (string) Str::ulid();
        DB::table('ledger_accounts')->insert([
            'public_id' => $publicId,
            'agency_id' => $agency['id'],
            'code' => '571901',
            'name' => 'Contrepartie',
            'account_class' => LedgerAccount::ACCOUNT_CLASS_TIERS,
            'is_postable' => true,
            'normal_balance_side' => 'credit',
            'status' => 'active',
        ]);

        // While unused, the class is a referential value and stays correctable —
        // but only towards the class the code actually implies.
        $stillWrong = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$publicId, [
                'account_class' => LedgerAccount::ACCOUNT_CLASS_CHARGES,
            ]);
        $stillWrong->assertStatus(422);
        $stillWrong->assertJsonValidationErrors(['account_class']);

        $corrected = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$publicId, [
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
            ]);
        $this->assertJsonSuccess($corrected);
        $corrected->assertJsonPath('data.account_class', LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE);

        // Once it carries a movement, changing the class would restate figures
        // already reported, so it freezes.
        $cash = $this->createAgencyLedgerAccount($actor, $agency['public_id'], '571001', 'Caisse');
        $this->createPostedJournalEntryWithLines($actor, $this->createUserWithRole('platform-admin'), $agency['public_id'], 'JE-CLS', '2026-05-01', [
            ['ledger_account_public_id' => $cash, 'debit_minor' => 2500, 'credit_minor' => 0],
            ['ledger_account_public_id' => $publicId, 'debit_minor' => 0, 'credit_minor' => 2500],
        ]);

        // Asserted on the normal side rather than the class: every class other
        // than the code's own is refused by the rule above whatever the account's
        // history, so it could not tell the freeze apart from the mismatch. The
        // normal side carries no code convention, so only the freeze can refuse it.
        $frozen = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$publicId, [
                'normal_balance_side' => 'debit',
            ]);
        $frozen->assertStatus(422);
        $frozen->assertJsonValidationErrors(['normal_balance_side']);

        // Renaming an account that has movements still works: only the class and
        // the normal balance side are frozen.
        $this->assertJsonSuccess($this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$publicId, ['name' => 'Contrepartie corrigée']));
    }

    public function test_an_archived_account_can_be_reactivated_so_its_code_is_never_stranded(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-ARC');

        $publicId = $this->createAgencyLedgerAccount($actor, $agency['public_id'], '571903', 'Compte à corriger');

        $this->assertJsonSuccess($this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->deleteJson('/api/v1/ledger-accounts/'.$publicId));
        self::assertSame(
            LedgerAccount::STATUS_ARCHIVED,
            DB::table('ledger_accounts')->where('public_id', $publicId)->value('status'),
        );

        // The code stays taken while archived — history must remain unambiguous,
        // so a second account may not claim it.
        $reuse = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'agency',
                'agency_public_id' => $agency['public_id'],
                'code' => '571903',
                'name' => 'Tentative de réemploi',
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
                'normal_balance_side' => 'debit',
            ]);
        $reuse->assertStatus(422);
        $reuse->assertJsonValidationErrors(['code']);

        // Which is only acceptable because archiving is reversible: the way out
        // is to reactivate the account and correct it, not to duplicate its code.
        $reactivated = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/ledger-accounts/'.$publicId, [
                'status' => LedgerAccount::STATUS_ACTIVE,
            ]);
        $this->assertJsonSuccess($reactivated);
        $reactivated->assertJsonPath('data.status', LedgerAccount::STATUS_ACTIVE);
    }

    public function test_accountant_reads_the_chart_but_does_not_author_it(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-O');
        $accountant = $this->createUserWithRole('accountant', $agency['code'], $agency['name']);

        // The PCEMF is adopted once at head office and every posted account has
        // to map to the COBAC chart, so agencies read the plan rather than extend
        // it. Subdivisions are requested from the chef comptable.
        $publicId = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '580001', 'Caisse Agence');

        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($accountant)
            ->getJson('/api/v1/ledger-accounts/'.$publicId));

        $this->withApiHeaders()
            ->actingAsSanctum($accountant)
            ->postJson('/api/v1/ledger-accounts', [
                'code' => '580002',
                'name' => 'Compte improvisé',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ])
            ->assertForbidden();

        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->patchJson('/api/v1/ledger-accounts/'.$publicId, ['name' => 'Renommé'])->assertForbidden();
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->deleteJson('/api/v1/ledger-accounts/'.$publicId)->assertForbidden();

        self::assertSame(0, DB::table('ledger_accounts')->where('code', '580002')->count());
        self::assertSame('Caisse Agence', DB::table('ledger_accounts')->where('public_id', $publicId)->value('name'));
    }

    public function test_accountant_prepares_its_own_agency_entries_but_cannot_validate_them(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-V');
        $otherAgency = $this->createAgency('CONS-W');
        $accountant = $this->createUserWithRole('accountant', $agency['code'], $agency['name']);
        $this->openInstitutionAccountingDay('2026-05-01');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');
        $this->ensureOpenAccountingDay($otherAgency['id'], '2026-05-01');

        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '584001', 'Caisse Agence');
        $counterpart = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '584901', 'Contrepartie Agence');

        // Recording an OD for its own agency: no agency_public_id needed, the
        // actor's assignment supplies it.
        $entry = $this->withApiHeaders()
            ->actingAsSanctum($accountant)
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'OD-AGENCY-1',
                'business_date' => '2026-05-01',
            ]);
        $this->assertJsonSuccess($entry, 201);
        $entry->assertJsonPath('data.agency_public_id', $agency['public_id']);
        $entryPublicId = $this->requireStringJsonPath($entry, 'data.public_id');

        foreach ([[$cash, 5000, 0], [$counterpart, 0, 5000]] as [$account, $debit, $credit]) {
            $this->assertJsonSuccess($this->withApiHeaders()
                ->actingAsSanctum($accountant)
                ->postJson('/api/v1/journal-lines', [
                    'journal_entry_public_id' => $entryPublicId,
                    'ledger_account_public_id' => $account,
                    'debit_minor' => $debit,
                    'credit_minor' => $credit,
                    'currency' => 'XAF',
                ]), 201);
        }

        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($accountant)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/submit'));

        // Validation belongs to the siège: whoever records does not approve.
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/approve')->assertForbidden();

        // And another agency's books stay out of reach even when named explicitly.
        $crossAgency = $this->withApiHeaders()
            ->actingAsSanctum($accountant)
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'OD-AGENCY-CROSS',
                'business_date' => '2026-05-01',
                'agency_public_id' => $otherAgency['public_id'],
            ]);
        $crossAgency->assertStatus(422);
        $crossAgency->assertJsonValidationErrors(['agency_public_id']);
    }

    public function test_head_office_records_an_entry_remotely_into_an_agency_ledger(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CONS-REM');

        // Head office carries no agency of its own — that is what makes this
        // remote. The accounting team's correction to question 2: « certaines
        // opérations doivent même pouvoir être saisies à distance depuis le
        // siège, directement dans les pôles HABISLOAN installés dans les
        // agences ». So the chief accountant names the agency and books into
        // its ledger without being posted there.
        $chief = $this->createUserWithRole('chief-accountant');

        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '582001', 'Caisse Agence');
        $counterpart = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '582901', 'Counterpart');

        $entry = $this->createPostedJournalEntryWithLines($chief, $admin, $agency['public_id'], 'JE-REMOTE', '2026-05-01', [
            ['ledger_account_public_id' => $cash, 'debit_minor' => 25000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $counterpart, 'debit_minor' => 0, 'credit_minor' => 25000],
        ]);

        // The entry belongs to the agency where the event happened, not to
        // whoever keyed it in: the books stay the agency's.
        $stored = DB::table('journal_entries')->where('public_id', $entry)->first(['agency_id', 'created_by_user_id']);
        self::assertNotNull($stored);
        self::assertSame($agency['id'], $stored->agency_id);
        self::assertSame($chief->id, $stored->created_by_user_id);

        $balance = $this->withApiHeaders()->actingAsSanctum($admin)
            ->getJson('/api/v1/ledger-accounts/'.$cash.'/balance?currency=XAF');
        $this->assertJsonSuccess($balance);
        $balance->assertJsonPath('data.balance_minor', 25000);

        // Remote does not mean unbounded: an entry filed against one agency has
        // to use that agency's accounts, so head office cannot fold two
        // agencies' ledgers into a single entry.
        $otherAgency = $this->createAgency('CONS-REM2');
        $otherCash = $this->createAgencyLedgerAccount($admin, $otherAgency['public_id'], '582002', 'Caisse Autre Agence');

        $draft = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/journal-entries', [
                'agency_public_id' => $agency['public_id'],
                'reference' => 'JE-REMOTE-MIX',
                'business_date' => '2026-05-01',
            ]);
        $this->assertJsonSuccess($draft, 201);

        $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/journal-lines', [
                'journal_entry_public_id' => $this->requireStringJsonPath($draft, 'data.public_id'),
                'ledger_account_public_id' => $otherCash,
                'currency' => 'XAF',
                'debit_minor' => 1000,
                'credit_minor' => 0,
            ])->assertStatus(422);
    }

    public function test_accountant_cannot_reach_another_agency_chart_or_the_institution_chart(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $ownAgency = $this->createAgency('CONS-P');
        $otherAgency = $this->createAgency('CONS-Q');
        $accountant = $this->createUserWithRole('accountant', $ownAgency['code'], $ownAgency['name']);

        $institutionPublicId = $this->createInstitutionLedgerAccount($admin, '581000', 'Caisse Globale');
        $otherAccountPublicId = $this->createAgencyLedgerAccount($admin, $otherAgency['public_id'], '581002', 'Other Agency Cash');

        // Another agency's accounts are out of reach on every verb.
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->getJson('/api/v1/ledger-accounts/'.$otherAccountPublicId)->assertForbidden();
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->patchJson('/api/v1/ledger-accounts/'.$otherAccountPublicId, ['name' => 'Renamed'])->assertForbidden();
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->deleteJson('/api/v1/ledger-accounts/'.$otherAccountPublicId)->assertForbidden();

        // Institution grouping accounts: readable, because agency accounts are
        // filed under them and the accountant has to see the plan — but not
        // writable, and their consolidated figures span every agency.
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($accountant)
            ->getJson('/api/v1/ledger-accounts/'.$institutionPublicId));
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->patchJson('/api/v1/ledger-accounts/'.$institutionPublicId, ['name' => 'Renamed Institution Account'])
            ->assertForbidden();
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->getJson('/api/v1/ledger-accounts/'.$institutionPublicId.'/balance?currency=XAF')->assertForbidden();
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->getJson('/api/v1/ledger-accounts/'.$institutionPublicId.'/movements?currency=XAF')->assertForbidden();
    }

    public function test_chief_accountant_owns_the_institution_chart_without_an_agency_assignment(): void
    {
        $agencyA = $this->createAgency('CONS-R');
        $agencyB = $this->createAgency('CONS-S');
        // Head office: no agency assignment at all.
        $chief = $this->createUserWithRole('chief-accountant');
        self::assertNull(DB::table('staff_agency_assignments')->where('user_id', $chief->id)->value('agency_id'));

        // Creates the institution grouping account.
        $institution = $this->withApiHeaders()
            ->actingAsSanctum($chief)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'institution',
                'code' => '582000',
                'name' => 'Caisse Globale',
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ]);
        $this->assertJsonSuccess($institution, 201);
        $institution->assertJsonPath('data.scope', 'institution');
        $institutionPublicId = $this->requireStringJsonPath($institution, 'data.public_id');

        // Deploys detail accounts into every agency chart, which no agency-bound
        // actor may do.
        foreach ([[$agencyA, '582001'], [$agencyB, '582002']] as [$agency, $code]) {
            $child = $this->withApiHeaders()
                ->actingAsSanctum($chief)
                ->postJson('/api/v1/ledger-accounts', [
                    'agency_public_id' => $agency['public_id'],
                    'code' => $code,
                    'name' => 'Caisse '.$agency['code'],
                    'account_class' => 'tresorerie_interbancaire',
                    'normal_balance_side' => 'debit',
                    'parent_account_public_id' => $institutionPublicId,
                ]);
            $this->assertJsonSuccess($child, 201);
            $child->assertJsonPath('data.agency_public_id', $agency['public_id']);
        }

        // Reads across agencies, including the consolidated institution figure.
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($chief)
            ->getJson('/api/v1/ledger-accounts/'.$institutionPublicId.'/balance?currency=XAF'));
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($chief)
            ->patchJson('/api/v1/ledger-accounts/'.$institutionPublicId, ['name' => 'Caisse Globale Institution']));

        // Owns the institution's declared identity.
        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($chief)
            ->patchJson('/api/v1/institution', ['legal_name' => 'Habis Microfinance SA']));
    }

    public function test_consolidated_trial_balance_needs_the_same_institution_read_as_a_consolidated_balance(): void
    {
        $this->seed(StandardReportDefinitionSeeder::class);
        $definitionPublicId = DB::table('report_definitions')->where('code', 'trial_balance')->value('public_id');
        self::assertIsString($definitionPublicId);

        // `auditor` holds accounting.audit.view but not ledger.scope.institution.read.
        $auditor = $this->createUserWithRole('auditor');

        $payload = [
            'report_definition_public_id' => $definitionPublicId,
            'currency' => 'XAF',
            'parameters' => ['consolidated' => true],
        ];

        // The consolidated rollup is cross-agency information, and this same actor
        // is already refused it on /ledger-accounts/{id}/balance. Generating it as
        // a report must not be the way around that.
        $this->withApiHeaders()->actingAsSanctum($auditor)
            ->postJson('/api/v1/report-runs', $payload)
            ->assertForbidden();

        // The ordinary trial balance stays available: only consolidation is gated.
        $this->assertJsonSuccess(
            $this->withApiHeaders()->actingAsSanctum($auditor)->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
                'currency' => 'XAF',
            ]),
            201,
        );

        // The chief accountant holds institution read, and consolidates.
        $chief = $this->createUserWithRole('chief-accountant');
        $run = $this->withApiHeaders()->actingAsSanctum($chief)->postJson('/api/v1/report-runs', $payload);
        $this->assertJsonSuccess($run, 201);
        $run->assertJsonPath('data.summary.consolidated', true);
    }

    public function test_a_posting_rule_cannot_be_authored_and_approved_by_the_same_person(): void
    {
        $author = $this->createUserWithRole('chief-accountant');
        $checker = $this->createUserWithRole('chief-accountant');
        $agency = $this->createAgency('MAP-MC');
        $debit = $this->createAgencyLedgerAccount($author, $agency['public_id'], '571401', 'Caisse');
        $credit = $this->createAgencyLedgerAccount($author, $agency['public_id'], '571411', 'Contrepartie');

        $code = $this->withApiHeaders()->actingAsSanctum($author)
            ->postJson('/api/v1/operation-codes', [
                'code' => 'mc_test_operation',
                'label' => 'Maker-checker test',
                'module' => 'accounting',
                'operation_type' => 'adjustment',
                'direction' => 'debit_credit',
            ]);
        $this->assertJsonSuccess($code, 201);

        // Only an approved mapping is ever resolved into a posting, so approval
        // is what puts a rule into service. The author must not grant it.
        $mapping = $this->withApiHeaders()->actingAsSanctum($author)
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $this->requireStringJsonPath($code, 'data.public_id'),
                'agency_public_id' => $agency['public_id'],
                'debit_ledger_account_public_id' => $debit,
                'credit_ledger_account_public_id' => $credit,
                'currency' => 'XAF',
                'approval_status' => 'approved',
            ]);
        $mapping->assertStatus(422);
        $mapping->assertJsonValidationErrors(['approval_status']);

        $created = $this->withApiHeaders()->actingAsSanctum($author)
            ->postJson('/api/v1/operation-account-mappings', [
                'operation_code_public_id' => $this->requireStringJsonPath($code, 'data.public_id'),
                'agency_public_id' => $agency['public_id'],
                'debit_ledger_account_public_id' => $debit,
                'credit_ledger_account_public_id' => $credit,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($created, 201);
        $created->assertJsonPath('data.approval_status', 'draft');
        $mappingPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        // Nor may the author reach approved by editing around the decision.
        $this->withApiHeaders()->actingAsSanctum($author)
            ->patchJson('/api/v1/operation-account-mappings/'.$mappingPublicId, ['approval_status' => 'approved'])
            ->assertStatus(422);

        $this->withApiHeaders()->actingAsSanctum($author)
            ->postJson('/api/v1/operation-account-mappings/'.$mappingPublicId.'/approve')
            ->assertForbidden();

        // A second holder of the same role is a valid checker: the control is on
        // identity, not on a separate role existing.
        $approved = $this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/operation-account-mappings/'.$mappingPublicId.'/approve');
        $this->assertJsonSuccess($approved);
        $approved->assertJsonPath('data.approval_status', 'approved');

        // Already decided: it cannot be approved twice.
        $this->withApiHeaders()->actingAsSanctum($checker)
            ->postJson('/api/v1/operation-account-mappings/'.$mappingPublicId.'/approve')
            ->assertStatus(422);
    }

    public function test_an_agency_accountant_cannot_approve_a_posting_rule(): void
    {
        $agency = $this->createAgency('MAP-ACC');
        $accountant = $this->createUserWithRole('accountant', $agency['code'], $agency['name']);
        $admin = $this->createUserWithRole('platform-admin');
        $debit = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571402', 'Caisse');

        $mappingId = DB::table('operation_account_mappings')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => DB::table('operation_codes')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'code' => 'acc_no_approve',
                'label' => 'No approve',
                'module' => 'accounting',
                'operation_type' => 'adjustment',
                'direction' => 'debit_credit',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'agency_id' => $agency['id'],
            'debit_ledger_account_id' => DB::table('ledger_accounts')->where('public_id', $debit)->value('id'),
            'currency' => 'XAF',
            'status' => 'active',
            'approval_status' => 'draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mappingRow = DB::table('operation_account_mappings')->where('id', $mappingId)->first(['public_id']);
        self::assertNotNull($mappingRow);
        $publicId = (string) $mappingRow->public_id;

        // Maintaining its agency's chart is the accountant's job; deciding which
        // posting rules go live is not.
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->postJson('/api/v1/operation-account-mappings/'.$publicId.'/approve')
            ->assertForbidden();
    }

    public function test_chief_accountant_reaches_the_report_catalogue_without_an_agency_assignment(): void
    {
        $chief = $this->createUserWithRole('chief-accountant');
        $this->seed(StandardReportDefinitionSeeder::class);

        // Report definitions are a global catalogue, not agency data, and this
        // role carries no agency by design. Requiring an assignment here empties
        // the report picker, so the role cannot generate the consolidated trial
        // balance it exists to produce — the failure looks like "no definitions
        // available for this type" rather than a permission error.
        $definitions = $this->withApiHeaders()
            ->actingAsSanctum($chief)
            ->getJson('/api/v1/report-definitions');
        $this->assertJsonSuccess($definitions);
        $definitions->assertSee('trial_balance');

        $definitionPublicId = DB::table('report_definitions')->where('code', 'trial_balance')->value('public_id');
        self::assertIsString($definitionPublicId);

        // And it can generate the institution-wide rollup: no agency named.
        $run = $this->withApiHeaders()
            ->actingAsSanctum($chief)
            ->postJson('/api/v1/report-runs', [
                'report_definition_public_id' => $definitionPublicId,
                'currency' => 'XAF',
                'parameters' => ['consolidated' => true],
            ]);
        $this->assertJsonSuccess($run, 201);
        $run->assertJsonPath('data.summary.consolidated', true);
    }

    public function test_chief_accountant_runs_the_institution_accounting_period(): void
    {
        // Starting a close hard-requires active close-control procedures, and
        // configuring those stays with platform-admin by design
        // (batch.procedures.manage is non-delegable). Seeding them is the
        // precondition, not part of what the role is being tested for.
        $this->seed(BatchProcedureSeeder::class);

        $chief = $this->createUserWithRole('chief-accountant');

        // Opening and closing the institution's own period is the arrêté
        // comptable — head-office accounting work, not platform administration.
        $open = $this->withApiHeaders()
            ->actingAsSanctum($chief)
            ->postJson('/api/v1/accounting-days/open', [
                'scope' => 'institution',
                'business_date' => '2026-05-01',
            ]);
        $this->assertJsonSuccess($open, 201);
        $open->assertJsonPath('data.scope', 'institution');
        $dayPublicId = $this->requireStringJsonPath($open, 'data.public_id');

        // The days list is reachable despite carrying no agency assignment.
        $index = $this->withApiHeaders()->actingAsSanctum($chief)->getJson('/api/v1/accounting-days');
        $this->assertJsonSuccess($index);

        $this->assertJsonSuccess($this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/accounting-days/'.$dayPublicId.'/start-close'));

        // Reopening a closed period stays with platform administrators: the role
        // that closes the books is not the role that can unclose them.
        self::assertFalse($chief->can('accounting.days.reopen'));
    }

    public function test_agency_accountant_still_cannot_touch_the_institution_accounting_period(): void
    {
        $agency = $this->createAgency('CONS-T');
        $accountant = $this->createUserWithRole('accountant', $agency['code'], $agency['name']);

        $this->withApiHeaders()
            ->actingAsSanctum($accountant)
            ->postJson('/api/v1/accounting-days/open', [
                'scope' => 'institution',
                'business_date' => '2026-05-01',
            ])
            ->assertForbidden();
    }

    public function test_journal_entry_without_an_agency_is_rejected_rather_than_failing_at_the_database(): void
    {
        // A head-office actor has no agency to default to, and
        // journal_entries.agency_id is NOT NULL.
        $chief = $this->createUserWithRole('chief-accountant');

        $response = $this->withApiHeaders()
            ->actingAsSanctum($chief)
            ->postJson('/api/v1/journal-entries', [
                'reference' => 'JE-NO-AGENCY',
                'business_date' => '2026-05-01',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['agency_public_id']);
    }

    public function test_a_subvention_is_an_operating_product_and_not_financial_income(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-SUB');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // Confirmed by the accounting team: « le compte 76 reste dans le calcul du
        // 81. C'est une subvention pour faire fonctionner l'activité (pas un
        // revenu d'intérêts), donc elle va avec les autres produits
        // d'exploitation. »
        //
        // Worth its own test because moving a root between soldes is invisible to
        // every other check here: 76 counted in 80 instead of 81 still appears
        // exactly once, and still leaves the résultat net untouched, since it is
        // a produit either way. Only the PNF would be wrong — the figure the
        // regulator reads as the institution's financial margin, inflated by a
        // grant that earned nothing.
        $subvention = $this->createResultAccount($admin, $agency['public_id'], '761000', "Subvention d'exploitation", 'produits', 'credit');
        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571000', 'Caisse');

        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-SUB', '2026-05-01', [
            ['ledger_account_public_id' => $subvention, 'debit_minor' => 0, 'credit_minor' => 50000],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 50000, 'credit_minor' => 0],
        ]);

        $soldes = $this->soldesFrom($this->postIncomeStatement($admin, $agency['public_id'])->json('data.summary.rows'));

        // The PNF is untouched: nothing financial happened.
        self::assertSame(0, $this->soldeAmount($soldes, '80'));
        self::assertNull($this->soldeSideOf($soldes, '80'));

        // It enters at the produit d'exploitation global and carries down.
        self::assertSame(50000, $this->soldeAmount($soldes, '81'));
        self::assertSame(50000, $this->soldeAmount($soldes, '82'));
        self::assertSame(50000, $this->soldeAmount($soldes, '87'));
    }

    public function test_closing_an_exercise_carries_the_benefice_to_131_and_soldes_classes_six_and_seven(): void
    {
        $chief = $this->createUserWithRole('chief-accountant');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CLO-A');
        $this->ensureOpenAccountingDay($agency['id'], '2026-12-31');

        $earned = $this->createResultAccount($chief, $agency['public_id'], '701000', 'Intérêts reçus', 'produits', 'credit');
        $paid = $this->createResultAccount($chief, $agency['public_id'], '601000', 'Intérêts payés', 'charges', 'debit');
        $benefice = $this->createResultAccount($chief, $agency['public_id'], '131', "Bénéfice de l'exercice", 'capitaux_permanents', 'credit');
        $cash = $this->createAgencyLedgerAccount($chief, $agency['public_id'], '571000', 'Caisse');

        $this->createPostedJournalEntryWithLines($chief, $reviewer, $agency['public_id'], 'JE-CLO-1', '2026-12-31', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => 90000],
            ['ledger_account_public_id' => $paid, 'debit_minor' => 30000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 60000, 'credit_minor' => 0],
        ]);

        $closing = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
                'currency' => 'XAF',
            ]);
        $this->assertJsonSuccess($closing, 201);

        // 90 000 earned less 30 000 paid: a bénéfice, so it carries to 131.
        $closing->assertJsonPath('data.net_result_minor', 60000);
        $closing->assertJsonPath('data.result_account_code', '131');

        // Created submitted, not posted. The largest entry of the year is exactly
        // the one that should need a second pair of eyes, so the clôture uses the
        // ordinary maker-checker rather than posting itself.
        $closing->assertJsonPath('data.status', JournalEntry::STATUS_SUBMITTED);
        $closing->assertJsonPath('data.posted', false);

        $entryPublicId = $this->requireStringJsonPath($closing, 'data.journal_entry_public_id');
        $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/approve')->assertStatus(200);
        $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/post')->assertStatus(200);

        // Classes 6 and 7 now stand at nil: that is what a clôture is for.
        foreach ([$earned, $paid] as $publicId) {
            $balance = $this->withApiHeaders()->actingAsSanctum($reviewer)
                ->getJson('/api/v1/ledger-accounts/'.$publicId.'/balance?currency=XAF');
            $this->assertJsonSuccess($balance);
            $balance->assertJsonPath('data.balance_minor', 0);
            $balance->assertJsonPath('data.balance_side', null);
        }

        // And the result is sitting in 131, on the credit side.
        $resultBalance = $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$benefice.'/balance?currency=XAF');
        $this->assertJsonSuccess($resultBalance);
        $resultBalance->assertJsonPath('data.balance_minor', 60000);
        $resultBalance->assertJsonPath('data.balance_side', LedgerAccount::NORMAL_BALANCE_CREDIT);

        // The exercise is still readable after being closed. The clôture is dated
        // its last day, as it must be, so counting it would cancel the activity it
        // closes and the annual accounts would read nil from the moment they were
        // signed off.
        $statement = $this->postIncomeStatement($reviewer, $agency['public_id'], businessDate: '2026-12-31');
        $statement->assertJsonPath('data.summary.net_result_minor', 60000);
        $soldes = $this->soldesFrom($statement->json('data.summary.rows'));
        self::assertSame(60000, $this->soldeAmount($soldes, '80'));

        // Closing it a second time would transfer the result twice and leave
        // classes 6 and 7 negative by the same amount.
        $again = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
                'currency' => 'XAF',
            ]);
        $again->assertStatus(422);
        $again->assertJsonValidationErrors(['fiscal_year']);
    }

    public function test_closing_a_loss_making_exercise_carries_it_to_132(): void
    {
        $chief = $this->createUserWithRole('chief-accountant');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CLO-B');
        $this->ensureOpenAccountingDay($agency['id'], '2026-12-31');

        $staff = $this->createResultAccount($chief, $agency['public_id'], '651000', 'Charges de personnel', 'charges', 'debit');
        $perte = $this->createResultAccount($chief, $agency['public_id'], '132', "Perte de l'exercice", 'capitaux_permanents', 'debit');
        $cash = $this->createAgencyLedgerAccount($chief, $agency['public_id'], '571000', 'Caisse');

        $this->createPostedJournalEntryWithLines($chief, $reviewer, $agency['public_id'], 'JE-CLO-2', '2026-12-31', [
            ['ledger_account_public_id' => $staff, 'debit_minor' => 45000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 45000],
        ]);

        $closing = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
            ]);
        $this->assertJsonSuccess($closing, 201);
        $closing->assertJsonPath('data.net_result_minor', -45000);
        $closing->assertJsonPath('data.result_account_code', '132');

        $entryPublicId = $this->requireStringJsonPath($closing, 'data.journal_entry_public_id');
        $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/approve')->assertStatus(200);
        $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entryPublicId.'/post')->assertStatus(200);

        // A perte lands on the debit side of 132, and the charge is soldé.
        $perteBalance = $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$perte.'/balance?currency=XAF');
        $this->assertJsonSuccess($perteBalance);
        $perteBalance->assertJsonPath('data.balance_minor', 45000);
        $perteBalance->assertJsonPath('data.balance_side', LedgerAccount::NORMAL_BALANCE_DEBIT);

        $staffBalance = $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$staff.'/balance?currency=XAF');
        $this->assertJsonSuccess($staffBalance);
        $staffBalance->assertJsonPath('data.balance_minor', 0);
    }

    public function test_exercises_must_be_closed_in_order(): void
    {
        $chief = $this->createUserWithRole('chief-accountant');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CLO-SEQ');

        $earned = $this->createResultAccount($chief, $agency['public_id'], '701000', 'Intérêts reçus', 'produits', 'credit');
        $benefice = $this->createResultAccount($chief, $agency['public_id'], '131', "Bénéfice de l'exercice", 'capitaux_permanents', 'credit');
        $cash = $this->createAgencyLedgerAccount($chief, $agency['public_id'], '571000', 'Caisse');

        // Activity in two consecutive exercises.
        foreach ([['2025-06-30', 40000], ['2026-06-30', 25000]] as [$date, $amount]) {
            $this->ensureOpenAccountingDay($agency['id'], $date);
            $this->createPostedJournalEntryWithLines($chief, $reviewer, $agency['public_id'], 'JE-SEQ-'.$date, $date, [
                ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => $amount],
                ['ledger_account_public_id' => $cash, 'debit_minor' => $amount, 'credit_minor' => 0],
            ]);
        }
        // A clôture is posted into the accounting day of its own closing date, so
        // each step below opens the day it needs. That is also why the assertions
        // name the `fiscal_year` field: a closing refused because the wrong day is
        // open fails on `closes_on`, and would otherwise pass for the wrong reason.
        $this->ensureOpenAccountingDay($agency['id'], '2026-12-31');

        // 2026 cannot be closed while 2025 is open. Balances are cumulative, so
        // allowing it would report 65 000 as 2026's result — 2025's 40 000 folded
        // into it — and leave 2025 with nothing to close afterwards.
        $premature = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
            ]);
        $premature->assertStatus(422);
        $premature->assertJsonValidationErrors(['fiscal_year']);

        $this->ensureOpenAccountingDay($agency['id'], '2025-12-31');
        $closing2025 = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2025,
            ]);
        $this->assertJsonSuccess($closing2025, 201);
        $closing2025->assertJsonPath('data.net_result_minor', 40000);

        // Drawn up but awaiting review, so it has moved nothing: 2025 is still
        // sitting in classes 6 and 7 and 2026 stays blocked.
        $this->ensureOpenAccountingDay($agency['id'], '2026-12-31');
        $stillBlocked = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
            ]);
        $stillBlocked->assertStatus(422);
        $stillBlocked->assertJsonValidationErrors(['fiscal_year']);

        // Post 2025's transfer, which needs its own day open again.
        $entry2025 = $this->requireStringJsonPath($closing2025, 'data.journal_entry_public_id');
        $this->ensureOpenAccountingDay($agency['id'], '2025-12-31');
        $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entry2025.'/approve')->assertStatus(200);
        $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->postJson('/api/v1/journal-entries/'.$entry2025.'/post')->assertStatus(200);

        // 2025's result is now in 131, and only 2025's.
        $beneficeBalance = $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$benefice.'/balance?currency=XAF');
        $this->assertJsonSuccess($beneficeBalance);
        $beneficeBalance->assertJsonPath('data.balance_minor', 40000);

        // 2026 is now the next one due.
        $this->ensureOpenAccountingDay($agency['id'], '2026-12-31');
        $closing2026 = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
            ]);
        $this->assertJsonSuccess($closing2026, 201);
        // Its own 25 000, not 65 000: closing 2025 returned those accounts to nil,
        // which is what makes a cumulative balance equal one exercise's activity.
        $closing2026->assertJsonPath('data.net_result_minor', 25000);
    }

    public function test_an_unclosed_exercise_never_stops_an_agency_from_operating(): void
    {
        $chief = $this->createUserWithRole('chief-accountant');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CLO-IDLE');

        $earned = $this->createResultAccount($chief, $agency['public_id'], '701000', 'Intérêts reçus', 'produits', 'credit');
        $this->createResultAccount($chief, $agency['public_id'], '131', "Bénéfice de l'exercice", 'capitaux_permanents', 'credit');
        $cash = $this->createAgencyLedgerAccount($chief, $agency['public_id'], '571000', 'Caisse');

        // An agency that opened in 2025 and did nothing in it. 2025 has no result
        // to carry, so there is no clôture to draw for it.
        $this->ensureOpenAccountingDay($agency['id'], '2025-12-31');
        $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2025,
            ])->assertStatus(422);

        // It trades normally in 2026 regardless. Nothing outside the closing
        // endpoint consults exercise_closings, so an unclosed exercise cannot stop
        // an agency working — the sequencing rule governs when a year may be
        // settled, never whether business may be recorded.
        $this->ensureOpenAccountingDay($agency['id'], '2026-06-30');
        $this->createPostedJournalEntryWithLines($chief, $reviewer, $agency['public_id'], 'JE-IDLE', '2026-06-30', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => 52000],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 52000, 'credit_minor' => 0],
        ]);

        $balance = $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$earned.'/balance?currency=XAF');
        $this->assertJsonSuccess($balance);
        $balance->assertJsonPath('data.balance_minor', 52000);

        // And 2026 closes without 2025 ever having been settled: an idle year is
        // skipped, not owed. Refusing here would strand the agency permanently for
        // the sake of a year in which nothing happened.
        $this->ensureOpenAccountingDay($agency['id'], '2026-12-31');
        $closing = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
            ]);
        $this->assertJsonSuccess($closing, 201);
        $closing->assertJsonPath('data.net_result_minor', 52000);
        $closing->assertJsonPath('data.fiscal_year', 2026);
    }

    public function test_an_exercise_whose_entries_cancel_out_does_not_block_the_next_one(): void
    {
        $chief = $this->createUserWithRole('chief-accountant');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('CLO-NIL');

        $earned = $this->createResultAccount($chief, $agency['public_id'], '701000', 'Intérêts reçus', 'produits', 'credit');
        $this->createResultAccount($chief, $agency['public_id'], '131', "Bénéfice de l'exercice", 'capitaux_permanents', 'credit');
        $cash = $this->createAgencyLedgerAccount($chief, $agency['public_id'], '571000', 'Caisse');

        // 2025: an entry and its correction. Lines exist for the year, but every
        // class 6 and 7 account ends it at nil, so there is no result to carry.
        $this->ensureOpenAccountingDay($agency['id'], '2025-06-30');
        $this->createPostedJournalEntryWithLines($chief, $reviewer, $agency['public_id'], 'JE-NIL-1', '2025-06-30', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => 18000],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 18000, 'credit_minor' => 0],
        ]);
        $this->createPostedJournalEntryWithLines($chief, $reviewer, $agency['public_id'], 'JE-NIL-2', '2025-06-30', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 18000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 18000],
        ]);

        // 2026: real activity.
        $this->ensureOpenAccountingDay($agency['id'], '2026-06-30');
        $this->createPostedJournalEntryWithLines($chief, $reviewer, $agency['public_id'], 'JE-NIL-3', '2026-06-30', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => 31000],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 31000, 'credit_minor' => 0],
        ]);

        // There is nothing to close in 2025 — correctly refused rather than
        // recorded as an empty clôture.
        $this->ensureOpenAccountingDay($agency['id'], '2025-12-31');
        $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2025,
            ])->assertStatus(422);

        // And 2025 must therefore not block 2026. Deciding "is a clôture owed for
        // this year?" with a cheaper test than the one that draws it would leave
        // 2026 demanding a 2025 closing that can never be created — a deadlock with
        // no way out but a database edit.
        $this->ensureOpenAccountingDay($agency['id'], '2026-12-31');
        $closing = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
            ]);
        $this->assertJsonSuccess($closing, 201);

        // 2026's own result, and only its own: 2025's entries cancelled, so the
        // cumulative balance carries nothing forward.
        $closing->assertJsonPath('data.net_result_minor', 31000);
    }

    public function test_closing_refuses_an_empty_exercise_and_needs_the_permission(): void
    {
        $chief = $this->createUserWithRole('chief-accountant');
        $accountant = $this->createUserWithRole('accountant');
        $agency = $this->createAgency('CLO-C');
        $this->ensureOpenAccountingDay($agency['id'], '2026-12-31');

        // Nothing posted: there is no result to carry, and inventing a nil entry
        // would put an empty clôture on the record and block the real one.
        $empty = $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
            ]);
        $empty->assertStatus(422);
        $empty->assertJsonValidationErrors(['fiscal_year']);

        // An accountant may keep the books but not close the year.
        $this->withApiHeaders()->actingAsSanctum($accountant)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 2026,
            ])->assertForbidden();

        // And a year the institution cannot have traded in is refused outright.
        $this->withApiHeaders()->actingAsSanctum($chief)
            ->postJson('/api/v1/exercise-closings', [
                'agency_public_id' => $agency['public_id'],
                'fiscal_year' => 12,
            ])->assertStatus(422);
    }

    public function test_the_net_result_names_its_destination_without_being_fed_by_it(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-CARRY');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // Their remarque, confirmed: « à la fin de l'exercice, le résultat du 87
        // doit être transféré dans le 131 s'il est positif (bénéfice) ou dans le
        // 132 s'il est négatif (perte) ».
        //
        // The transfer itself is a clôture annuelle and does not exist yet. What
        // must hold before it is written is that the two directions do not meet:
        // the compte de résultat reads classes 6 and 7, and 131/132 are class 1,
        // so carrying the result must not alter the result. Otherwise the first
        // closing would either double the bénéfice or wipe the soldes, depending
        // on which way the feedback ran, and the figure would move every time the
        // report was re-run.
        $earned = $this->createResultAccount($admin, $agency['public_id'], '701000', 'Intérêts reçus', 'produits', 'credit');
        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571000', 'Caisse');
        $benefice = $this->createResultAccount($admin, $agency['public_id'], '131000', "Bénéfice de l'exercice", 'capitaux_permanents', 'credit');

        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-CARRY-1', '2026-05-01', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => 60000],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 60000, 'credit_minor' => 0],
        ]);

        $before = $this->postIncomeStatement($admin, $agency['public_id']);
        $before->assertJsonPath('data.summary.net_result_minor', 60000);
        // Names where it goes; does not claim to have sent it there.
        $before->assertJsonPath('data.summary.net_result_carries_to_code', '131');

        // Now carry it, the way a clôture eventually will.
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-CARRY-2', '2026-05-01', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 60000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $benefice, 'debit_minor' => 0, 'credit_minor' => 60000],
        ]);

        // The produit is now closed out, so the exercise reads nil — correct, and
        // the reason a clôture entry has to fall outside the period the report
        // covers, or on the far side of its own cut-off. Whoever writes the
        // closing needs to know this: dating it inside the exercise it closes
        // empties the very statement it was drawn from.
        $after = $this->postIncomeStatement($admin, $agency['public_id']);
        $after->assertJsonPath('data.summary.net_result_minor', 0);

        // And the 131 posting itself contributed nothing: class 1 is not read by
        // the compte de résultat, so the result was zeroed by closing the produit,
        // not doubled by crediting the bénéfice.
        $soldes = $this->soldesFrom($after->json('data.summary.rows'));
        self::assertSame(0, $this->soldeAmount($soldes, '80'));
        self::assertSame(0, $this->soldeAmount($soldes, '87'));

        // The bénéfice is where it was put, untouched by any of this.
        $balance = $this->withApiHeaders()->actingAsSanctum($reviewer)
            ->getJson('/api/v1/ledger-accounts/'.$benefice.'/balance?currency=XAF');
        $this->assertJsonSuccess($balance);
        $balance->assertJsonPath('data.balance_minor', 60000);
        $balance->assertJsonPath('data.balance_side', LedgerAccount::NORMAL_BALANCE_CREDIT);
    }

    public function test_the_corporate_tax_leaves_the_operating_result_untouched_and_the_patente_does_not(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-TAX2');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // Their answer to question 3, stated precisely: « le calcul du 82 garde
        // alors sa formule normale (moins toute la classe 66) et on lui rajoute
        // + 6611 pour annuler juste cette ligne là ».
        //
        // "Annuler juste cette ligne là" is an exact claim, and a stronger one
        // than the mixed exercise elsewhere in this file checks: the corporate
        // income tax must leave the résultat d'exploitation at precisely the value
        // it would have had if the tax had never been posted. Each half is
        // therefore posted alone, where any leakage shows up as the whole amount
        // rather than as a discrepancy someone has to spot.
        $tax = $this->createResultAccount($admin, $agency['public_id'], '661100', 'Impôt sur les sociétés', 'charges', 'debit');
        $patente = $this->createResultAccount($admin, $agency['public_id'], '661200', 'Patente', 'charges', 'debit');
        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571000', 'Caisse');

        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-TAX-ONLY', '2026-05-01', [
            ['ledger_account_public_id' => $tax, 'debit_minor' => 17000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 17000],
        ]);

        $soldes = $this->soldesFrom($this->postIncomeStatement($admin, $agency['public_id'])->json('data.summary.rows'));

        // Subtracted with the rest of class 66, then returned in full: no trace.
        self::assertSame(0, $this->soldeAmount($soldes, '82'));
        self::assertNull($this->soldeSideOf($soldes, '82'));
        self::assertSame(0, $this->soldeAmount($soldes, '85'));

        // It appears once, at 86, and once only — which is what makes solde 86
        // credible as the tax line.
        self::assertSame(17000, $this->soldeAmount($soldes, '86'));
        self::assertSame(-17000, $this->soldeAmount($soldes, '87'));

        // Now the other direct tax, which must behave the opposite way.
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-PATENTE', '2026-05-01', [
            ['ledger_account_public_id' => $patente, 'debit_minor' => 4000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 4000],
        ]);

        $after = $this->soldesFrom($this->postIncomeStatement($admin, $agency['public_id'])->json('data.summary.rows'));

        // « les autres impôts directs restent bien dans le 82 »: the patente is a
        // cost of operating and stays charged there.
        self::assertSame(-4000, $this->soldeAmount($after, '82'));
        self::assertSame(LedgerAccount::NORMAL_BALANCE_DEBIT, $this->soldeSideOf($after, '82'));

        // And it never reaches the tax line, which still shows only the 6611
        // amount. Had the add-back been written as the whole of 661, this would
        // read 21 000 and the patente would have been charged twice over.
        self::assertSame(17000, $this->soldeAmount($after, '86'));
        self::assertSame(-21000, $this->soldeAmount($after, '87'));
    }

    public function test_credit_provisions_are_ordinary_business_and_never_exceptional(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-PROV');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // Their answer to question 2: « les comptes 69 et 79 restent dans le
        // calcul du 82. Les provisions pour risque de crédit et les pertes sur
        // créances font partie du métier normal d'un établissement qui prête de
        // l'argent : ce n'est pas un événement exceptionnel. »
        //
        // The literal placement cannot be tested, because 83 equals 82 and the two
        // are indistinguishable from outside. What can be tested is the reason
        // they gave, which is the part that carries consequences: a provision is
        // not exceptional, so it must reach the résultat d'exploitation and never
        // solde 84. Booked as exceptional, a lender's loan losses would leave the
        // résultat d'exploitation showing a business that never loses money on
        // lending — the single figure that says whether the lending itself works.
        $dotation = $this->createResultAccount($admin, $agency['public_id'], '691000', 'Dotations aux provisions', 'charges', 'debit');
        $reprise = $this->createResultAccount($admin, $agency['public_id'], '791000', 'Reprises de provisions', 'produits', 'credit');
        $provision = $this->createResultAccount($admin, $agency['public_id'], '391000', 'Provisions sur créances douteuses', 'operations_clientele', 'credit');

        // The real double entry: a dotation raises the provision, a reprise
        // releases it. The counterpart is class 3, which the compte de résultat
        // does not read, so only the charge and the produit reach the soldes.
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-DOT', '2026-05-01', [
            ['ledger_account_public_id' => $dotation, 'debit_minor' => 20000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $provision, 'debit_minor' => 0, 'credit_minor' => 20000],
        ]);
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-REP', '2026-05-01', [
            ['ledger_account_public_id' => $provision, 'debit_minor' => 8000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $reprise, 'debit_minor' => 0, 'credit_minor' => 8000],
        ]);

        $soldes = $this->soldesFrom($this->postIncomeStatement($admin, $agency['public_id'])->json('data.summary.rows'));

        // Not financial, not accessory: the PNF and the produit d'exploitation
        // global are untouched.
        self::assertSame(0, $this->soldeAmount($soldes, '80'));
        self::assertSame(0, $this->soldeAmount($soldes, '81'));

        // The net cost of credit risk lands in the résultat d'exploitation, as a
        // charge: 8 000 released against 20 000 provided.
        self::assertSame(-12000, $this->soldeAmount($soldes, '82'));
        self::assertSame(LedgerAccount::NORMAL_BALANCE_DEBIT, $this->soldeSideOf($soldes, '82'));

        // And nothing reaches the exceptional result — the assertion their reason
        // actually makes.
        self::assertSame(0, $this->soldeAmount($soldes, '84'));
        self::assertNull($this->soldeSideOf($soldes, '84'));

        // 83 carries 82 unchanged, which is their answer to question 4 seen from
        // the report rather than from the definitions.
        self::assertSame(
            $this->soldeAmount($soldes, '82'),
            $this->soldeAmount($soldes, '83'),
        );
        self::assertSame(-12000, $this->soldeAmount($soldes, '87'));
    }

    public function test_exceptional_items_reach_the_exceptional_result_and_nothing_above_it(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-EXC');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // 77 and 67 are the only roots of classes 6 and 7 that bypass the
        // operating soldes entirely: « 84 = 77 − 67 ». The same blind spot applies
        // — an exceptional profit counted in 81 would leave the résultat net
        // correct and the résultat d'exploitation overstated, which is precisely
        // the figure used to judge whether the ordinary business is viable.
        $exceptionalGain = $this->createResultAccount($admin, $agency['public_id'], '771000', 'Profit exceptionnel', 'produits', 'credit');
        $exceptionalLoss = $this->createResultAccount($admin, $agency['public_id'], '671000', 'Perte exceptionnelle', 'charges', 'debit');
        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571000', 'Caisse');

        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-EXC', '2026-05-01', [
            ['ledger_account_public_id' => $exceptionalGain, 'debit_minor' => 0, 'credit_minor' => 30000],
            ['ledger_account_public_id' => $exceptionalLoss, 'debit_minor' => 11000, 'credit_minor' => 0],
            // Net of the two, so the entry balances: 11 000 + 19 000 = 30 000.
            ['ledger_account_public_id' => $cash, 'debit_minor' => 19000, 'credit_minor' => 0],
        ]);

        $soldes = $this->soldesFrom($this->postIncomeStatement($admin, $agency['public_id'])->json('data.summary.rows'));

        // Nothing exceptional touches the ordinary business.
        foreach (['80', '81', '82', '83'] as $ordinary) {
            self::assertSame(0, $this->soldeAmount($soldes, $ordinary), "Solde {$ordinary} must ignore exceptional items.");
        }

        self::assertSame(19000, $this->soldeAmount($soldes, '84'));
        self::assertSame(19000, $this->soldeAmount($soldes, '85'));
        self::assertSame(19000, $this->soldeAmount($soldes, '87'));
    }

    public function test_the_net_result_is_all_produits_less_all_charges(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-ID');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // The eight formulas partition classes 6 and 7, and the 6611 term appears
        // twice with opposite signs — added into 82, taken out again at 87 through
        // solde 86. So however elaborate the intermediate soldes, the résultat net
        // has to reduce to the plainest statement in accounting: everything earned
        // less everything spent.
        //
        // Asserting the identity rather than re-deriving the formulas in the test:
        // a test that recomputes them only proves the arithmetic agrees with
        // itself. This one would fail if the tax add-back stopped cancelling,
        // which would put the résultat out by exactly the corporate tax — big
        // enough to matter, plausible enough to go unquestioned.
        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571000', 'Caisse');

        // One account in every root of both classes, so nothing can hide in a
        // root the formulas forgot. Two entries rather than twenty: the journal
        // write throttle is real, and a balanced entry may carry many lines.
        $produitLines = [];
        $produits = 0;
        foreach (range(70, 79) as $index => $root) {
            $amount = 1000 * ($index + 1);
            $produitLines[] = [
                'ledger_account_public_id' => $this->createResultAccount($admin, $agency['public_id'], $root.'1000', 'Produit '.$root, 'produits', 'credit'),
                'debit_minor' => 0,
                'credit_minor' => $amount,
            ];
            $produits += $amount;
        }
        $produitLines[] = ['ledger_account_public_id' => $cash, 'debit_minor' => $produits, 'credit_minor' => 0];

        $chargeLines = [];
        $charges = 0;
        foreach (range(60, 69) as $index => $root) {
            $amount = 500 * ($index + 1);
            $chargeLines[] = [
                'ledger_account_public_id' => $this->createResultAccount($admin, $agency['public_id'], $root.'1000', 'Charge '.$root, 'charges', 'debit'),
                'debit_minor' => $amount,
                'credit_minor' => 0,
            ];
            $charges += $amount;
        }

        // And the corporate income tax specifically, the one amount the formulas
        // handle twice.
        $chargeLines[] = [
            'ledger_account_public_id' => $this->createResultAccount($admin, $agency['public_id'], '661100', 'Impôt sur les sociétés', 'charges', 'debit'),
            'debit_minor' => 12000,
            'credit_minor' => 0,
        ];
        $charges += 12000;
        $chargeLines[] = ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => $charges];

        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-PRODUITS', '2026-05-01', $produitLines);
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-CHARGES', '2026-05-01', $chargeLines);

        $response = $this->postIncomeStatement($admin, $agency['public_id']);
        $response->assertJsonPath('data.summary.net_result_minor', $produits - $charges);

        // The tax reaches solde 86 in full, and leaves the résultat d'exploitation
        // rather than being counted there as well.
        $soldes = $this->soldesFrom($response->json('data.summary.rows'));
        self::assertSame(12000, $this->soldeAmount($soldes, '86'));
        self::assertSame(
            $this->soldeAmount($soldes, '85') - 12000,
            $this->soldeAmount($soldes, '87'),
        );
    }

    public function test_no_class_eight_account_can_be_created_at_all(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-C8');

        // « Aucun compte de la classe 8 n'est créé dans la table des comptes sur
        // lesquels on peut saisir une écriture, parce qu'on n'y saisit jamais
        // rien directement. » The seeder honours that, but the seeder is not the
        // only way an account appears: a class 8 code posted here would create
        // somewhere to file entries that the compte de résultat computes from
        // classes 6 and 7 and would never read, so the money would leave the
        // income statement entirely.
        $postable = $this->withApiHeaders()->actingAsSanctum($admin)
            ->postJson('/api/v1/ledger-accounts', [
                'agency_public_id' => $agency['public_id'],
                'code' => '800000',
                'name' => 'Produit net financier',
                'account_class' => LedgerAccount::ACCOUNT_CLASS_SOLDES_INTERMEDIAIRES_GESTION,
                'normal_balance_side' => 'credit',
            ]);
        $postable->assertStatus(422);
        $postable->assertJsonValidationErrors(['code']);

        // Not even as an institution grouping account. Those are legitimate
        // elsewhere, but a class 8 grouping consolidates by parent_account_id and
        // the soldes aggregate classes 6 and 7 instead, so it could only ever
        // report zero — « ils resteraient toujours vides ».
        $grouping = $this->withApiHeaders()->actingAsSanctum($admin)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'institution',
                'code' => '80',
                'name' => 'Soldes intermédiaires de gestion',
                'account_class' => LedgerAccount::ACCOUNT_CLASS_SOLDES_INTERMEDIAIRES_GESTION,
                'normal_balance_side' => null,
            ]);
        $grouping->assertStatus(422);

        self::assertSame(0, DB::table('ledger_accounts')->whereRaw('left(code, 1) = ?', ['8'])->count());
    }

    public function test_the_income_statement_builds_the_eight_soldes_from_classes_six_and_seven(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-A');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // A small but complete exercise: interest earned and paid, staff costs,
        // corporate income tax, one other direct tax, and an exceptional loss.
        // Enough that every solde has something to say.
        $earned = $this->createResultAccount($admin, $agency['public_id'], '701000', 'Intérêts reçus', 'produits', 'credit');
        $paid = $this->createResultAccount($admin, $agency['public_id'], '601000', 'Intérêts payés', 'charges', 'debit');
        $staff = $this->createResultAccount($admin, $agency['public_id'], '651000', 'Charges de personnel', 'charges', 'debit');
        $incomeTax = $this->createResultAccount($admin, $agency['public_id'], '661100', 'Impôt sur les sociétés', 'charges', 'debit');
        $patente = $this->createResultAccount($admin, $agency['public_id'], '661200', 'Patente', 'charges', 'debit');
        $exceptional = $this->createResultAccount($admin, $agency['public_id'], '671000', 'Perte exceptionnelle', 'charges', 'debit');
        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571000', 'Caisse');

        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-IS-1', '2026-05-01', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => 120000],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 120000, 'credit_minor' => 0],
        ]);
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-IS-2', '2026-05-01', [
            ['ledger_account_public_id' => $paid, 'debit_minor' => 30000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 30000],
        ]);
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-IS-3', '2026-05-01', [
            ['ledger_account_public_id' => $staff, 'debit_minor' => 40000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 40000],
        ]);
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-IS-4', '2026-05-01', [
            ['ledger_account_public_id' => $incomeTax, 'debit_minor' => 9000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $patente, 'debit_minor' => 5000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 14000],
        ]);
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-IS-5', '2026-05-01', [
            ['ledger_account_public_id' => $exceptional, 'debit_minor' => 7000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 7000],
        ]);

        $soldes = $this->runIncomeStatement($admin, $agency['public_id']);

        // 80 = produits 120 000 − charges financières 30 000
        self::assertSame(90000, $this->soldeAmount($soldes, '80'));
        // 81 = 80: nothing accessory posted
        self::assertSame(90000, $this->soldeAmount($soldes, '81'));
        // 82 = 81 − 65 (40 000) − 66 (14 000) + 6611 (9 000). The corporate tax
        // is added back and the patente is not: that is the point of the split.
        self::assertSame(45000, $this->soldeAmount($soldes, '82'));
        // 83 = 82, confirmed by the accounting team on 2026-08-10
        self::assertSame(45000, $this->soldeAmount($soldes, '83'));
        // 84 = 77 − 67 = 0 − 7 000
        self::assertSame(-7000, $this->soldeAmount($soldes, '84'));
        self::assertSame(38000, $this->soldeAmount($soldes, '85'));
        // 86 carries the isolated corporate tax and nothing else
        self::assertSame(9000, $this->soldeAmount($soldes, '86'));
        self::assertSame(29000, $this->soldeAmount($soldes, '87'));

        // « bénéfice = crédit »
        self::assertSame(LedgerAccount::NORMAL_BALANCE_CREDIT, $this->soldeSideOf($soldes, '87'));
    }

    public function test_a_loss_reads_as_debit_and_carries_to_the_loss_account(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-B');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        $staff = $this->createResultAccount($admin, $agency['public_id'], '651000', 'Charges de personnel', 'charges', 'debit');
        $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571000', 'Caisse');

        // Charges only: the exercise is a loss.
        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-IS-LOSS', '2026-05-01', [
            ['ledger_account_public_id' => $staff, 'debit_minor' => 25000, 'credit_minor' => 0],
            ['ledger_account_public_id' => $cash, 'debit_minor' => 0, 'credit_minor' => 25000],
        ]);

        $response = $this->postIncomeStatement($admin, $agency['public_id']);
        $response->assertJsonPath('data.summary.net_result_minor', -25000);
        $response->assertJsonPath('data.summary.net_result_side', LedgerAccount::NORMAL_BALANCE_DEBIT);
        $response->assertJsonPath('data.summary.net_result_carries_to_code', '132');

        $soldes = $this->soldesFrom($response->json('data.summary.rows'));

        // A solde nobody touched sits on neither side rather than defaulting to
        // one: 84 has no exceptional profit and no exceptional loss.
        self::assertSame(0, $this->soldeAmount($soldes, '84'));
        self::assertNull($this->soldeSideOf($soldes, '84'));
    }

    public function test_an_exercise_that_breaks_even_is_a_nil_profit_not_a_loss(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-C');
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        $earned = $this->createResultAccount($admin, $agency['public_id'], '701000', 'Intérêts reçus', 'produits', 'credit');
        $paid = $this->createResultAccount($admin, $agency['public_id'], '601000', 'Intérêts payés', 'charges', 'debit');

        $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-IS-EVEN', '2026-05-01', [
            ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => 15000],
            ['ledger_account_public_id' => $paid, 'debit_minor' => 15000, 'credit_minor' => 0],
        ]);

        // Neither side, and carried as a bénéfice of nothing: filing nil in 132
        // would report the institution as loss-making for the year.
        $response = $this->postIncomeStatement($admin, $agency['public_id']);
        $response->assertJsonPath('data.summary.net_result_minor', 0);
        $response->assertJsonPath('data.summary.net_result_side', null);
        $response->assertJsonPath('data.summary.net_result_carries_to_code', '131');
    }

    public function test_the_institution_wide_income_statement_needs_institution_read(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('IS-D');
        // An auditor holds accounting.audit.view — so may run reports — but not
        // ledger.scope.institution.read. That is the exact pair the consolidated
        // trial balance gate was written for, so it is the pair to test with.
        $auditor = $this->createUserWithRole('auditor', $agency['code'], $agency['name']);
        $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');

        // Omitting the agency asks for the institution's result, summed across
        // every agency — the same cross-agency reach the consolidated trial
        // balance gates. Ungated, an auditor could read here what they are
        // refused there.
        $this->postIncomeStatement($auditor, null, expectSuccess: false)->assertForbidden();

        // Their own agency is their own book, and is allowed — which also proves
        // the refusal above came from the institution gate and not from a missing
        // permission to run reports at all.
        $own = $this->postIncomeStatement($auditor, $agency['public_id']);
        $own->assertJsonPath('data.summary.scope', LedgerAccount::SCOPE_AGENCY);

        $wide = $this->postIncomeStatement($admin, null);
        $wide->assertJsonPath('data.summary.scope', LedgerAccount::SCOPE_INSTITUTION);
    }

    public function test_the_institution_income_statement_sums_every_agency(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $reviewer = $this->createUserWithRole('platform-admin');
        $agencyA = $this->createAgency('IS-E');
        $agencyB = $this->createAgency('IS-F');

        foreach ([[$agencyA, 60000], [$agencyB, 40000]] as [$agency, $amount]) {
            $this->ensureOpenAccountingDay($agency['id'], '2026-05-01');
            $earned = $this->createResultAccount($admin, $agency['public_id'], '701000', 'Intérêts reçus', 'produits', 'credit');
            $cash = $this->createAgencyLedgerAccount($admin, $agency['public_id'], '571000', 'Caisse');
            $this->createPostedJournalEntryWithLines($admin, $reviewer, $agency['public_id'], 'JE-'.$agency['code'], '2026-05-01', [
                ['ledger_account_public_id' => $earned, 'debit_minor' => 0, 'credit_minor' => $amount],
                ['ledger_account_public_id' => $cash, 'debit_minor' => $amount, 'credit_minor' => 0],
            ]);
        }

        // Each agency keeps its own 701000 under the same code, so the
        // institution's PNF is the two added together rather than either one.
        self::assertSame(100000, $this->soldeAmount($this->runIncomeStatement($admin, null), '80'));
        self::assertSame(60000, $this->soldeAmount($this->runIncomeStatement($admin, $agencyA['public_id']), '80'));
    }

    private function createResultAccount(User $actor, string $agencyPublicId, string $code, string $name, string $class, string $side): string
    {
        $response = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/ledger-accounts', [
            'agency_public_id' => $agencyPublicId,
            'code' => $code,
            'name' => $name,
            'account_class' => $class,
            'normal_balance_side' => $side,
        ]);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function postIncomeStatement(User $actor, ?string $agencyPublicId, bool $expectSuccess = true, string $businessDate = '2026-05-01'): TestResponse
    {
        $payload = [
            'report_definition_public_id' => $this->incomeStatementDefinitionPublicId(),
            'period_starts_on' => $businessDate,
            'period_ends_on' => $businessDate,
            'currency' => 'XAF',
        ];
        if ($agencyPublicId !== null) {
            $payload['agency_public_id'] = $agencyPublicId;
        }

        $response = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/report-runs', $payload);
        if ($expectSuccess) {
            $this->assertJsonSuccess($response, 201);
        }

        return $response;
    }

    /**
     * @return array<int, array{code: string, amount_minor: int, balance_side: string|null}>
     */
    private function runIncomeStatement(User $actor, ?string $agencyPublicId): array
    {
        $response = $this->postIncomeStatement($actor, $agencyPublicId);
        $response->assertJsonPath('data.summary.report_type', ReportDefinition::TYPE_INCOME_STATEMENT);

        return $this->soldesFrom($response->json('data.summary.rows'));
    }

    /**
     * Kept as a list rather than keyed by code: PHP turns the key '80' into the
     * integer 80, so a map would hand back integers and every string lookup
     * would miss.
     *
     * @return array<int, array{code: string, amount_minor: int, balance_side: string|null}>
     */
    private function soldesFrom(mixed $rows): array
    {
        self::assertIsArray($rows);

        $soldes = [];
        foreach ($rows as $row) {
            self::assertIsArray($row);
            $code = $row['code'] ?? null;
            self::assertIsString($code);
            $amount = $row['amount_minor'] ?? null;
            self::assertIsInt($amount);
            $side = $row['balance_side'] ?? null;
            $soldes[] = [
                'code' => $code,
                'amount_minor' => $amount,
                'balance_side' => is_string($side) ? $side : null,
            ];
        }

        self::assertCount(8, $soldes);

        return $soldes;
    }

    /**
     * @param  array<int, array{code: string, amount_minor: int, balance_side: string|null}>  $soldes
     */
    private function soldeAmount(array $soldes, string $code): int
    {
        foreach ($soldes as $solde) {
            if ($solde['code'] === $code) {
                return $solde['amount_minor'];
            }
        }

        self::fail("The report has no solde {$code}.");
    }

    /**
     * @param  array<int, array{code: string, amount_minor: int, balance_side: string|null}>  $soldes
     */
    private function soldeSideOf(array $soldes, string $code): ?string
    {
        foreach ($soldes as $solde) {
            if ($solde['code'] === $code) {
                return $solde['balance_side'];
            }
        }

        self::fail("The report has no solde {$code}.");
    }

    private function incomeStatementDefinitionPublicId(): string
    {
        $existing = DB::table('report_definitions')->where('code', 'income_statement')->first(['public_id']);
        if ($existing !== null) {
            return (string) $existing->public_id;
        }

        $id = DB::table('report_definitions')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => 'income_statement',
            'name' => 'Compte de résultat',
            'report_type' => ReportDefinition::TYPE_INCOME_STATEMENT,
            'module' => 'accounting',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('report_definitions')->where('id', $id)->first(['public_id']);
        self::assertNotNull($row);

        return (string) $row->public_id;
    }

    private function createInstitutionLedgerAccount(User $actor, string $code, string $name): string
    {
        $response = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/ledger-accounts', [
                'scope' => 'institution',
                'code' => $code,
                'name' => $name,
                'account_class' => 'tresorerie_interbancaire',
                'normal_balance_side' => 'debit',
            ]);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function createAgencyLedgerAccount(User $actor, string $agencyPublicId, string $code, string $name, ?string $parentPublicId = null): string
    {
        $payload = [
            'agency_public_id' => $agencyPublicId,
            'code' => $code,
            'name' => $name,
            'account_class' => 'tresorerie_interbancaire',
            'normal_balance_side' => 'debit',
        ];
        if ($parentPublicId !== null) {
            $payload['parent_account_public_id'] = $parentPublicId;
        }

        $response = $this->withApiHeaders()->actingAsSanctum($actor)->postJson('/api/v1/ledger-accounts', $payload);
        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
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
        $agencyId = $this->agencyIdFromPublicId($agencyPublicId);
        $this->ensureOpenAccountingDay($agencyId, $businessDate);

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

    /**
     * The consolidated trial balance row for a ledger account code, failing the
     * test when the rollup omitted it.
     *
     * @param  array<mixed>  $rows
     * @return array<mixed>
     */
    private function consolidatedRow(array $rows, string $code): array
    {
        foreach ($rows as $row) {
            self::assertIsArray($row);
            if (($row['ledger_account_code'] ?? null) === $code) {
                return $row;
            }
        }

        self::fail("The consolidated trial balance has no row for {$code}.");
    }
}
