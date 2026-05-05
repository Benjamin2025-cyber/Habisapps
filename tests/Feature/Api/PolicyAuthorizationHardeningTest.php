<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\ClientGuarantor;
use App\Models\ClientIdentityDocument;
use App\Models\ClientProxy;
use App\Models\CustomerAccount;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PolicyAuthorizationHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_accounting_policy_preserves_platform_admin_access(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AUTH-ACCT-01');
        $clientPublicId = $this->createClient($agency['id']);

        $response = $this->actingAsSanctum($admin)
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'account_number' => 'AUTH-CA-001',
                'opened_on' => now()->toDateString(),
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.account_number', 'AUTH-CA-001');
    }

    public function test_accounting_policy_preserves_agency_scoped_list_access(): void
    {
        $agencyA = $this->createAgency('AUTH-ACCT-02A');
        $agencyB = $this->createAgency('AUTH-ACCT-02B');
        $agencyManager = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);

        $accountA = $this->createCustomerAccount($agencyA['id'], 'AUTH-CA-002A');
        $accountB = $this->createCustomerAccount($agencyB['id'], 'AUTH-CA-002B');

        $response = $this->actingAsSanctum($agencyManager)
            ->getJson('/api/v1/customer-accounts');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.customer_accounts.0.public_id', $accountA);
        $response->assertJsonMissing(['public_id' => $accountB]);
    }

    public function test_crm_policy_preserves_institution_read_access_and_denies_cross_agency_staff(): void
    {
        $agencyA = $this->createAgency('AUTH-CRM-03A');
        $agencyB = $this->createAgency('AUTH-CRM-03B');
        $clientPublicId = $this->createClient($agencyB['id']);

        $institutionReader = $this->createUserWithRole('auditor');
        $crossAgencyOfficer = $this->createUserWithRole('kyc-officer', $agencyA['code'], $agencyA['name']);

        $allowed = $this->actingAsSanctum($institutionReader)
            ->getJson('/api/v1/clients/'.$clientPublicId);

        $this->assertJsonSuccess($allowed);
        $allowed->assertJsonPath('data.public_id', $clientPublicId);

        $denied = $this->actingAsSanctum($crossAgencyOfficer)
            ->getJson('/api/v1/clients/'.$clientPublicId);

        $this->assertSafeForbidden($denied);
    }

    public function test_read_only_accounting_user_cannot_mutate_customer_accounts(): void
    {
        $agency = $this->createAgency('AUTH-ACCT-04');
        $clientPublicId = $this->createClient($agency['id']);
        $agencyManager = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);

        $response = $this->actingAsSanctum($agencyManager)
            ->postJson('/api/v1/customer-accounts', [
                'client_public_id' => $clientPublicId,
                'agency_public_id' => $agency['public_id'],
                'account_number' => 'AUTH-CA-004',
                'opened_on' => now()->toDateString(),
            ]);

        $this->assertSafeForbidden($response);
        $this->assertDatabaseMissing('customer_accounts', [
            'account_number' => 'AUTH-CA-004',
        ]);
    }

    public function test_nested_crm_child_routes_fail_closed_for_wrong_parent_public_ids(): void
    {
        $admin = $this->createUserWithRole('platform-admin');
        $agency = $this->createAgency('AUTH-CRM-05');
        $parentClient = $this->createClientModel($agency['id'], 'AUTH-CLI-PARENT');
        $otherClient = $this->createClientModel($agency['id'], 'AUTH-CLI-OTHER');

        $identityDocument = ClientIdentityDocument::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_id' => $otherClient->id,
            'document_type' => 'national_id',
            'document_number' => 'AUTHDOC001',
            'verification_status' => ClientIdentityDocument::VERIFICATION_PENDING,
            'status' => ClientIdentityDocument::STATUS_ACTIVE,
            'created_by_user_id' => $admin->id,
        ]);

        $guarantor = ClientGuarantor::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_id' => $otherClient->id,
            'guarantor_full_name' => 'Wrong Parent Guarantor',
            'status' => ClientGuarantor::STATUS_ACTIVE,
            'verification_status' => ClientGuarantor::VERIFICATION_PENDING,
            'created_by_user_id' => $admin->id,
        ]);

        $proxy = ClientProxy::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_id' => $otherClient->id,
            'proxy_full_name' => 'Wrong Parent Proxy',
            'mandate_type' => 'collection',
            'status' => ClientProxy::STATUS_ACTIVE,
            'verification_status' => ClientProxy::VERIFICATION_PENDING,
            'created_by_user_id' => $admin->id,
        ]);

        $this->actingAsSanctum($admin)
            ->getJson('/api/v1/clients/'.$parentClient->public_id.'/identity-documents/'.$identityDocument->public_id)
            ->assertNotFound();

        $this->actingAsSanctum($admin)
            ->getJson('/api/v1/clients/'.$parentClient->public_id.'/guarantors/'.$guarantor->public_id)
            ->assertNotFound();

        $this->actingAsSanctum($admin)
            ->getJson('/api/v1/clients/'.$parentClient->public_id.'/proxies/'.$proxy->public_id)
            ->assertNotFound();
    }

    public function test_audit_event_resource_does_not_expose_internal_integer_ids(): void
    {
        $auditor = $this->createUserWithRole('auditor');
        $subject = $this->createUserWithRole('staff');

        activity('security')
            ->event('resource.contract.checked')
            ->causedBy($auditor)
            ->performedOn($subject)
            ->log('resource.contract.checked');

        $response = $this->actingAsSanctum($auditor)
            ->getJson('/api/v1/audit-events?log_name=security');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.events.0.event', 'resource.contract.checked');
        $response->assertJsonMissingPath('data.events.0.id');
        $response->assertJsonMissingPath('data.events.0.subject_id');
        $response->assertJsonMissingPath('data.events.0.causer_id');
    }

    private function assertSafeForbidden(TestResponse $response): void
    {
        $response->assertStatus(403);
        $response->assertJsonPath('success', false);
        $response->assertJsonMissingPath('exception');
        $response->assertJsonMissingPath('file');
        $response->assertJsonMissingPath('line');
        $response->assertJsonMissingPath('trace');
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

    private function createClient(int $agencyId): string
    {
        $client = $this->createClientModel($agencyId, 'AUTH-CLI-'.Str::ulid());

        return $client->public_id;
    }

    private function createClientModel(int $agencyId, string $clientReference): Client
    {
        return Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => $clientReference,
            'first_name' => 'Policy',
            'last_name' => 'Client',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_VERIFIED,
        ]);
    }

    private function createCustomerAccount(int $agencyId, string $accountNumber): string
    {
        $clientPublicId = $this->createClient($agencyId);
        $client = Client::query()->where('public_id', $clientPublicId)->firstOrFail();

        $account = CustomerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'agency_id' => $agencyId,
            'account_number' => $accountNumber,
            'opened_on' => now()->toDateString(),
            'status' => CustomerAccount::STATUS_ACTIVE,
        ]);

        return $account->public_id;
    }
}
