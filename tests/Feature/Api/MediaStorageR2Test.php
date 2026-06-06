<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Support\Media\MediaStorageDiskResolver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

final class MediaStorageR2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    // --- R2-003: Upload compatibility -------------------------------------

    public function test_local_upload_still_works_and_stores_local_disk(): void
    {
        $agency = $this->createAgency('R2LOC');
        $admin = $this->platformAdmin();

        $response = $this->uploadDocument($admin, $agency['public_id']);
        $this->assertJsonSuccess($response, 201);

        // API response never exposes the backing disk or object path.
        $response->assertJsonMissingPath('data.disk');
        $response->assertJsonMissingPath('data.path');

        $document = Document::query()->where('public_id', $this->requireStringJsonPath($response, 'data.public_id'))->firstOrFail();
        self::assertSame('local', $document->disk);
        self::assertSame('local', $document->getFirstMedia('kyc_documents')?->disk);
    }

    public function test_r2_upload_stores_disk_r2(): void
    {
        $this->enableHealthyR2();
        $agency = $this->createAgency('R2OK');
        $admin = $this->platformAdmin();

        $response = $this->uploadDocument($admin, $agency['public_id']);
        $this->assertJsonSuccess($response, 201);
        $response->assertJsonMissingPath('data.disk');
        $response->assertJsonMissingPath('data.path');

        $document = Document::query()->where('public_id', $this->requireStringJsonPath($response, 'data.public_id'))->firstOrFail();
        self::assertSame('r2', $document->disk);

        $media = $document->getFirstMedia('kyc_documents');
        self::assertNotNull($media);
        self::assertSame('r2', $media->disk);
        Storage::disk('r2')->assertExists($media->getPathRelativeToRoot());

        self::assertTrue(
            Activity::query()->where('event', 'media.storage.r2_selected')->getQuery()->exists(),
            'Expected a media.storage.r2_selected audit event.'
        );
    }

    public function test_fail_closed_unhealthy_r2_returns_503_and_creates_no_document(): void
    {
        $this->enableUnreachableR2(MediaStorageDiskResolver::FALLBACK_FAIL_CLOSED);
        $agency = $this->createAgency('R2FC');
        $admin = $this->platformAdmin();

        $response = $this->uploadDocument($admin, $agency['public_id']);
        $response->assertStatus(503);
        $response->assertJsonPath('errors.code', 'media_storage_unavailable');

        self::assertSame(0, Document::query()->getQuery()->count(), 'Failed remote storage must not leave an orphan document row.');
    }

    public function test_fallback_local_stores_on_local_and_audits_fallback(): void
    {
        $this->enableUnreachableR2(MediaStorageDiskResolver::FALLBACK_LOCAL);
        $agency = $this->createAgency('R2FB');
        $admin = $this->platformAdmin();

        $response = $this->uploadDocument($admin, $agency['public_id']);
        $this->assertJsonSuccess($response, 201);

        $document = Document::query()->where('public_id', $this->requireStringJsonPath($response, 'data.public_id'))->firstOrFail();
        self::assertSame('local', $document->disk);

        self::assertTrue(
            Activity::query()->where('event', 'media.storage.local_fallback_used')->getQuery()->exists(),
            'Expected a media.storage.local_fallback_used audit event.'
        );
    }

    public function test_explicit_local_media_disk_upload_remains_supported(): void
    {
        config([
            'security.documents.media.auto' => false,
            'media-library.disk_name' => 'local',
        ]);

        $agency = $this->createAgency('R2EXL');
        $admin = $this->platformAdmin();

        $response = $this->uploadDocument($admin, $agency['public_id']);
        $this->assertJsonSuccess($response, 201);

        $document = Document::query()->where('public_id', $this->requireStringJsonPath($response, 'data.public_id'))->firstOrFail();
        self::assertSame('local', $document->disk);
        self::assertSame('local', $document->getFirstMedia('kyc_documents')?->disk);
    }

    public function test_invalid_explicit_public_media_disk_is_rejected_without_creating_document(): void
    {
        config([
            'security.documents.media.auto' => false,
            'media-library.disk_name' => 'public',
        ]);

        $agency = $this->createAgency('R2EXP');
        $admin = $this->platformAdmin();

        $response = $this->uploadDocument($admin, $agency['public_id']);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.code', 'media_storage_invalid_config');
        self::assertSame(0, Document::query()->getQuery()->count());
    }

    public function test_document_created_audit_does_not_leak_object_key(): void
    {
        $agency = $this->createAgency('R2AUD');
        $admin = $this->platformAdmin();
        $this->uploadDocument($admin, $agency['public_id'])->assertStatus(201);

        $activity = Activity::query()->where('event', 'document.created')->firstOrFail();
        $properties = json_encode($activity->properties?->toArray() ?? []);
        self::assertIsString($properties);
        self::assertStringNotContainsString('kyc_documents/', $properties);
        self::assertStringNotContainsString('.jpg', $properties);
    }

    // --- R2-004: Download / preview compatibility -------------------------

    public function test_download_streams_local_media(): void
    {
        $agency = $this->createAgency('R2DL');
        $admin = $this->platformAdmin();
        $publicId = $this->requireStringJsonPath($this->uploadDocument($admin, $agency['public_id']), 'data.public_id');

        $this->actingWith($admin)->get("/api/v1/documents/{$publicId}/file")->assertOk();
    }

    public function test_download_streams_r2_media(): void
    {
        $this->enableHealthyR2();
        $agency = $this->createAgency('R2DR2');
        $admin = $this->platformAdmin();
        $publicId = $this->requireStringJsonPath($this->uploadDocument($admin, $agency['public_id']), 'data.public_id');

        $document = Document::query()->where('public_id', $publicId)->firstOrFail();
        self::assertSame('r2', $document->disk);

        $this->actingWith($admin)->get("/api/v1/documents/{$publicId}/file")->assertOk();
    }

    public function test_profile_photo_thumbnail_renders_from_r2_without_storing_derivatives(): void
    {
        $this->enableHealthyR2();
        $agency = $this->createAgency('R2PPR');
        $admin = $this->platformAdmin();
        $publicId = $this->requireStringJsonPath(
            $this->uploadDocument($admin, $agency['public_id'], UploadedFile::fake()->image('profile.jpg', 640, 640), 'profile_photo'),
            'data.public_id'
        );

        $document = Document::query()->where('public_id', $publicId)->firstOrFail();
        self::assertSame('r2', $document->disk);
        $media = $document->getFirstMedia('kyc_documents');
        self::assertNotNull($media);
        $this->assertNoStoredDerivatives($media->disk, $media->getPathRelativeToRoot());

        $client = Client::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency['id'],
            'client_reference' => 'CLI-R2-PHOTO',
            'first_name' => 'R2',
            'last_name' => 'Photo',
            'status' => Client::STATUS_ACTIVE,
            'kyc_status' => Client::KYC_STATUS_VERIFIED,
            'profile_photo_document_id' => $document->id,
        ]);

        $mediaCountBefore = DB::table('media')->count();
        $filesBefore = Storage::disk('r2')->allFiles();
        sort($filesBefore);

        $url = URL::temporarySignedRoute(
            'clients.profile-photo-thumbnail',
            now()->addMinutes(5),
            ['client' => $client->public_id],
        );

        $response = $this->get($url);
        $response->assertOk();
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));

        $filesAfter = Storage::disk('r2')->allFiles();
        sort($filesAfter);
        self::assertSame($mediaCountBefore, DB::table('media')->count(), 'Profile-photo thumbnail rendering must not create media rows.');
        self::assertSame($filesBefore, $filesAfter, 'Profile-photo thumbnail rendering must not store conversion files.');
        $this->assertNoStoredDerivatives($media->disk, $media->getPathRelativeToRoot());
    }

    public function test_missing_r2_object_returns_controlled_not_found(): void
    {
        $this->enableHealthyR2();
        $agency = $this->createAgency('R2MISS');
        $admin = $this->platformAdmin();
        $publicId = $this->requireStringJsonPath($this->uploadDocument($admin, $agency['public_id']), 'data.public_id');

        $document = Document::query()->where('public_id', $publicId)->firstOrFail();
        $media = $document->getFirstMedia('kyc_documents');
        self::assertNotNull($media);
        Storage::disk('r2')->delete($media->getPathRelativeToRoot());

        $this->actingWith($admin)->get("/api/v1/documents/{$publicId}/file")->assertNotFound();
    }

    public function test_cross_agency_download_is_denied_for_r2_media(): void
    {
        $this->enableHealthyR2();
        $agencyA = $this->createAgency('R2XA');
        $agencyB = $this->createAgency('R2XB');
        $admin = $this->platformAdmin();
        $publicId = $this->requireStringJsonPath($this->uploadDocument($admin, $agencyA['public_id']), 'data.public_id');

        $otherAgencyUser = $this->createUserWithRole('teller', $agencyB);

        $this->actingWith($otherAgencyUser)->get("/api/v1/documents/{$publicId}/file")->assertForbidden();
    }

    // --- R2-005: Regulated media processing policy ------------------------

    public function test_image_upload_creates_single_media_with_no_conversions(): void
    {
        $agency = $this->createAgency('R2IMG');
        $admin = $this->platformAdmin();
        $publicId = $this->requireStringJsonPath(
            $this->uploadDocument($admin, $agency['public_id'], UploadedFile::fake()->image('photo.jpg', 640, 640)),
            'data.public_id'
        );

        $document = Document::query()->where('public_id', $publicId)->firstOrFail();
        self::assertCount(1, $document->getMedia('kyc_documents'));

        $media = $document->getFirstMedia('kyc_documents');
        self::assertNotNull($media);
        self::assertSame([], $media->generated_conversions);
        self::assertSame([], $media->responsive_images);
        $this->assertNoStoredDerivatives($media->disk, $media->getPathRelativeToRoot());
    }

    public function test_pdf_upload_creates_single_media_with_no_preview(): void
    {
        $agency = $this->createAgency('R2PDF');
        $admin = $this->platformAdmin();

        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        self::assertIsString($tmp);
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $file = new UploadedFile($tmp, 'evidence.pdf', 'application/pdf', null, true);

        $publicId = $this->requireStringJsonPath(
            $this->uploadDocument($admin, $agency['public_id'], $file),
            'data.public_id'
        );

        $document = Document::query()->where('public_id', $publicId)->firstOrFail();
        // Exactly one media row for the original; no derived preview/thumbnail.
        self::assertCount(1, $document->getMedia('kyc_documents'));
        $media = $document->getFirstMedia('kyc_documents');
        self::assertNotNull($media);
        self::assertSame([], $media->generated_conversions);
        self::assertSame([], $media->responsive_images);
        $this->assertNoStoredDerivatives($media->disk, $media->getPathRelativeToRoot());
    }

    public function test_checksum_equals_original_upload(): void
    {
        $agency = $this->createAgency('R2SUM');
        $admin = $this->platformAdmin();
        // A faked image carries real JPEG bytes (and passes the binary
        // signature validation in StoreDocumentRequest).
        $file = UploadedFile::fake()->image('evidence.jpg', 320, 320);
        $realPath = $file->getRealPath();
        self::assertIsString($realPath);
        $expected = hash_file('sha256', $realPath);

        $publicId = $this->requireStringJsonPath(
            $this->uploadDocument($admin, $agency['public_id'], $file),
            'data.public_id'
        );

        $document = Document::query()->where('public_id', $publicId)->firstOrFail();
        self::assertSame($expected, $document->checksum_sha256);
    }

    public function test_document_does_not_register_conversions_or_responsive_images(): void
    {
        // Static guard mirroring R2-005: the evidence model must never opt into
        // ecommerce-style derivatives.
        $source = (string) file_get_contents(app_path('Models/Document.php'));
        self::assertStringNotContainsString('addMediaConversion(', $source);
        self::assertStringNotContainsString('withResponsiveImages(', $source);
    }

    // --- R2-006: Status endpoint ------------------------------------------

    public function test_status_requires_authentication(): void
    {
        $this->getJson('/api/v1/media-storage/status')->assertUnauthorized();
    }

    public function test_status_forbidden_for_non_privileged_role(): void
    {
        $teller = $this->createUserWithRole('teller', $this->createAgency('R2STT'));
        $this->actingWith($teller)->getJson('/api/v1/media-storage/status')->assertForbidden();
    }

    public function test_status_disabled_r2_reports_local(): void
    {
        $admin = $this->platformAdmin();
        $response = $this->actingWith($admin)->getJson('/api/v1/media-storage/status');
        $this->assertJsonSuccess($response, 200);
        $response->assertJsonPath('data.active_disk', 'local');
        $response->assertJsonPath('data.r2_enabled', false);
    }

    public function test_status_healthy_r2_reports_r2(): void
    {
        $this->enableHealthyR2();
        $admin = $this->platformAdmin();
        $response = $this->actingWith($admin)->getJson('/api/v1/media-storage/status');
        $this->assertJsonSuccess($response, 200);
        $response->assertJsonPath('data.active_disk', 'r2');
        $response->assertJsonPath('data.r2_healthy', true);
    }

    public function test_status_partial_config_is_flagged(): void
    {
        config([
            'security.documents.media.r2_enabled' => true,
            'filesystems.disks.r2.key' => 'k',
            'filesystems.disks.r2.secret' => 's',
            'filesystems.disks.r2.bucket' => '', // incomplete
        ]);
        $admin = $this->platformAdmin();
        $response = $this->actingWith($admin)->getJson('/api/v1/media-storage/status');
        $this->assertJsonSuccess($response, 200);
        $response->assertJsonPath('data.active_disk', 'local');
        $response->assertJsonPath('data.r2_partial_config', true);
        $response->assertJsonPath('data.r2_configured', false);
    }

    public function test_status_never_exposes_secrets(): void
    {
        $this->enableHealthyR2();
        $admin = $this->platformAdmin();
        $response = $this->actingWith($admin)->getJson('/api/v1/media-storage/status');
        $this->assertJsonSuccess($response, 200);
        $response->assertDontSee('test-secret');
        $response->assertDontSee('test-key');
        $response->assertDontSee('test-bucket');
    }

    // --- R2-007: Existing media compatibility -----------------------------

    public function test_local_document_still_downloads_after_r2_enabled(): void
    {
        $agency = $this->createAgency('R2COMP');
        $admin = $this->platformAdmin();

        // Upload while R2 is disabled -> local-backed media.
        $localPublicId = $this->requireStringJsonPath($this->uploadDocument($admin, $agency['public_id']), 'data.public_id');
        self::assertSame('local', Document::query()->where('public_id', $localPublicId)->firstOrFail()->disk);

        // Now enable R2 and upload a second document -> R2-backed.
        $this->enableHealthyR2();
        $r2PublicId = $this->requireStringJsonPath($this->uploadDocument($admin, $agency['public_id']), 'data.public_id');
        self::assertSame('r2', Document::query()->where('public_id', $r2PublicId)->firstOrFail()->disk);

        // The pre-existing local document still serves from local.
        $this->actingWith($admin)->get("/api/v1/documents/{$localPublicId}/file")->assertOk();
        $this->actingWith($admin)->get("/api/v1/documents/{$r2PublicId}/file")->assertOk();
    }

    // --- R2-011: Permissions and role catalog -----------------------------

    public function test_roles_catalog_exposes_media_storage_permissions(): void
    {
        $admin = $this->platformAdmin();
        $response = $this->actingWith($admin)->getJson('/api/v1/roles');
        $this->assertJsonSuccess($response, 200);

        $response->assertSee('system.media-storage.view');
        $response->assertSee('system.media-storage.manage');
        $response->assertSee('system.media-storage.migrate');
    }

    public function test_media_storage_manage_and_migrate_are_non_delegable(): void
    {
        $admin = $this->platformAdmin();
        $response = $this->actingWith($admin)->getJson('/api/v1/roles');
        $nonDelegable = $response->json('data.permission_policy.non_delegable');
        self::assertIsArray($nonDelegable);
        self::assertContains('system.media-storage.manage', $nonDelegable);
        self::assertContains('system.media-storage.migrate', $nonDelegable);

        $protected = $response->json('data.permission_policy.protected');
        self::assertIsArray($protected);
        self::assertContains('system.media-storage.view', $protected);
    }

    // --- Helpers ----------------------------------------------------------

    private function uploadDocument(User $actor, string $agencyPublicId, ?UploadedFile $file = null, string $category = 'identity'): TestResponse
    {
        return $this->actingWith($actor)->postJson('/api/v1/documents', [
            'file' => $file ?? UploadedFile::fake()->image('id.jpg'),
            'category' => $category,
            'title' => 'KYC Document',
            'agency_public_id' => $agencyPublicId,
        ]);
    }

    private function assertNoStoredDerivatives(string $disk, string $originalPath): void
    {
        $directory = dirname($originalPath);
        $files = Storage::disk($disk)->allFiles($directory === '.' ? '' : $directory);

        foreach ($files as $file) {
            self::assertStringNotContainsString('/conversions/', $file);
            self::assertStringNotContainsString('/responsive-images/', $file);
        }
    }

    private function enableHealthyR2(): void
    {
        config([
            'security.documents.media.r2_enabled' => true,
            'filesystems.disks.r2.key' => 'test-key',
            'filesystems.disks.r2.secret' => 'test-secret',
            'filesystems.disks.r2.bucket' => 'test-bucket',
            'filesystems.disks.r2.account_id' => 'test-account',
            'filesystems.disks.r2.endpoint' => 'https://test-account.r2.cloudflarestorage.com',
        ]);
        Storage::fake('r2');
    }

    private function enableUnreachableR2(string $fallbackMode): void
    {
        config([
            'security.documents.media.r2_enabled' => true,
            'security.documents.media.r2_fallback_mode' => $fallbackMode,
            'filesystems.disks.r2.key' => 'test-key',
            'filesystems.disks.r2.secret' => 'test-secret',
            'filesystems.disks.r2.bucket' => 'test-bucket',
            'filesystems.disks.r2.account_id' => 'test-account',
            'filesystems.disks.r2.endpoint' => 'https://test-account.r2.cloudflarestorage.com',
            // Invalid driver makes Storage::disk('r2') throw -> unreachable.
            'filesystems.disks.r2.driver' => 'invalid-driver-for-test',
        ]);
    }

    private function platformAdmin(): User
    {
        return $this->createUserWithRole('platform-admin');
    }

    /**
     * @param  array{id: int, public_id: string, code: string, name: string}|null  $agency
     */
    private function createUserWithRole(string $role, ?array $agency = null): User
    {
        $user = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
            'agency_id' => $agency['id'] ?? null,
            'agency_code' => $agency['code'] ?? null,
            'agency_name' => $agency['name'] ?? null,
        ]);
        $user->assignRole($role);

        if ($agency !== null) {
            DB::table('staff_agency_assignments')->insert([
                'public_id' => (string) Str::ulid(),
                'user_id' => $user->id,
                'agency_id' => $agency['id'],
                'role_at_agency' => $role,
                'starts_on' => now()->toDateString(),
                'is_primary' => true,
                'status' => 'active',
            ]);
        }

        return $user;
    }

    /**
     * @return array{id: int, public_id: string, code: string, name: string}
     */
    private function createAgency(string $code): array
    {
        $publicId = (string) Str::ulid();
        $id = DB::table('agencies')->insertGetId([
            'public_id' => $publicId,
            'code' => $code,
            'name' => $code.' Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'public_id' => $publicId, 'code' => $code, 'name' => $code.' Agency'];
    }

    private function actingWith(User $actor): self
    {
        // Sanctum::actingAs re-binds the guard on each call, so switching
        // actors mid-test works (a fresh bearer token would not — the request
        // guard memoizes the first resolved user).
        $this->actingAsSanctum($actor);

        return $this->withApiHeaders();
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
