<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Policies\ClientPolicy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $create = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token')->plainTextToken])
            ->postJson('/api/v1/clients', [
                'agency_public_id' => $agency['public_id'],
                'first_name' => 'Alice',
                'last_name' => 'Ngono',
                'phone_number' => '+237600000111',
                'status' => Client::STATUS_ACTIVE,
            ]);

        $this->assertJsonSuccess($create, 201);
        $clientPublicId = $this->requireStringJsonPath($create, 'data.client.public_id');
        $create->assertJsonPath('data.client.first_name', 'Alice');
        $create->assertJsonPath('data.client.client_reference', fn (mixed $value) => is_string($value) && str_starts_with($value, 'CLI'));

        $update = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-2')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId, [
                'occupation' => 'Trader',
                'collection_type' => 'field',
                'collection_frequency' => 'weekly',
                'collection_target_amount' => '15000.50',
            ]);

        $this->assertJsonSuccess($update);
        $update->assertJsonPath('data.client.collection_target_amount', '15000.50');

        $show = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-3')->plainTextToken])
            ->getJson('/api/v1/clients/'.$clientPublicId);

        $this->assertJsonSuccess($show);
        $show->assertJsonPath('data.client.first_name', 'Alice');
        $show->assertJsonPath('data.client.last_name', 'Ngono');
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
        $documentPublicId = $this->requireStringJsonPath($upload, 'data.document.public_id');

        $identityCreate = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-v3')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/identity-documents', [
                'document_type' => 'national_id',
                'document_number' => 'CMR12345',
                'issued_on' => '2020-01-01',
                'expires_on' => now()->addYear()->toDateString(),
                'document_public_id' => $documentPublicId,
            ]);

        $this->assertJsonSuccess($identityCreate, 201);
        $identityPublicId = $this->requireStringJsonPath($identityCreate, 'data.identity_document.public_id');

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

        $actor->givePermissionTo('crm.kyc.verify');
        $verifyNow = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-v5')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/kyc-status', [
                'action' => 'verify',
            ]);

        $this->assertJsonSuccess($verifyNow);
        $verifyNow->assertJsonPath('data.client.kyc_status', Client::KYC_STATUS_VERIFIED);

        $this->assertDatabaseHas('client_kyc_reviews', [
            'client_id' => $client->id,
            'new_kyc_status' => Client::KYC_STATUS_VERIFIED,
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
        $proxy->assertJsonPath('data.proxy.status', 'expired');
        $proxyPublicId = $this->requireStringJsonPath($proxy, 'data.proxy.public_id');

        $expire = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('token-g3')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$proxyPublicId.'/status', [
                'action' => 'expire',
            ]);

        $this->assertJsonSuccess($expire);
        $expire->assertJsonPath('data.proxy.status', 'expired');
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
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.identity_document.public_id');

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
        $guarantorPublicId = $this->requireStringJsonPath($guarantor, 'data.guarantor.public_id');

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
        $proxyPublicId = $this->requireStringJsonPath($proxy, 'data.proxy.public_id');

        $proxyVerify = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('self-v6')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$proxyPublicId.'/status', [
                'action' => 'verify',
                'allow_self_verify' => true,
            ]);
        $proxyVerify->assertStatus(422);
        $proxyVerify->assertJsonValidationErrors(['allow_self_verify']);
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
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.identity_document.public_id');

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
        $allowed->assertJsonPath('data.client.kyc_status', Client::KYC_STATUS_VERIFIED);

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
        $identityPublicId = $this->requireStringJsonPath($identity, 'data.identity_document.public_id');

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
        $guarantorPublicId = $this->requireStringJsonPath($guarantor, 'data.guarantor.public_id');

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
        $proxyPublicId = $this->requireStringJsonPath($proxy, 'data.proxy.public_id');

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
        $futureProxy->assertJsonPath('data.proxy.status', 'inactive');

        $activeProxy = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('proxy-v2')->plainTextToken])
            ->postJson('/api/v1/clients/'.$clientPublicId.'/proxies', [
                'proxy_full_name' => 'Active Proxy',
                'mandate_type' => 'limited',
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => now()->addDays(5)->toDateString(),
            ]);
        $this->assertJsonSuccess($activeProxy, 201);
        $activeProxyPublicId = $this->requireStringJsonPath($activeProxy, 'data.proxy.public_id');

        $updated = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('proxy-v3')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$activeProxyPublicId, [
                'starts_on' => now()->addDays(3)->toDateString(),
            ]);
        $this->assertJsonSuccess($updated);
        $updated->assertJsonPath('data.proxy.status', 'inactive');

        $reactivated = $this->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('proxy-v4')->plainTextToken])
            ->patchJson('/api/v1/clients/'.$clientPublicId.'/proxies/'.$activeProxyPublicId, [
                'starts_on' => now()->subDay()->toDateString(),
                'ends_on' => now()->addDays(3)->toDateString(),
            ]);
        $this->assertJsonSuccess($reactivated);
        $reactivated->assertJsonPath('data.proxy.status', 'active');
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

        return $this->requireStringJsonPath($response, 'data.client.public_id');
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

        return $this->requireStringJsonPath($response, 'data.document.public_id');
    }

    private function createDocumentInAgency(int $agencyId): string
    {
        $document = Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'category' => 'identity',
            'title' => 'External Document',
            'status' => Document::STATUS_ACTIVE,
        ]);

        $document->addMediaFromString('test-image-content')
            ->usingFileName('external.jpg')
            ->toMediaCollection('kyc_documents');

        return $document->public_id;
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
        $value = $response instanceof \Illuminate\Testing\TestResponse ? $response->json($path) : null;
        self::assertIsString($value);

        return $value;
    }
}
