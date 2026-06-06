<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Document;
use App\Models\MediaStorageMigration;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class MediaMigrationToR2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    // --- R2-008: Command behavior -----------------------------------------

    public function test_dry_run_reports_candidates_without_modifying_records(): void
    {
        $agencyId = $this->createAgencyId('MIGDRY');
        $document = $this->createLocalDocument($agencyId, 'dry-run-payload');

        self::assertSame(0, Artisan::call('media:migrate-to-r2', ['--dry-run' => true]));

        $operation = MediaStorageMigration::query()->latest('id')->firstOrFail();
        self::assertTrue($operation->dry_run);
        self::assertSame(MediaStorageMigration::STATUS_COMPLETED, $operation->status);
        self::assertSame(1, $operation->total_candidates);
        self::assertGreaterThan(0, $operation->total_bytes);
        self::assertSame(0, $operation->migrated_count);

        // Source unchanged.
        $document->refresh();
        self::assertSame('local', $document->disk);
        self::assertSame('local', $document->getFirstMedia('kyc_documents')?->disk);
    }

    public function test_migration_copies_local_document_to_r2_and_preserves_checksum(): void
    {
        $this->enableR2();
        $agencyId = $this->createAgencyId('MIGOK');
        $payload = 'evidence-bytes-'.Str::random(24);
        $document = $this->createLocalDocument($agencyId, $payload);
        $media = $document->getFirstMedia('kyc_documents');
        self::assertNotNull($media);
        $path = $media->getPathRelativeToRoot();
        $sourceHash = hash('sha256', (string) Storage::disk('local')->get($path));

        self::assertSame(0, Artisan::call('media:migrate-to-r2'));

        $operation = MediaStorageMigration::query()->latest('id')->firstOrFail();
        self::assertSame(MediaStorageMigration::STATUS_COMPLETED, $operation->status);
        self::assertSame(1, $operation->migrated_count);
        self::assertSame(0, $operation->failed_count);

        // Target exists with byte-identical checksum.
        Storage::disk('r2')->assertExists($path);
        self::assertSame($sourceHash, hash('sha256', (string) Storage::disk('r2')->get($path)));

        // Metadata flipped to r2; source bytes preserved (not deleted).
        $document->refresh();
        self::assertSame('r2', $document->disk);
        self::assertSame('r2', $document->getFirstMedia('kyc_documents')?->disk);
        Storage::disk('local')->assertExists($path);
    }

    public function test_failed_copy_leaves_source_metadata_unchanged(): void
    {
        $this->enableR2();
        $agencyId = $this->createAgencyId('MIGFAIL');
        $document = $this->createLocalDocument($agencyId, 'will-be-removed');
        $media = $document->getFirstMedia('kyc_documents');
        self::assertNotNull($media);

        // Remove the source object so the copy fails verification/read.
        Storage::disk('local')->delete($media->getPathRelativeToRoot());

        self::assertSame(1, Artisan::call('media:migrate-to-r2'));

        $operation = MediaStorageMigration::query()->latest('id')->firstOrFail();
        self::assertSame(MediaStorageMigration::STATUS_FAILED, $operation->status);
        self::assertSame(1, $operation->failed_count);
        self::assertSame(0, $operation->migrated_count);

        $document->refresh();
        self::assertSame('local', $document->disk, 'Failed copy must not change source disk metadata.');
        self::assertSame('local', $document->getFirstMedia('kyc_documents')?->disk);
    }

    public function test_rerunning_migration_skips_already_migrated_records(): void
    {
        $this->enableR2();
        $agencyId = $this->createAgencyId('MIGIDEM');
        $this->createLocalDocument($agencyId, 'idempotent-bytes');

        self::assertSame(0, Artisan::call('media:migrate-to-r2'));
        self::assertSame(0, Artisan::call('media:migrate-to-r2'));

        $second = MediaStorageMigration::query()->latest('id')->firstOrFail();
        self::assertSame(0, $second->total_candidates);
        self::assertSame(0, $second->migrated_count);
    }

    public function test_media_on_disallowed_source_disk_is_not_a_candidate(): void
    {
        $this->enableR2();
        $agencyId = $this->createAgencyId('MIGDSK');
        $candidate = $this->createLocalDocument($agencyId, 'candidate');

        // A second document whose media lives on a disk outside the allowed
        // source list must be ignored.
        $other = $this->createLocalDocument($agencyId, 'off-limits');
        $otherMedia = $other->getFirstMedia('kyc_documents');
        self::assertNotNull($otherMedia);
        DB::table('media')->where('id', $otherMedia->id)->update(['disk' => 's3']);

        self::assertSame(0, Artisan::call('media:migrate-to-r2'));

        $operation = MediaStorageMigration::query()->latest('id')->firstOrFail();
        self::assertSame(1, $operation->total_candidates);
        self::assertSame(1, $operation->migrated_count);
    }

    public function test_real_migration_aborts_when_r2_not_configured(): void
    {
        $agencyId = $this->createAgencyId('MIGNOCFG');
        $this->createLocalDocument($agencyId, 'payload');

        self::assertSame(1, Artisan::call('media:migrate-to-r2'));
        self::assertSame(0, MediaStorageMigration::query()->getQuery()->count(), 'No operation record should be created when aborting pre-flight.');
    }

    // --- R2-008 / R2-011: API endpoint -----------------------------------

    public function test_non_platform_role_cannot_request_migration(): void
    {
        $teller = $this->createUserWithRole('teller', $this->createAgency('MIGTEL'));

        $this->actingWith($teller)
            ->postJson('/api/v1/media-storage/migrations', ['dry_run' => true])
            ->assertForbidden();
    }

    public function test_platform_admin_can_request_dry_run_migration_via_api(): void
    {
        $agencyId = $this->createAgencyId('MIGAPI');
        $this->createLocalDocument($agencyId, 'api-payload');
        $admin = $this->createUserWithRole('platform-admin');

        $response = $this->actingWith($admin)
            ->postJson('/api/v1/media-storage/migrations', ['dry_run' => true]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('data.migration.dry_run', true);
        $response->assertJsonPath('data.migration.status', MediaStorageMigration::STATUS_COMPLETED);
        $publicId = $this->requireStringJsonPath($response, 'data.migration.public_id');

        // Listable and viewable.
        $this->actingWith($admin)->getJson('/api/v1/media-storage/migrations')->assertJsonPath('success', true);
        $this->actingWith($admin)->getJson("/api/v1/media-storage/migrations/{$publicId}")
            ->assertJsonPath('data.migration.public_id', $publicId);
    }

    public function test_real_migration_via_api_requires_r2_configured(): void
    {
        $admin = $this->createUserWithRole('platform-admin');

        $this->actingWith($admin)
            ->postJson('/api/v1/media-storage/migrations', ['dry_run' => false])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'r2_not_configured');
    }

    // --- Helpers ----------------------------------------------------------

    private function enableR2(): void
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

    private function createLocalDocument(int $agencyId, string $content): Document
    {
        $document = Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'category' => 'identity',
            'title' => 'Local Evidence',
            'status' => Document::STATUS_ACTIVE,
        ]);

        $media = $document->addMediaFromString($content)
            ->usingFileName('evidence.pdf')
            ->toMediaCollection('kyc_documents', 'local');

        $document->update([
            'disk' => $media->disk,
            'path' => $media->getPathRelativeToRoot(),
            'original_name' => $media->file_name,
            'mime_type' => $media->mime_type,
            'size_bytes' => $media->size,
            'checksum_sha256' => hash('sha256', $content),
        ]);

        return $document->refresh();
    }

    private function createAgencyId(string $code): int
    {
        return $this->createAgency($code)['id'];
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

    private function actingWith(User $actor): self
    {
        return $this->withApiHeaders([
            'Authorization' => 'Bearer '.$actor->createToken('mig-test')->plainTextToken,
        ]);
    }

    private function requireStringJsonPath(TestResponse $response, string $path): string
    {
        $value = $response->json($path);
        self::assertIsString($value);

        return $value;
    }
}
