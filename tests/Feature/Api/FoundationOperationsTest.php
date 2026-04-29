<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class FoundationOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authorized_user_can_upload_and_archive_document_metadata(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('DOC');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $uploadResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'National ID',
                'metadata' => ['side' => 'front'],
                'file' => UploadedFile::fake()->image('national-id.jpg', 640, 480),
            ]);

        $this->assertJsonSuccess($uploadResponse, 201);
        $uploadResponse->assertJsonPath('message', 'Document uploaded successfully');
        $uploadResponse->assertJsonPath('data.document.category', 'kyc');
        $uploadResponse->assertJsonMissingPath('data.document.path');

        $document = Document::query()->firstOrFail();
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'agency_id' => $agency['id'],
        ]);
        self::assertIsString($document->path);
        Storage::disk('local')->assertExists($document->path);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'document.created',
        ]);

        $archiveResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/documents/'.$document->public_id.'/archive');

        $this->assertJsonSuccess($archiveResponse);
        $archiveResponse->assertJsonPath('data.document.status', Document::STATUS_ARCHIVED);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'document.archived',
        ]);
    }

    public function test_staff_without_document_permission_cannot_upload_document(): void
    {
        $agency = $this->createAgency('STA');
        $actor = $this->createUserWithRole('staff', $agency['code'], $agency['name']);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'National ID',
                'file' => UploadedFile::fake()->image('national-id.jpg'),
            ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_staff_cannot_view_document_from_different_agency(): void
    {
        Storage::fake('local');
        $agencyA = $this->createAgency('AGA');
        $agencyB = $this->createAgency('AGB');
        $actorA = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $actorB = $this->createUserWithRole('agency-manager', $agencyB['code'], $agencyB['name']);

        // Verify agency assignments
        self::assertEquals($agencyA['id'], $actorA->currentAgencyId());
        self::assertEquals($agencyB['id'], $actorB->currentAgencyId());

        $uploadResponse = $this
            ->actingAs($actorA)
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorA->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Agency A Document',
                'file' => UploadedFile::fake()->image('doc-a.jpg'),
            ]);

        $documentPublicId = $this->requireStringJsonPath($uploadResponse, 'data.document.public_id');
        $document = Document::query()->where('public_id', $documentPublicId)->first();

        self::assertInstanceOf(Document::class, $document);
        self::assertEquals($agencyA['id'], $document->agency_id);

        $viewResponse = $this
            ->actingAs($actorB)
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorB->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/documents/'.$documentPublicId);

        $viewResponse->assertForbidden();
    }

    public function test_staff_cannot_archive_document_from_different_agency(): void
    {
        Storage::fake('local');
        $agencyA = $this->createAgency('AGC');
        $agencyB = $this->createAgency('AGD');
        $actorA = $this->createUserWithRole('agency-manager', $agencyA['code'], $agencyA['name']);
        $actorB = $this->createUserWithRole('agency-manager', $agencyB['code'], $agencyB['name']);

        // Verify agency assignments
        self::assertEquals($agencyA['id'], $actorA->currentAgencyId());
        self::assertEquals($agencyB['id'], $actorB->currentAgencyId());

        $uploadResponse = $this
            ->actingAs($actorA)
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorA->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Agency A Document',
                'file' => UploadedFile::fake()->image('doc-a.jpg'),
            ]);

        $documentPublicId = $this->requireStringJsonPath($uploadResponse, 'data.document.public_id');
        $document = Document::query()->where('public_id', $documentPublicId)->first();

        self::assertInstanceOf(Document::class, $document);
        self::assertEquals($agencyA['id'], $document->agency_id);

        $archiveResponse = $this
            ->actingAs($actorB)
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorB->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/documents/'.$documentPublicId.'/archive');

        $archiveResponse->assertForbidden();
    }

    public function test_document_scope_uses_active_assignment_not_cached_user_agency(): void
    {
        Storage::fake('local');
        $assignedAgency = $this->createAgency('ASN');
        $cachedAgency = $this->createAgency('CAC');
        $actor = $this->createUserWithRole('agency-manager', $assignedAgency['code'], $assignedAgency['name']);
        $actor->forceFill([
            'agency_id' => $cachedAgency['id'],
            'agency_code' => $cachedAgency['code'],
            'agency_name' => $cachedAgency['name'],
        ])->save();

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Assigned Scope Document',
                'file' => UploadedFile::fake()->image('assigned-scope.jpg', 320, 240),
            ]);

        $this->assertJsonSuccess($response, 201);
        $this->assertDatabaseHas('documents', [
            'agency_id' => $assignedAgency['id'],
            'title' => 'Assigned Scope Document',
        ]);
        $this->assertDatabaseMissing('documents', [
            'agency_id' => $cachedAgency['id'],
            'title' => 'Assigned Scope Document',
        ]);
    }

    public function test_current_agency_scope_fails_closed_without_active_assignment_even_if_cached_agency_exists(): void
    {
        $agency = $this->createAgency('CLD');
        $actor = $this->createUserWithRole('agency-manager', $agency['code'], $agency['name']);

        DB::table('staff_agency_assignments')
            ->where('user_id', $actor->id)
            ->delete();

        $actor->forceFill([
            'agency_id' => $agency['id'],
            'agency_code' => $agency['code'],
            'agency_name' => $agency['name'],
        ])->save();

        self::assertNull($actor->currentAgencyId());
    }

    public function test_authorized_user_can_reserve_reference_numbers_sequentially(): void
    {
        $actor = $this->createUserWithRole('platform-admin');
        $headers = ['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken];

        $first = $this->withApiHeaders($headers)->postJson('/api/v1/reference-numbers', [
            'key' => 'loan',
        ]);
        $second = $this->withApiHeaders($headers)->postJson('/api/v1/reference-numbers', [
            'key' => 'loan',
        ]);

        $this->assertJsonSuccess($first, 201);
        $this->assertJsonSuccess($second, 201);
        $first->assertJsonPath('data.reference', 'LOA00000001');
        $second->assertJsonPath('data.reference', 'LOA00000002');
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'reference.reserved',
        ]);
    }

    public function test_upload_creates_domain_document_and_media_record(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('TST');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => UploadedFile::fake()->image('test.jpg', 640, 480),
            ]);

        $this->assertJsonSuccess($response, 201);
        $documentPublicId = $this->requireStringJsonPath($response, 'data.document.public_id');

        $document = Document::query()->where('public_id', $documentPublicId)->first();
        self::assertInstanceOf(Document::class, $document);
        self::assertTrue($document->hasMedia('kyc_documents'));
        self::assertCount(1, $document->getMedia('kyc_documents'));
    }

    public function test_upload_rejects_unsupported_mime_types(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('TST2');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => UploadedFile::fake()->create('test.exe', 100),
            ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_upload_rejects_oversized_files(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('TST3');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => UploadedFile::fake()->create('test.pdf', 15 * 1024), // 15MB
            ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_archive_does_not_delete_files(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('TST4');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $uploadResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => UploadedFile::fake()->image('test.jpg', 640, 480),
            ]);

        $documentPublicId = $this->requireStringJsonPath($uploadResponse, 'data.document.public_id');
        $document = Document::query()->where('public_id', $documentPublicId)->first();
        self::assertInstanceOf(Document::class, $document);
        $media = $document->getMedia('kyc_documents')->first();

        self::assertNotNull($media);
        Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());

        $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/documents/'.$documentPublicId.'/archive')
            ->assertOk();

        $document->refresh();
        self::assertEquals(Document::STATUS_ARCHIVED, $document->status);
        Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());
    }

    public function test_api_responses_do_not_expose_private_storage_paths(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('TST5');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => UploadedFile::fake()->image('test.jpg', 640, 480),
            ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonMissingPath('data.document.path');
        $response->assertJsonMissingPath('data.document.disk');
    }

    public function test_path_traversal_payload_cannot_bypass_validation(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('SEC1');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $maliciousNamedFile = UploadedFile::fake()->image('..\\..\\evil.jpg', 640, 480);

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Malicious Filename Probe',
                'file' => $maliciousNamedFile,
            ]);

        $this->assertJsonSuccess($response, 201);
        $documentPublicId = $this->requireStringJsonPath($response, 'data.document.public_id');

        $document = Document::query()->where('public_id', $documentPublicId)->first();
        self::assertInstanceOf(Document::class, $document);
        $media = $document->getMedia('kyc_documents')->first();
        self::assertNotNull($media);
        Storage::disk($media->disk)->assertExists($media->getPathRelativeToRoot());
        self::assertStringNotContainsString('..', $media->file_name);
        self::assertStringNotContainsString('/', $media->file_name);
        self::assertStringNotContainsString('\\', $media->file_name);
    }

    public function test_content_type_spoofing_does_not_bypass_validation(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('SEC2');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $spoofedFile = UploadedFile::fake()->createWithContent(
            'identity.jpg',
            "MZ\x90\x00".str_repeat('A', 2048)
        );

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => $spoofedFile,
            ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_generic_media_ids_cannot_bypass_authorization(): void
    {
        Storage::fake('local');
        $agencyA = $this->createAgency('SEC3');
        $agencyB = $this->createAgency('SEC4');
        $actorA = $this->createUserWithRole('platform-admin', $agencyA['code'], $agencyA['name']);
        $actorB = $this->createUserWithRole('platform-admin', $agencyB['code'], $agencyB['name']);

        $uploadResponse = $this
            ->actingAs($actorA)
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorA->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => UploadedFile::fake()->image('test.jpg', 640, 480),
            ]);

        $documentPublicId = $this->requireStringJsonPath($uploadResponse, 'data.document.public_id');
        $document = Document::query()->where('public_id', $documentPublicId)->first();
        self::assertInstanceOf(Document::class, $document);
        $media = $document->getMedia('kyc_documents')->first();

        self::assertNotNull($media);

        // Try to access document by public ID (not media ID)
        $viewResponse = $this
            ->actingAs($actorB)
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorB->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/documents/'.$documentPublicId);

        $viewResponse->assertForbidden();

        $documentByMediaIdResponse = $this
            ->actingAs($actorB)
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorB->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/documents/'.$media->id);

        $documentByMediaIdResponse->assertNotFound();

        $directMediaRouteResponse = $this
            ->actingAs($actorB)
            ->withApiHeaders(['Authorization' => 'Bearer '.$actorB->createToken('test-token')->plainTextToken])
            ->get('/media/'.$media->id.'/'.$media->file_name);

        $directMediaRouteResponse->assertNotFound();
    }

    public function test_archived_documents_are_protected(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('SEC5');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $uploadResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => UploadedFile::fake()->image('test.jpg', 640, 480),
            ]);

        $documentPublicId = $this->requireStringJsonPath($uploadResponse, 'data.document.public_id');

        $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->patchJson('/api/v1/documents/'.$documentPublicId.'/archive')
            ->assertOk();

        // Archived document can still be viewed (by authorized user)
        $viewResponse = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/documents/'.$documentPublicId);

        $viewResponse->assertOk();
        $viewResponse->assertJsonPath('data.document.status', Document::STATUS_ARCHIVED);
    }

    public function test_audit_logs_capture_media_actions(): void
    {
        Storage::fake('local');
        $agency = $this->createAgency('SEC6');
        $actor = $this->createUserWithRole('platform-admin', $agency['code'], $agency['name']);

        $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->postJson('/api/v1/documents', [
                'category' => 'kyc',
                'title' => 'Test Document',
                'file' => UploadedFile::fake()->image('test.jpg', 640, 480),
            ]);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'document.created',
        ]);
    }

    public function test_auditor_can_browse_security_audit_events(): void
    {
        $actor = $this->createUserWithRole('auditor');
        activity('security')->event('test.event')->log('test.event');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/audit-events?log_name=security');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.events.0.log_name', 'security');
        $response->assertJsonPath('data.events.0.event', 'test.event');
    }

    public function test_staff_without_audit_permission_cannot_browse_audit_events(): void
    {
        $actor = $this->createUserWithRole('staff');

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$actor->createToken('test-token')->plainTextToken])
            ->getJson('/api/v1/audit-events');

        $response->assertForbidden();
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
     * @return array{id:int, code:string, name:string}
     */
    private function createAgency(string $code, ?string $name = null): array
    {
        $name ??= $code.' Agency';
        $id = DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $name,
            'status' => 'active',
        ]);

        return ['id' => $id, 'code' => $code, 'name' => $name];
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
