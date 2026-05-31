<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Resources\ClientResource;
use App\Models\Agency;
use App\Models\Client;
use App\Models\ClientGuarantor;
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

    public function test_platform_admin_can_verify_kyc_submitted_by_another_user_and_self_verify_is_explained(): void
    {
        Storage::fake('local');
        config(['security.crm.kyc.enforce_maker_checker' => true]);

        $agency = $this->createAgency('AG-KYC-PA');
        $submitter = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($submitter, $agency['public_id']);
        $this->attachVerifiedIdentity($submitter, $clientPublicId);

        $this->withApiHeaders()->actingAsSanctum($submitter)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', ['action' => 'submit'])
            ->assertOk();

        // The submitter cannot self-verify without the override; the error now
        // explains the override path instead of a bare 403.
        $selfVerify = $this->withApiHeaders()->actingAsSanctum($submitter)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', ['action' => 'verify']);
        $selfVerify->assertForbidden();
        $selfVerify->assertJsonPath('success', false);
        $message = $selfVerify->json('message');
        self::assertIsString($message);
        self::assertStringContainsString('allow_self_verify', $message);

        // A platform-admin (a different user with the permission) verifies the
        // submitted KYC successfully — the role's permission is sufficient when
        // it is not self-verification.
        $platformAdmin = $this->createUserWithRole('platform-admin');
        $verify = $this->withApiHeaders()->actingAsSanctum($platformAdmin)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', ['action' => 'verify']);
        $this->assertJsonSuccess($verify);
        $verify->assertJsonPath('data.kyc_status', Client::KYC_STATUS_VERIFIED);
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

    public function test_proxy_creation_stores_encrypted_id_document_number_without_overflow(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-CRM-PROXYENC');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);

        $documentNumber = 'CMR-NID-2026-0099887766';

        $proxy = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('proxy-enc')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Encrypted Proxy',
                'mandate_type' => 'full',
                'proxy_id_document_type' => 'national_id',
                'proxy_id_document_number' => $documentNumber,
            ]);

        $this->assertJsonSuccess($proxy, 201);
        $proxyPublicId = $this->requireStringJsonPath($proxy, 'data.public_id');

        $stored = ClientProxy::query()->where('public_id', $proxyPublicId)->firstOrFail();

        // The encrypted payload on disk must exceed the original varchar(128)
        // limit; if the column were still varchar(128) this insert would have
        // overflowed and the request would have returned 500.
        $rawCiphertext = DB::table('client_proxies')
            ->where('public_id', $proxyPublicId)
            ->value('proxy_id_document_number');
        self::assertIsString($rawCiphertext);
        self::assertGreaterThan(128, strlen($rawCiphertext));

        // The decrypted value round-trips to the original plaintext.
        self::assertSame($documentNumber, $stored->proxy_id_document_number);
    }

    public function test_platform_admin_must_select_agency_when_uploading_documents(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-DOC-UP');
        $platformAdmin = $this->createUserWithRole('platform-admin');

        // Platform-admin has documents.create but no current agency: without a
        // selection the response is a structured 422, not an unexplained 403.
        $missingAgency = $this->withApiHeaders()
            ->actingAsSanctum($platformAdmin)
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->image('id.jpg'),
                'category' => 'identity',
                'title' => 'Doc',
            ]);
        $missingAgency->assertStatus(422);
        $missingAgency->assertJsonValidationErrors(['agency_public_id']);

        // With agency_public_id the upload succeeds and is stored in that agency.
        $withAgency = $this->withApiHeaders()
            ->actingAsSanctum($platformAdmin)
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->image('id.jpg'),
                'category' => 'identity',
                'title' => 'Doc',
                'agency_public_id' => $agency['public_id'],
            ]);
        $this->assertJsonSuccess($withAgency, 201);
        $documentPublicId = $this->requireStringJsonPath($withAgency, 'data.public_id');
        self::assertSame($agency['id'], Document::query()->where('public_id', $documentPublicId)->value('agency_id'));

        // An agency-scoped user cannot upload into a different agency.
        $otherAgency = $this->createAgency('AG-DOC-OTHER');
        $agencyUser = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $crossAgency = $this->withApiHeaders()
            ->actingAsSanctum($agencyUser)
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->image('id.jpg'),
                'category' => 'identity',
                'title' => 'Doc',
                'agency_public_id' => $otherAgency['public_id'],
            ]);
        $crossAgency->assertForbidden();
    }

    public function test_platform_admin_document_lifecycle_without_current_agency(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-DOC-LC');
        $platformAdmin = $this->createUserWithRole('platform-admin');

        // Upload for a selected agency (platform-admin has no current agency).
        $upload = $this->withApiHeaders()->actingAsSanctum($platformAdmin)
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->image('lc.jpg'),
                'category' => 'identity',
                'title' => 'Lifecycle Doc',
                'agency_public_id' => $agency['public_id'],
            ]);
        $this->assertJsonSuccess($upload, 201);
        $documentPublicId = $this->requireStringJsonPath($upload, 'data.public_id');

        // Metadata, file download, and archive all succeed for that same actor
        // (AIR-003) — not just the upload.
        $this->withApiHeaders()->actingAsSanctum($platformAdmin)
            ->getJson('/api/v1/documents/'.$documentPublicId)
            ->assertOk();

        $this->withApiHeaders()->actingAsSanctum($platformAdmin)
            ->getJson('/api/v1/documents/'.$documentPublicId.'/file')
            ->assertStatus(200);

        $this->withApiHeaders()->actingAsSanctum($platformAdmin)
            ->patchJson('/api/v1/documents/'.$documentPublicId.'/archive')
            ->assertOk();

        // A normal user from another agency still cannot read it.
        $otherAgency = $this->createAgency('AG-DOC-LC2');
        $foreignUser = $this->createUserWithRole('kyc-officer', $otherAgency['code'], $otherAgency['name']);
        $this->withApiHeaders()->actingAsSanctum($foreignUser)
            ->getJson('/api/v1/documents/'.$documentPublicId)
            ->assertForbidden();
    }

    public function test_uploaded_document_file_is_retrievable_and_agency_scoped(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-DOC-DL');

        // Unauthenticated requests are rejected (checked before any acting-as,
        // since Sanctum's test guard persists once set).
        $seedDocument = $this->createDocumentInAgency($agency['id'], 'profile_photo');
        $unauth = $this->withApiHeaders(['Authorization' => ''])
            ->getJson('/api/v1/documents/'.$seedDocument.'/file');
        $unauth->assertStatus(401);

        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);

        $upload = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/documents', [
                'file' => UploadedFile::fake()->image('photo.jpg'),
                'category' => 'profile_photo',
                'title' => 'Profile Photo',
            ]);
        $this->assertJsonSuccess($upload, 201);
        $documentPublicId = $this->requireStringJsonPath($upload, 'data.public_id');
        $expectedChecksum = Document::query()->where('public_id', $documentPublicId)->value('checksum_sha256');
        self::assertIsString($expectedChecksum);

        $download = $this->withApiHeaders()
            ->actingAsSanctum($actor)
            ->getJson('/api/v1/documents/'.$documentPublicId.'/file');
        $download->assertStatus(200);
        self::assertStringStartsWith('image/', (string) $download->headers->get('Content-Type'));
        self::assertStringContainsString('inline', (string) $download->headers->get('Content-Disposition'));

        // The streamed bytes match the checksum computed at upload time.
        ob_start();
        $download->baseResponse->sendContent();
        $bytes = (string) ob_get_clean();
        self::assertSame($expectedChecksum, hash('sha256', $bytes));

        // A user from another agency cannot retrieve the file.
        $otherAgency = $this->createAgency('AG-DOC-DL2');
        $foreignUser = $this->createUserWithRole('kyc-officer', $otherAgency['code'], $otherAgency['name']);
        $forbidden = $this->withApiHeaders()
            ->actingAsSanctum($foreignUser)
            ->getJson('/api/v1/documents/'.$documentPublicId.'/file');
        $forbidden->assertForbidden();
    }

    public function test_client_pii_masking_exposes_pii_redacted_flag(): void
    {
        $agency = $this->createAgency('AG-PIIFLAG');
        $client = Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_reference' => 'CLI-PIIFLAG',
            'first_name' => 'Mariama',
            'last_name' => 'Bello',
            'phone_number' => '+237699445566',
            'email' => 'mariama.bello@example.test',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_VERIFIED,
        ]);

        // agency-manager has crm.clients.view but not crm.pii.view: data is
        // masked and pii_redacted=true signals the masking to the frontend.
        $manager = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);
        $masked = $this->withApiHeaders()->actingAsSanctum($manager)
            ->getJson('/api/v1/clients/'.$client->public_id);
        $this->assertJsonSuccess($masked);
        $masked->assertJsonPath('data.pii_redacted', true);
        self::assertNotSame('Mariama', $masked->json('data.first_name'));

        // platform-admin has crm.pii.view: full data and pii_redacted=false.
        $admin = $this->createUserWithRole('platform-admin');
        $full = $this->withApiHeaders()->actingAsSanctum($admin)
            ->getJson('/api/v1/clients/'.$client->public_id);
        $this->assertJsonSuccess($full);
        $full->assertJsonPath('data.pii_redacted', false);
        $full->assertJsonPath('data.first_name', 'Mariama');
    }

    public function test_identity_back_face_evidence_uniqueness_is_enforced(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-FACE-UNIQ');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $token = 'Bearer '.$actor->createToken('face-uniq')->plainTextToken;

        $clientA = $this->createClientViaApi($actor, $agency['public_id']);
        $frontDoc = $this->createDocumentViaApi($actor);
        $backDoc = $this->createDocumentViaApi($actor);

        $identity = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/clients/'.$clientA.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'NID-UNIQ-1',
                'document_public_id' => $frontDoc,
                'back_document_public_id' => $backDoc,
            ]);
        $this->assertJsonSuccess($identity, 201);
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.public_id');

        // Re-saving the same record's own evidence is not a false positive.
        $resave = $this->withApiHeaders(['Authorization' => $token])
            ->patchJson('/api/v1/clients/'.$clientA.'/identity-documents/'.$identityPublicId, [
                'back_document_public_id' => $backDoc,
            ]);
        $this->assertJsonSuccess($resave);

        // Update rejects setting the back face equal to the front face.
        $sameAsFront = $this->withApiHeaders(['Authorization' => $token])
            ->patchJson('/api/v1/clients/'.$clientA.'/identity-documents/'.$identityPublicId, [
                'back_document_public_id' => $frontDoc,
            ]);
        $sameAsFront->assertStatus(422);
        $sameAsFront->assertJsonValidationErrors(['back_document_public_id']);

        // Front-only update also cannot point at the current back face.
        $frontToCurrentBack = $this->withApiHeaders(['Authorization' => $token])
            ->patchJson('/api/v1/clients/'.$clientA.'/identity-documents/'.$identityPublicId, [
                'document_public_id' => $backDoc,
            ]);
        $frontToCurrentBack->assertStatus(422);
        $frontToCurrentBack->assertJsonValidationErrors(['back_document_public_id']);

        // A document already used on one identity record cannot be reused on
        // another record for the same client.
        $sameClientReuse = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/clients/'.$clientA.'/identity-documents', [
                'document_type' => 'passport',
                'document_number' => 'PP-SAME-CLIENT-REUSE',
                'document_public_id' => $backDoc,
            ]);
        $this->assertJsonError($sameClientReuse, 422, 'Document attachment is invalid for this client.');

        // A document already used as another client's back face cannot be reused.
        $clientB = $this->createClientViaApi($actor, $agency['public_id']);
        $reuse = $this->withApiHeaders(['Authorization' => $token])
            ->postJson('/api/v1/clients/'.$clientB.'/identity-documents', [
                'document_type' => 'passport',
                'document_number' => 'PP-REUSE-1',
                'document_public_id' => $backDoc,
            ]);
        $this->assertJsonError($reuse, 422, 'Document attachment is invalid for this client.');
    }

    public function test_two_face_identity_document_cannot_be_verified_without_both_faces(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-FACES-1');
        $submitter = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($submitter, $agency['public_id']);
        $frontDoc = $this->createDocumentViaApi($submitter);
        $backDoc = $this->createDocumentViaApi($submitter);

        $identity = $this->withApiHeaders(['Authorization' => 'Bearer '.$submitter->createToken('faces-1')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'NID-FACE-1',
                'expires_on' => now()->addYear()->toDateString(),
                'document_public_id' => $frontDoc,
            ]);
        $this->assertJsonSuccess($identity, 201);
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.public_id');

        // A different officer attempts verification with only the front face.
        $verifier = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $blocked = $this->withApiHeaders()->actingAsSanctum($verifier)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
            ]);
        $blocked->assertStatus(422);
        $blocked->assertJsonValidationErrors(['back_document_public_id']);

        // Attach the back face, then verification succeeds.
        $this->withApiHeaders()->actingAsSanctum($verifier)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId, [
                'back_document_public_id' => $backDoc,
            ])->assertOk();

        $verified = $this->withApiHeaders()->actingAsSanctum($verifier)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
            ]);
        $this->assertJsonSuccess($verified);
        $verified->assertJsonPath('data.verification_status', 'verified');
        $verified->assertJsonPath('data.back_document_public_id', $backDoc);
    }

    public function test_single_face_identity_document_verifies_with_one_face(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-FACES-2');
        $submitter = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($submitter, $agency['public_id']);
        $frontDoc = $this->createDocumentViaApi($submitter);

        $identity = $this->withApiHeaders(['Authorization' => 'Bearer '.$submitter->createToken('faces-2')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'passport',
                'document_number' => 'PP-FACE-1',
                'expires_on' => now()->addYear()->toDateString(),
                'document_public_id' => $frontDoc,
            ]);
        $this->assertJsonSuccess($identity, 201);
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.public_id');

        $verifier = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $verified = $this->withApiHeaders()->actingAsSanctum($verifier)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
            ]);
        $this->assertJsonSuccess($verified);
        $verified->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_identity_document_expiry_requirement_is_enforced_from_catalog(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-EXP-CAT');
        $submitter = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $verifier = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($submitter, $agency['public_id']);

        $passport = $this->withApiHeaders()->actingAsSanctum($submitter)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'passport',
                'document_number' => 'PP-NO-EXP-1',
                'document_public_id' => $this->createDocumentViaApi($submitter),
            ]);
        $this->assertJsonSuccess($passport, 201);
        $passportPublicId = $this->requireStringJsonPath($passport, 'data.public_id');

        $passportVerify = $this->withApiHeaders()->actingAsSanctum($verifier)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$passportPublicId.'/status', [
                'action' => 'verify',
            ]);
        $passportVerify->assertStatus(422);
        $passportVerify->assertJsonValidationErrors(['expires_on']);

        // Voter card is catalogued as no-expiry-required, so it can be
        // verified with one face and no expires_on value.
        $voterCard = $this->withApiHeaders()->actingAsSanctum($submitter)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'voter_card',
                'document_number' => 'VC-NO-EXP-1',
                'document_public_id' => $this->createDocumentViaApi($submitter),
            ]);
        $this->assertJsonSuccess($voterCard, 201);
        $voterCardPublicId = $this->requireStringJsonPath($voterCard, 'data.public_id');

        $voterCardVerify = $this->withApiHeaders()->actingAsSanctum($verifier)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$voterCardPublicId.'/status', [
                'action' => 'verify',
            ]);
        $this->assertJsonSuccess($voterCardVerify);
        $voterCardVerify->assertJsonPath('data.verification_status', 'verified');
    }

    public function test_identity_document_type_catalog_is_exposed_and_enforced(): void
    {
        Storage::fake('local');

        $agency = $this->createAgency('AG-IDCAT');
        $actor = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);

        $catalog = $this->withApiHeaders()->actingAsSanctum($actor)
            ->getJson('/api/v1/reference/identity-document-types');
        $this->assertJsonSuccess($catalog);
        $catalog->assertJsonFragment(['key' => 'national_id', 'label' => 'National ID Card', 'required_faces' => 2, 'requires_expiry' => true]);
        $catalog->assertJsonFragment(['key' => 'passport', 'label' => 'Passport', 'required_faces' => 1, 'requires_expiry' => true]);

        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);
        $documentPublicId = $this->createDocumentViaApi($actor);

        // A known catalog key is accepted.
        $valid = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'NID-VALID-1',
                'document_public_id' => $documentPublicId,
            ]);
        $this->assertJsonSuccess($valid, 201);

        // A typo / unknown type is rejected with 422 on document_type.
        $invalid = $this->withApiHeaders()->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_idd',
                'document_number' => 'NID-INVALID-1',
                'document_public_id' => $this->createDocumentViaApi($actor),
            ]);
        $invalid->assertStatus(422);
        $invalid->assertJsonValidationErrors(['document_type']);
    }

    public function test_guarantor_directory_supports_institution_scope_filters_and_search(): void
    {
        $agencyA = $this->createAgency('AG-DIR-A');
        $agencyB = $this->createAgency('AG-DIR-B');
        $clientA = $this->makeDirectoryClient($agencyA['id'], 'DIR-A');
        $clientB = $this->makeDirectoryClient($agencyB['id'], 'DIR-B');

        $guarantorA = ClientGuarantor::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyA['id'],
            'client_id' => $clientA->id,
            'guarantor_full_name' => 'Alpha Guarantor',
            'status' => ClientGuarantor::STATUS_ACTIVE,
            'verification_status' => ClientGuarantor::VERIFICATION_PENDING,
        ]);
        $guarantorB = ClientGuarantor::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyB['id'],
            'client_id' => $clientB->id,
            'guarantor_full_name' => 'Beta Guarantor',
            'status' => ClientGuarantor::STATUS_INACTIVE,
            'verification_status' => ClientGuarantor::VERIFICATION_VERIFIED,
        ]);

        $institutionReader = $this->createUserWithRole('platform-admin');

        // scope=all returns both agencies for an institution reader, and the
        // payload carries the client_public_id needed by the UI.
        $all = $this->withApiHeaders()->actingAsSanctum($institutionReader)
            ->getJson('/api/v1/guarantors?scope=all');
        $this->assertJsonSuccess($all);
        $all->assertJsonFragment(['public_id' => $guarantorA->public_id]);
        $all->assertJsonFragment(['public_id' => $guarantorB->public_id]);
        $all->assertJsonFragment(['client_public_id' => $clientA->public_id]);

        // status / verification_status / agency filters.
        $byStatus = $this->withApiHeaders()->actingAsSanctum($institutionReader)
            ->getJson('/api/v1/guarantors?scope=all&filter[status]=inactive');
        $byStatus->assertJsonFragment(['public_id' => $guarantorB->public_id]);
        $byStatus->assertJsonMissing(['public_id' => $guarantorA->public_id]);

        $byVerification = $this->withApiHeaders()->actingAsSanctum($institutionReader)
            ->getJson('/api/v1/guarantors?scope=all&filter[verification_status]=verified');
        $byVerification->assertJsonFragment(['public_id' => $guarantorB->public_id]);
        $byVerification->assertJsonMissing(['public_id' => $guarantorA->public_id]);

        $byAgency = $this->withApiHeaders()->actingAsSanctum($institutionReader)
            ->getJson('/api/v1/guarantors?scope=all&filter[agency_public_id]='.$agencyB['public_id']);
        $byAgency->assertJsonFragment(['public_id' => $guarantorB->public_id]);
        $byAgency->assertJsonMissing(['public_id' => $guarantorA->public_id]);

        $bySearch = $this->withApiHeaders()->actingAsSanctum($institutionReader)
            ->getJson('/api/v1/guarantors?scope=all&search=Alpha');
        $bySearch->assertJsonFragment(['public_id' => $guarantorA->public_id]);
        $bySearch->assertJsonMissing(['public_id' => $guarantorB->public_id]);

        // An agency-scoped actor only ever sees its own agency, even when it
        // requests scope=all.
        $agencyActor = $this->createUserWithRole('kyc-officer', $agencyA['code'], $agencyA['name']);
        $scoped = $this->withApiHeaders()->actingAsSanctum($agencyActor)
            ->getJson('/api/v1/guarantors?scope=all');
        $this->assertJsonSuccess($scoped);
        $scoped->assertJsonFragment(['public_id' => $guarantorA->public_id]);
        $scoped->assertJsonMissing(['public_id' => $guarantorB->public_id]);
    }

    public function test_proxy_directory_returns_linked_public_ids_with_institution_scope(): void
    {
        $agencyA = $this->createAgency('AG-PXY-A');
        $agencyB = $this->createAgency('AG-PXY-B');
        $clientA = $this->makeDirectoryClient($agencyA['id'], 'PXY-A');
        $clientB = $this->makeDirectoryClient($agencyB['id'], 'PXY-B');
        $accountA = $this->createCustomerAccount($clientA);

        $proxyA = ClientProxy::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyA['id'],
            'client_id' => $clientA->id,
            'customer_account_id' => $accountA->id,
            'proxy_full_name' => 'Alpha Proxy',
            'mandate_type' => 'full',
            'status' => ClientProxy::STATUS_ACTIVE,
            'verification_status' => ClientProxy::VERIFICATION_PENDING,
        ]);
        $proxyB = ClientProxy::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyB['id'],
            'client_id' => $clientB->id,
            'proxy_full_name' => 'Beta Proxy',
            'mandate_type' => 'full',
            'status' => ClientProxy::STATUS_ACTIVE,
            'verification_status' => ClientProxy::VERIFICATION_PENDING,
        ]);

        $institutionReader = $this->createUserWithRole('platform-admin');
        $all = $this->withApiHeaders()->actingAsSanctum($institutionReader)
            ->getJson('/api/v1/proxies?scope=all');
        $this->assertJsonSuccess($all);
        $all->assertJsonFragment(['public_id' => $proxyA->public_id]);
        $all->assertJsonFragment(['public_id' => $proxyB->public_id]);
        $all->assertJsonFragment(['customer_account_public_id' => $accountA->public_id]);
        $all->assertJsonFragment(['client_public_id' => $clientA->public_id]);

        $agencyActor = $this->createUserWithRole('kyc-officer', $agencyB['code'], $agencyB['name']);
        $scoped = $this->withApiHeaders()->actingAsSanctum($agencyActor)
            ->getJson('/api/v1/proxies?scope=all');
        $scoped->assertJsonFragment(['public_id' => $proxyB->public_id]);
        $scoped->assertJsonMissing(['public_id' => $proxyA->public_id]);
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
                'document_type' => 'passport',
                'document_number' => 'SELFVERIFY-OVERRIDE-1',
                'expires_on' => now()->addYear()->toDateString(),
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
        $reviewer = $this->createUserWithRole('kyc-officer', $agency['code'], $agency['name']);
        $clientPublicId = $this->createClientViaApi($actor, $agency['public_id']);

        $identity = $this->withApiHeaders(['Authorization' => ''])
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'METADATA-ONLY-1',
            ]);
        $this->assertJsonSuccess($identity, 201);
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.public_id');

        $identitySelfVerify = $this->withApiHeaders(['Authorization' => ''])
            ->actingAsSanctum($actor)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
            ]);
        $identitySelfVerify->assertForbidden();

        $identityReviewerVerify = $this->withApiHeaders(['Authorization' => ''])
            ->actingAsSanctum($reviewer)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/identity-documents/'.$identityPublicId.'/status', [
                'action' => 'verify',
            ]);
        $this->assertJsonError($identityReviewerVerify, 422, 'Identity document verification requires linked KYC document evidence.');

        $guarantor = $this->withApiHeaders(['Authorization' => ''])
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/guarantors', [
                'guarantor_full_name' => 'Metadata Guarantor',
            ]);
        $this->assertJsonSuccess($guarantor, 201);
        $guarantorPublicId = $this->requireStringJsonPath($guarantor, 'data.public_id');

        $guarantorVerify = $this->withApiHeaders(['Authorization' => ''])
            ->actingAsSanctum($reviewer)
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/guarantors/'.$guarantorPublicId.'/status', [
                'action' => 'verify',
            ]);
        $this->assertJsonError($guarantorVerify, 422, 'Guarantor verification requires linked KYC document evidence.');

        $proxy = $this->withApiHeaders(['Authorization' => ''])
            ->actingAsSanctum($actor)
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Metadata Proxy',
                'mandate_type' => 'full',
            ]);
        $this->assertJsonSuccess($proxy, 201);
        $proxyPublicId = $this->requireStringJsonPath($proxy, 'data.public_id');

        $proxyVerify = $this->withApiHeaders(['Authorization' => ''])
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

    private function makeDirectoryClient(int $agencyId, string $reference): Client
    {
        return Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'client_reference' => 'CLI-'.$reference,
            'first_name' => 'Dir',
            'last_name' => $reference,
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_VERIFIED,
        ]);
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
