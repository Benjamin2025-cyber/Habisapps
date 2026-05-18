<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Resources\ClientResource;
use App\Models\Agency;
use App\Models\Client;
use App\Models\ClientIdentityDocument;
use App\Models\ClientProxy;
use App\Models\CustomerAccount;
use App\Models\Document;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Support\Crm\ClientCollectionProfile;
use App\Support\Crm\ClientProxyMandateAuthorizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class Module2CrmKycTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_platform_admin_can_create_update_and_view_client(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-CRM-1');
        $actor = $this->createUserWithRole('platform-admin');
        $profilePhotoPublicId = $this->createDocumentInAgency($agency['id'], 'profile_photo');
        $classification = $this->createSectorAndSubSector('TRADE', 'RETAIL');

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token')->plainTextToken])
            ->postJson('/api/v1/clients', [
                'agency_public_id' => $agency['public_id'],
                'profile_photo_document_public_id' => $profilePhotoPublicId,
                'sector_public_id' => $classification['sector_public_id'],
                'sub_sector_public_id' => $classification['sub_sector_public_id'],
                'first_name' => 'Alice',
                'last_name' => 'Ngono',
                'father_name' => 'Joseph Ngono',
                'mother_name' => 'Marie Ngono',
                'phone_number' => '+237600000111',
                'home_phone_number' => '+237699000111',
                'business_started_on' => '2020-01-15',
                'business_activity_started_on' => '2020-02-01',
                'business_address_line_1' => 'Market stall 12',
                'business_address_line_2' => 'Central Market',
                'business_city' => 'Yaounde',
                'business_region' => 'Center',
                'status' => Client::STATUS_ACTIVE,
            ]);

        $this->assertJsonSuccess($create, 201);
        $clientPublicId = $this->requireStringJsonPath($create, 'data.public_id');
        $create->assertJsonPath('data.first_name', 'Alice');
        $create->assertJsonPath('data.profile_photo_document_public_id', $profilePhotoPublicId);
        $create->assertJsonPath('data.sector_public_id', $classification['sector_public_id']);
        $create->assertJsonPath('data.sub_sector_public_id', $classification['sub_sector_public_id']);
        $create->assertJsonPath('data.father_name', 'Joseph Ngono');
        $create->assertJsonPath('data.home_phone_number', '+237699000111');
        $create->assertJsonPath('data.business_started_on', '2020-01-15T00:00:00+00:00');
        $create->assertJsonPath('data.business_address_line_1', 'Market stall 12');
        $create->assertJsonPath('data.client_reference', fn (mixed $value) => is_string($value) && str_starts_with($value, 'CLI'));
        $create->assertJsonMissing(['path' => 'external.jpg']);
        self::assertSame(0, DB::table('journal_entries')->count());

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-2')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId, [
                'occupation' => 'Trader',
                'sector_public_id' => $classification['sector_public_id'],
                'father_name' => 'Jean Ngono',
                'home_phone_number' => null,
                'business_address_line_1' => 'Market stall 14',
                'collection_type' => 'field',
                'collection_frequency' => 'weekly',
                'collection_target_amount' => '15000.50',
            ]);

        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.father_name', 'Jean Ngono');
        $update->assertJsonPath('data.home_phone_number', null);
        $update->assertJsonPath('data.business_address_line_1', 'Market stall 14');
        $update->assertJsonPath('data.collection_target_amount', '15000.50');
        $update->assertJsonPath('data.sector_public_id', $classification['sector_public_id']);
        $update->assertJsonPath('data.sub_sector_public_id', null);

        $show = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-3')->plainTextToken])
            ->getJson('/api/v1/clients/'.$clientPublicId);

        $this->assertJsonSuccess($show);
        $show->assertJsonPath('data.first_name', 'Alice');
        $show->assertJsonPath('data.last_name', 'Ngono');

        $client = Client::query()
            ->where('public_id', $clientPublicId)
            ->with(['agency', 'profilePhotoDocument'])
            ->firstOrFail();
        $maskedPayload = ClientResource::make($client)
            ->toArray(Request::create('/api/v1/clients/'.$clientPublicId));

        self::assertSame($profilePhotoPublicId, $maskedPayload['profile_photo_document_public_id']);
        self::assertSame('J*********', $maskedPayload['father_name']);
        self::assertNull($maskedPayload['home_phone_number']);
        self::assertNull($maskedPayload['business_address_line_1']);
    }

    public function test_client_sector_classification_requires_active_matching_references(): void
    {
        $agency = $this->createAgency('AG-CRM-SEC');
        $actor = $this->createUserWithRole('platform-admin');
        $trade = $this->createSectorAndSubSector('TRADE-X', 'RETAIL-X');
        $agriculture = $this->createSectorAndSubSector('AGRI-X', 'FARM-X');
        $inactive = $this->createSectorAndSubSector('OLD-X', 'OLD-SUB-X', subSectorStatus: 'inactive');

        $mismatched = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('sector-mismatch')->plainTextToken])
            ->postJson('/api/v1/clients', [
                'agency_public_id' => $agency['public_id'],
                'sector_public_id' => $trade['sector_public_id'],
                'sub_sector_public_id' => $agriculture['sub_sector_public_id'],
                'first_name' => 'Sector',
                'last_name' => 'Mismatch',
            ]);

        $mismatched->assertStatus(422);
        $mismatched->assertJsonValidationErrors(['sub_sector_public_id']);

        $inactiveSubSector = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('sector-inactive')->plainTextToken])
            ->postJson('/api/v1/clients', [
                'agency_public_id' => $agency['public_id'],
                'sub_sector_public_id' => $inactive['sub_sector_public_id'],
                'first_name' => 'Inactive',
                'last_name' => 'SubSector',
            ]);

        $inactiveSubSector->assertStatus(422);
        $inactiveSubSector->assertJsonValidationErrors(['sub_sector_public_id']);
    }

    public function test_client_profile_photo_must_be_same_agency_profile_photo_document(): void
    {
        Storage::fake('local');

        $agencyA = $this->createAgency('AG-CRM-P1');
        $agencyB = $this->createAgency('AG-CRM-P2');
        $actor = $this->createUserWithRole('platform-admin');
        $crossAgencyPhoto = $this->createDocumentInAgency($agencyB['id'], 'profile_photo');
        $wrongCategoryDocument = $this->createDocumentInAgency($agencyA['id'], 'identity');

        $crossAgencyResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('photo-cross')->plainTextToken])
            ->postJson('/api/v1/clients', [
                'agency_public_id' => $agencyA['public_id'],
                'profile_photo_document_public_id' => $crossAgencyPhoto,
                'first_name' => 'Photo',
                'last_name' => 'Cross',
            ]);

        $crossAgencyResponse->assertStatus(422);
        $crossAgencyResponse->assertJsonValidationErrors(['profile_photo_document_public_id']);

        $wrongCategoryResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('photo-category')->plainTextToken])
            ->postJson('/api/v1/clients', [
                'agency_public_id' => $agencyA['public_id'],
                'profile_photo_document_public_id' => $wrongCategoryDocument,
                'first_name' => 'Photo',
                'last_name' => 'Category',
            ]);

        $wrongCategoryResponse->assertStatus(422);
        $wrongCategoryResponse->assertJsonValidationErrors(['profile_photo_document_public_id']);
    }

    public function test_agency_manager_cannot_create_client_in_other_agency(): void
    {
        $agencyA = $this->createAgency('AG-CRM-2');
        $agencyB = $this->createAgency('AG-CRM-3');
        $actor = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);

        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token')->plainTextToken])
            ->postJson('/api/v1/clients', [
                'agency_public_id' => $agencyB['public_id'],
                'first_name' => 'Marie',
                'last_name' => 'Test',
            ]);

        $response->assertForbidden();
    }

    public function test_client_profile_rejects_html_payloads_on_create_and_update(): void
    {
        $agency = $this->createAgency('AG-CRM-XSS');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('xss-v1')->plainTextToken])
            ->postJson('/api/v1/clients', [
                'first_name' => '<script>alert(1)</script>',
                'last_name' => 'Test',
            ]);

        $create->assertStatus(422);
        $create->assertJsonValidationErrors(['first_name']);

        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('xss-v2')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId, [
                'last_name' => '<img src=x onerror=alert(1)>',
            ]);

        $update->assertStatus(422);
        $update->assertJsonValidationErrors(['last_name']);
    }

    public function test_kyc_verification_requires_verified_identity_document(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-CRM-4');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);

        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $client = Client::query()->where('public_id', $clientPublicId)->firstOrFail();

        $submit = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-v0')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'submit',
            ]);
        $this->assertJsonSuccess($submit);

        $verify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-v1')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'verify',
            ]);

        $this->assertJsonError($verify, 422, 'Client must have at least one active verified identity document before KYC verification.');

        $upload = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-v2')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->image('id.jpg'),
                'category' => 'identity',
                'title' => 'Identity scan',
            ]);

        $this->assertJsonSuccess($upload, 201);
        $documentPublicId = $this->requireStringJsonPath($upload, 'data.public_id');

        $identityCreate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-v3')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'CMR12345',
                'issued_on' => '2020-01-01',
                'expires_on' => now()->addYear()->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);

        $this->assertJsonSuccess($identityCreate, 201);
        $identityPublicId = $this->requireStringJsonPath($identityCreate, 'data.public_id');

        $selfVerify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-v4')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
                'allow_self_verify' => true,
            ]);

        $selfVerify->assertStatus(422);
        $selfVerify->assertJsonValidationErrors(['allow_self_verify']);

        DB::table('client_identity_documents')
            ->where('public_id', $identityPublicId)
            ->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
            ]);

        $reviewer = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $verifyNow = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'verify',
            ]);

        $this->assertJsonSuccess($verifyNow);
        $verifyNow->assertJsonPath('data.kyc_status', Client::KYC_STATUS_VERIFIED);

        $this->assertDatabaseHas('client_kyc_reviews', [
            'client_id' => $client->id,
            'new_kyc_status' => Client::KYC_STATUS_VERIFIED,
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'kyc_submitted_by_user_id' => $actor->id,
            'kyc_verified_by_user_id' => $reviewer->id,
        ]);
    }

    public function test_configurable_maker_checker_kyc_segregation(): void
    {
        Storage::fake('local');
        config(['security.crm.kyc.enforce_maker_checker' => true]);

        $agency = $this->createAgency('AG-CRM-MC');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $client = Client::query()->where('public_id', $clientPublicId)->firstOrFail();
        $this->attachVerifiedIdentity($actor, $clientPublicId);

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('maker-submit')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'submit',
            ])
            ->assertOk();

        $selfVerify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('maker-self')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'verify',
            ]);

        $selfVerify->assertForbidden();
        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'kyc_status' => Client::KYC_STATUS_PENDING_REVIEW,
            'kyc_submitted_by_user_id' => $actor->id,
            'kyc_verified_by_user_id' => null,
        ]);

        $selfVerifyWithoutPermission = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('maker-self-override-denied')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'verify',
                'allow_self_verify' => true,
            ]);

        $selfVerifyWithoutPermission->assertForbidden();

        $overrideReviewer = $this->createUserWithRole('platform-admin');
        DB::table('clients')
            ->where('id', $client->id)
            ->update(['kyc_submitted_by_user_id' => $overrideReviewer->id]);

        $selfVerifyWithPermission = $this->withApiHeaders(['Authorization' => 'Bearer '.$overrideReviewer->createToken('maker-self-override-allowed')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'verify',
                'allow_self_verify' => true,
                'reason' => 'Branch manager approved emergency KYC recovery.',
            ]);

        $this->assertJsonSuccess($selfVerifyWithPermission);
        $selfVerifyWithPermission->assertJsonPath('data.kyc_status', Client::KYC_STATUS_VERIFIED);

        $overrideAudit = DB::table('activity_log')
            ->where('event', 'crm.client.kyc_status_changed')
            ->latest('id')
            ->first(['properties']);
        self::assertIsObject($overrideAudit);
        $overrideProperties = json_decode((string) $overrideAudit->properties, true);
        self::assertIsArray($overrideProperties);
        self::assertSame(true, $overrideProperties['maker_checker_override_used'] ?? null);
        self::assertSame('client_kyc', $overrideProperties['override_surface'] ?? null);
        self::assertSame('Branch manager approved emergency KYC recovery.', $overrideProperties['override_reason'] ?? null);

        config(['security.crm.kyc.enforce_maker_checker' => false]);

        $secondClientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $secondClient = Client::query()->where('public_id', $secondClientPublicId)->firstOrFail();
        $this->attachVerifiedIdentity($actor, $secondClientPublicId, 'MC-DISABLED');

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('maker-disabled-submit')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$secondClientPublicId.'/kyc-status', [
                'action' => 'submit',
            ])
            ->assertOk();

        $disabledVerify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('maker-disabled-verify')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$secondClientPublicId.'/kyc-status', [
                'action' => 'verify',
            ]);

        $this->assertJsonSuccess($disabledVerify);
        $this->assertDatabaseHas('clients', [
            'id' => $secondClient->id,
            'kyc_status' => Client::KYC_STATUS_VERIFIED,
            'kyc_submitted_by_user_id' => $actor->id,
            'kyc_verified_by_user_id' => $actor->id,
        ]);
    }

    public function test_identity_document_linking_and_duplicate_number_are_hardened(): void
    {
        Storage::fake('local');

        $agencyA = $this->createAgency('AG-CRM-5');
        $agencyB = $this->createAgency('AG-CRM-6');
        $actorA = $this->createUserWithRole('kyc-officer', $agencyA['code'], $agencyA['name']);
        $actorB = $this->createUserWithRole('kyc-officer', $agencyB['code'], $agencyB['name']);

        $clientA1PublicId = $this->createClientViaApi($actorA, $agencyA['public_id']);
        $clientA2PublicId = $this->createClientViaApi($actorA, $agencyA['public_id']);
        $clientBPublicId = (string) Str::ulid();
        Client::query()->create([
            'public_id' => $clientBPublicId,
            'agency_id' => $agencyB['id'],
            'client_reference' => 'CLI-B-'.$agencyB['id'],
            'first_name' => 'Other',
            'last_name' => 'Agency',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_DRAFT,
        ]);

        $documentA = $this->createDocumentViaApi($actorA);
        $documentASecond = $this->createDocumentViaApi($actorA);
        $documentB = $this->createDocumentInAgency($agencyB['id']);

        $first = $this->withApiHeaders(['Authorization' => 'Bearer '.$actorA->createToken('token-i1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientA1PublicId.'/identity-documents', [
                'document_type' => 'passport',
                'document_number' => 'ABC-123',
                'document_public_id' => $documentA,
            ]);
        $this->assertJsonSuccess($first, 201);

        $duplicate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actorA->createToken('token-i2')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientA2PublicId.'/identity-documents', [
                'document_type' => 'passport',
                'document_number' => 'ABC123',
                'document_public_id' => $documentASecond,
            ]);
        $this->assertJsonError($duplicate, 422, 'Identity document already exists or conflicts with an existing record.');

        $crossAgency = $this->withApiHeaders(['Authorization' => 'Bearer '.$actorA->createToken('token-i3')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientA2PublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'XYZ-999',
                'document_public_id' => $documentB,
            ]);
        $this->assertJsonError($crossAgency, 422, 'Document attachment is invalid for this client.');

        self::assertFalse((new ClientPolicy)->view(
            $actorA,
            Client::query()->where('public_id', $clientBPublicId)->firstOrFail(),
        ));
    }

    public function test_identity_document_numbers_are_masked_without_pii_permission(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-CRM-PII');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $auditor = $this->createUserWithRole('auditor');
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $documentPublicId = $this->createDocumentViaApi($actor);

        $identity = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('pii-v1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'passport',
                'document_number' => 'AB123456',
                'document_public_id' => $documentPublicId,
            ]);
        $this->assertJsonSuccess($identity, 201);
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.public_id');

        $storedIdentity = DB::table('client_identity_documents')
            ->where('public_id', $identityPublicId)
            ->first(['document_number', 'document_number_hash']);
        self::assertIsObject($storedIdentity);
        self::assertNotSame('AB123456', $storedIdentity->document_number);
        self::assertSame(
            ClientIdentityDocument::documentNumberHash('AB123456'),
            $storedIdentity->document_number_hash
        );

        $piiResponse = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('pii-v2')->plainTextToken])
            ->getJson('/api/v1/clients/'.$clientPublicId.'/identity-documents');
        $this->assertJsonSuccess($piiResponse);
        $piiResponse->assertJsonPath('data.identity_documents.0.document_number', 'AB123456');

        $maskedResponse = $this->withApiHeaders()
            ->actingAsSanctum($auditor)
            ->getJson('/api/v1/clients/'.$clientPublicId.'/identity-documents');
        $this->assertJsonSuccess($maskedResponse);
        $maskedResponse->assertJsonPath('data.identity_documents.0.document_number', '****3456');
    }

    public function test_guarantor_and_proxy_security_constraints_are_enforced(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-CRM-7');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);

        $selfGuarantor = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-g1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/guarantors', [
                'guarantor_client_public_id' => $clientPublicId,
            ]);

        $this->assertJsonError($selfGuarantor, 422, 'Guarantor client must be in the same agency and cannot match the client.');

        $proxy = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-g2')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Proxy Name',
                'mandate_type' => 'full',
                'starts_on' => now()->subDays(10)->toDateString(),
                'ends_on' => now()->subDay()->toDateString(),
            ]);

        $this->assertJsonSuccess($proxy, 201);
        $proxy->assertJsonPath('data.status', 'expired');
        $proxyPublicId = $this->requireStringJsonPath($proxy, 'data.public_id');

        $expire = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-g3')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$proxyPublicId.'/status', [
                'action' => 'expire',
            ]);

        $this->assertJsonSuccess($expire);
        $expire->assertJsonPath('data.status', 'expired');
    }

    public function test_self_verify_override_flags_do_not_bypass_verification_controls(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-CRM-8');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);

        $identityDocumentPublicId = $this->createDocumentViaApi($actor);
        $identity = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('self-v1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'SELFVERIFY-1',
                'document_public_id' => $identityDocumentPublicId,
            ]);
        $this->assertJsonSuccess($identity, 201);
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.public_id');

        $identityVerify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('self-v2')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
                'allow_self_verify' => true,
            ]);
        $identityVerify->assertStatus(422);
        $identityVerify->assertJsonValidationErrors(['allow_self_verify']);

        $guarantorDocumentPublicId = $this->createDocumentViaApi($actor);
        $guarantor = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('self-v3')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/guarantors', [
                'guarantor_full_name' => 'External Guarantor',
                'document_public_id' => $guarantorDocumentPublicId,
            ]);
        $this->assertJsonSuccess($guarantor, 201);
        $guarantorPublicId = $this->requireStringJsonPath($guarantor, 'data.public_id');

        $guarantorVerify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('self-v4')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/guarantors/'.$guarantorPublicId.'/status', [
                'action' => 'verify',
                'allow_self_verify' => true,
            ]);
        $guarantorVerify->assertStatus(422);
        $guarantorVerify->assertJsonValidationErrors(['allow_self_verify']);

        $proxyDocumentPublicId = $this->createDocumentViaApi($actor);
        $proxy = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('self-v5')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'External Proxy',
                'mandate_type' => 'full',
                'document_public_id' => $proxyDocumentPublicId,
            ]);
        $this->assertJsonSuccess($proxy, 201);
        $proxyPublicId = $this->requireStringJsonPath($proxy, 'data.public_id');

        $proxyVerify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('self-v6')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$proxyPublicId.'/status', [
                'action' => 'verify',
                'allow_self_verify' => true,
            ]);
        $proxyVerify->assertStatus(422);
        $proxyVerify->assertJsonValidationErrors(['allow_self_verify']);

        $overrideActor = $this->createUserWithRole('platform-admin');
        $overrideDocumentPublicId = $this->createDocumentViaApi($overrideActor);
        $overrideIdentity = $this->withApiHeaders()
            ->actingAsSanctum($overrideActor)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'SELFVERIFY-OVERRIDE-1',
                'document_public_id' => $overrideDocumentPublicId,
            ]);
        $this->assertJsonSuccess($overrideIdentity, 201);
        $overrideIdentityPublicId = $this->requireStringJsonPath($overrideIdentity, 'data.public_id');

        $identityOverride = $this->withApiHeaders()
            ->actingAsSanctum($overrideActor)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$overrideIdentityPublicId.'/status', [
                'action' => 'verify',
                'allow_self_verify' => true,
                'reason' => 'Compliance-approved exception for unavailable checker.',
            ]);
        $this->assertJsonSuccess($identityOverride);

        $overrideAudit = DB::table('activity_log')
            ->where('event', 'crm.identity_document.status_changed')
            ->latest('id')
            ->first(['properties']);
        self::assertIsObject($overrideAudit);
        $overrideProperties = json_decode((string) $overrideAudit->properties, true);
        self::assertIsArray($overrideProperties);
        self::assertSame(true, $overrideProperties['self_verification_override_used'] ?? null);
        self::assertSame('document_kyc', $overrideProperties['override_surface'] ?? null);
        self::assertSame('Compliance-approved exception for unavailable checker.', $overrideProperties['override_reason'] ?? null);
    }

    public function test_expired_identity_override_requires_explicit_permission(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-CRM-9');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $client = Client::query()->where('public_id', $clientPublicId)->firstOrFail();

        $documentPublicId = $this->createDocumentViaApi($actor);
        $identity = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('expired-v1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'passport',
                'document_number' => 'EXPIRED-1',
                'issued_on' => '2020-01-01',
                'expires_on' => now()->addDay()->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);
        $this->assertJsonSuccess($identity, 201);
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.public_id');

        DB::table('client_identity_documents')
            ->where('public_id', $identityPublicId)
            ->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
            ]);

        DB::table('client_identity_documents')
            ->where('public_id', $identityPublicId)
            ->update(['expires_on' => now()->subDay()->toDateString()]);

        $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('expired-v3')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'submit',
            ])
            ->assertOk();

        $forbidden = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('expired-v4')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'verify',
                'force_override_expired_identity' => true,
            ]);
        $forbidden->assertForbidden();

        $overrideReviewer = $this->createUserWithRole('platform-admin');

        $allowed = $this->withApiHeaders()
            ->actingAsSanctum($overrideReviewer)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'verify',
                'force_override_expired_identity' => true,
            ]);
        $this->assertJsonSuccess($allowed);
        $allowed->assertJsonPath('data.kyc_status', Client::KYC_STATUS_VERIFIED);

        $this->assertDatabaseHas('client_kyc_reviews', [
            'client_id' => $client->id,
            'new_kyc_status' => Client::KYC_STATUS_VERIFIED,
        ]);
    }

    public function test_institution_read_scope_does_not_imply_mutation_scope(): void
    {
        $agency = $this->createAgency('AG-CRM-10');
        $agencyActor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $compliance = $this->createUserWithRole('compliance-officer');
        $readOnlyReviewer = $this->createUserWithRole('staff', 'AG-CRM-10B', 'AG-CRM-10B Agency');
        $readOnlyReviewer->givePermissionTo([
            'crm.clients.view',
            'crm.reviews.view',
            'crm.scope.institution.read',
            'crm.kyc.reject',
        ]);
        $client = Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_reference' => 'CLI-SCOPE-'.$agency['id'],
            'first_name' => 'Scoped',
            'last_name' => 'Client',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_PENDING_REVIEW,
        ]);

        $policy = new ClientPolicy;

        $platformAdmin = $this->createUserWithRole('platform-admin');
        self::assertTrue($policy->viewAny($platformAdmin));
        self::assertTrue($policy->create($platformAdmin));
        self::assertTrue($policy->rejectKyc($compliance, $client));
        self::assertFalse($policy->rejectKyc($readOnlyReviewer, $client));
        self::assertFalse($policy->update($readOnlyReviewer, $client));
        self::assertTrue($policy->view($readOnlyReviewer, $client));
    }

    public function test_non_kyc_documents_cannot_be_linked_to_guarantors_or_proxies(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-CRM-11');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $invalidDocumentPublicId = $this->createDocumentViaApi($actor, 'profile_photo');

        $guarantor = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('doc-v1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/guarantors', [
                'guarantor_full_name' => 'Bad Link',
                'document_public_id' => $invalidDocumentPublicId,
            ]);
        $this->assertJsonError($guarantor, 422, 'Document attachment is invalid for this client.');

        $proxy = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('doc-v2')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Bad Proxy',
                'mandate_type' => 'full',
                'document_public_id' => $invalidDocumentPublicId,
            ]);
        $this->assertJsonError($proxy, 422, 'Document attachment is invalid for this client.');
    }

    public function test_metadata_only_kyc_evidence_cannot_be_verified(): void
    {
        $agency = $this->createAgency('AG-CRM-12');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $reviewer = $this->createUserWithRole('platform-admin');
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);

        $identity = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('metadata-v1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'METADATA-ONLY-1',
            ]);
        $this->assertJsonSuccess($identity, 201);
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.public_id');

        $identitySelfVerify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('metadata-v2')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
            ]);
        $identitySelfVerify->assertForbidden();

        $identityReviewerVerify = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
            ]);
        $this->assertJsonError($identityReviewerVerify, 422, 'Identity document verification requires linked KYC document evidence.');

        $guarantor = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('metadata-v3')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/guarantors', [
                'guarantor_full_name' => 'Metadata Guarantor',
            ]);
        $this->assertJsonSuccess($guarantor, 201);
        $guarantorPublicId = $this->requireStringJsonPath($guarantor, 'data.public_id');

        $guarantorVerify = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/guarantors/'.$guarantorPublicId.'/status', [
                'action' => 'verify',
            ]);
        $this->assertJsonError($guarantorVerify, 422, 'Guarantor verification requires linked KYC document evidence.');

        $proxy = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('metadata-v4')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Metadata Proxy',
                'mandate_type' => 'full',
            ]);
        $this->assertJsonSuccess($proxy, 201);
        $proxyPublicId = $this->requireStringJsonPath($proxy, 'data.public_id');

        $proxyVerify = $this->withApiHeaders()
            ->actingAsSanctum($reviewer)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$proxyPublicId.'/status', [
                'action' => 'verify',
            ]);
        $this->assertJsonError($proxyVerify, 422, 'Proxy verification requires linked KYC document evidence.');
    }

    public function test_proxy_status_is_derived_from_date_range(): void
    {
        $agency = $this->createAgency('AG-CRM-13');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);

        $futureProxy = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('proxy-v1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Future Proxy',
                'mandate_type' => 'limited',
                'starts_on' => now()->addDays(5)->toDateString(),
            ]);
        $this->assertJsonSuccess($futureProxy, 201);
        $futureProxy->assertJsonPath('data.status', 'inactive');

        $activeProxy = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('proxy-v2')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Active Proxy',
                'mandate_type' => 'limited',
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => now()->addDays(5)->toDateString(),
            ]);
        $this->assertJsonSuccess($activeProxy, 201);
        $activeProxyPublicId = $this->requireStringJsonPath($activeProxy, 'data.public_id');

        $updated = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('proxy-v3')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$activeProxyPublicId, [
                'starts_on' => now()->addDays(3)->toDateString(),
            ]);
        $this->assertJsonSuccess($updated);
        $updated->assertJsonPath('data.status', 'inactive');

        $reactivated = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('proxy-v4')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$activeProxyPublicId, [
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => now()->addDays(3)->toDateString(),
            ]);
        $this->assertJsonSuccess($reactivated);
        $reactivated->assertJsonPath('data.status', 'active');
    }

    public function test_proxy_mandate_can_be_scoped_to_account_operations_limits_and_dates(): void
    {
        $agency = $this->createAgency('AG-CRM-14');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $client = Client::query()->where('public_id', $clientPublicId)->firstOrFail();
        $customerAccount = $this->createCustomerAccount($client);
        $otherClientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $otherClient = Client::query()->where('public_id', $otherClientPublicId)->firstOrFail();
        $otherCustomerAccount = $this->createCustomerAccount($otherClient);

        $crossAccount = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Cross Account Proxy',
                'mandate_type' => 'limited',
                'customer_account_public_id' => $otherCustomerAccount->public_id,
                'operation_types' => ['withdrawal'],
                'max_amount_minor' => 50000,
                'limit_currency' => 'XAF',
            ]);
        $this->assertJsonError($crossAccount, 422, 'Customer account mandate scope is invalid for this client.');

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Account Proxy',
                'mandate_type' => 'limited',
                'customer_account_public_id' => $customerAccount->public_id,
                'operation_types' => ['withdrawal', 'statement_request'],
                'max_amount_minor' => 50000,
                'limit_currency' => 'XAF',
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => now()->addDays(5)->toDateString(),
            ]);

        $this->assertJsonSuccess($created, 201);
        $created->assertJsonPath('data.customer_account_public_id', $customerAccount->public_id);
        $created->assertJsonPath('data.operation_types.0', 'withdrawal');
        $created->assertJsonPath('data.max_amount_minor', 50000);
        $proxyPublicId = $this->requireStringJsonPath($created, 'data.public_id');

        $proxy = ClientProxy::query()->where('public_id', $proxyPublicId)->firstOrFail();
        $proxy->forceFill([
            'verification_status' => ClientProxy::VERIFICATION_VERIFIED,
            'verified_at' => now(),
            'verified_by_user_id' => $actor->id,
        ])->save();

        $authorizer = app(ClientProxyMandateAuthorizer::class);
        self::assertTrue($authorizer->allows($proxy->refresh(), $customerAccount, 'withdrawal', 50000, 'XAF'));
        self::assertFalse($authorizer->allows($proxy->refresh(), $customerAccount, 'transfer', 1000, 'XAF'));
        self::assertFalse($authorizer->allows($proxy->refresh(), $customerAccount, 'withdrawal', 50001, 'XAF'));
        self::assertFalse($authorizer->allows($proxy->refresh(), $otherCustomerAccount, 'withdrawal', 1000, 'XAF'));

        $deactivated = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$proxyPublicId.'/status', [
                'action' => 'deactivate',
            ]);
        $this->assertJsonSuccess($deactivated);
        self::assertFalse($authorizer->allows($proxy->refresh(), $customerAccount, 'withdrawal', 1000, 'XAF'));

        $expired = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$proxyPublicId, [
                'ends_on' => now()->subDay()->toDateString(),
            ]);
        $this->assertJsonSuccess($expired);
        $expired->assertJsonPath('data.status', ClientProxy::STATUS_EXPIRED);
    }

    public function test_collection_metadata_is_descriptive_and_does_not_create_cash_or_repayment_facts(): void
    {
        $agency = $this->createAgency('AG-CRM-15');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $collector = $this->createUserWithRole('teller', $agency['code'], $agency['name']);

        $created = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/clients', [
                'first_name' => 'Collection',
                'last_name' => 'Metadata',
                'phone_number' => '+237699991515',
                'collection_agent_public_id' => $collector->public_id,
                'collection_type' => 'field',
                'collection_frequency' => 'weekly',
                'collection_target_amount' => '25000.00',
            ]);

        $this->assertJsonSuccess($created, 201);
        $created->assertJsonPath('data.collection_agent_public_id', $collector->public_id);
        $created->assertJsonPath('data.collection_type', 'field');
        $created->assertJsonPath('data.collection_frequency', 'weekly');
        $created->assertJsonPath('data.collection_target_amount', '25000.00');

        $client = Client::query()
            ->where('public_id', $this->requireStringJsonPath($created, 'data.public_id'))
            ->firstOrFail();
        $profile = app(ClientCollectionProfile::class)->forClient($client);

        self::assertSame($client->id, $profile['client_id']);
        self::assertSame($agency['id'], $profile['agency_id']);
        self::assertSame($collector->id, $profile['collection_agent_id']);
        self::assertSame('field', $profile['collection_type']);
        self::assertSame('weekly', $profile['collection_frequency']);
        self::assertSame('25000.00', $profile['collection_target_amount']);

        $this->assertDatabaseMissing('teller_transactions', [
            'agency_id' => $agency['id'],
            'client_id' => $client->id,
        ]);
        $this->assertDatabaseMissing('loan_repayments', [
            'agency_id' => $agency['id'],
        ]);
        $this->assertDatabaseMissing('journal_entries', [
            'agency_id' => $agency['id'],
            'source_module' => 'collection',
        ]);
    }

    private function createClientViaApi(User $actor, string $agencyPublicId): string
    {
        $payload = [
            'first_name' => 'Jean',
            'last_name' => 'Client',
            'phone_number' => '+2376'.random_int(10000000, 99999999),
        ];

        if ($actor->hasRole('platform-admin')) {
            $payload['agency_public_id'] = $agencyPublicId;
        }

        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('client-api')->plainTextToken])
            ->postJson('/api/v1/clients', $payload);

        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function createDocumentViaApi(User $actor, string $category = 'identity'): string
    {
        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('doc-api')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->image('id.jpg'),
                'category' => $category,
                'title' => 'Document',
            ]);

        $this->assertJsonSuccess($response, 201);

        return $this->requireStringJsonPath($response, 'data.public_id');
    }

    private function attachVerifiedIdentity(User $actor, string $clientPublicId, string $documentNumber = 'MC-IDENTITY'): string
    {
        $documentPublicId = $this->createDocumentViaApi($actor);

        $response = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('identity-api')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => $documentNumber,
                'issued_on' => '2020-01-01',
                'expires_on' => now()->addYear()->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);

        $this->assertJsonSuccess($response, 201);
        $identityPublicId = $this->requireStringJsonPath($response, 'data.public_id');

        DB::table('client_identity_documents')
            ->where('public_id', $identityPublicId)
            ->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
                'verified_by_user_id' => $actor->id,
            ]);

        return $identityPublicId;
    }

    private function createDocumentInAgency(int $agencyId, string $category = 'identity'): string
    {
        $document = Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'category' => $category,
            'title' => 'External Document',
            'status' => Document::STATUS_ACTIVE,
        ]);

        $document->addMediaFromString('test-image-content')
            ->usingFileName('external.jpg')
            ->toMediaCollection('kyc_documents');

        return $document->public_id;
    }

    private function createCustomerAccount(Client $client): CustomerAccount
    {
        $ledgerAccountId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $client->agency_id,
            'code' => 'CRM-PROXY-'.Str::upper(Str::random(8)),
            'name' => 'CRM Proxy Ledger',
            'account_class' => 'liability',
            'normal_balance_side' => 'credit',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $account = CustomerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'agency_id' => $client->agency_id,
            'ledger_account_id' => $ledgerAccountId,
            'account_number' => 'CRM-PROXY-'.Str::upper(Str::random(10)),
            'account_type' => 'ordinary_savings',
            'currency' => 'XAF',
            'opened_on' => now()->toDateString(),
            'status' => CustomerAccount::STATUS_ACTIVE,
        ]);

        return $account->refresh();
    }

    /**
     * @return array{sector_id:int, sub_sector_id:int, sector_public_id:string, sub_sector_public_id:string}
     */
    private function createSectorAndSubSector(string $sectorCode, string $subSectorCode, string $sectorStatus = 'active', string $subSectorStatus = 'active'): array
    {
        $sectorId = DB::table('sectors')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $sectorCode,
            'name' => $sectorCode.' Sector',
            'status' => $sectorStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subSectorId = DB::table('sub_sectors')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'sector_id' => $sectorId,
            'code' => $subSectorCode,
            'name' => $subSectorCode.' Sub-sector',
            'status' => $subSectorStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sector = DB::table('sectors')->where('id', $sectorId)->first(['public_id']);
        $subSector = DB::table('sub_sectors')->where('id', $subSectorId)->first(['public_id']);

        self::assertIsObject($sector);
        self::assertIsObject($subSector);
        self::assertIsString($sector->public_id);
        self::assertIsString($subSector->public_id);

        return [
            'sector_id' => $sectorId,
            'sub_sector_id' => $subSectorId,
            'sector_public_id' => $sector->public_id,
            'sub_sector_public_id' => $subSector->public_id,
        ];
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
            'status' => Agency::STATUS_ACTIVE,
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

    private function requireStringJsonPath(mixed $response, string $path): string
    {
        $value = $response instanceof TestResponse ? $response->json($path) : null;
        self::assertIsString($value);

        return $value;
    }
}
