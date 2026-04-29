<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BackfillDocumentMediaCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_attach_media_or_mutate_document_paths(): void
    {
        Storage::fake('local');

        $agencyId = $this->createAgency('BFDRY');
        $legacyPath = 'legacy/dry-run-proof.pdf';
        $payload = 'dry-run-payload-'.Str::random(16);
        Storage::disk('local')->put($legacyPath, $payload);

        $document = $this->createLegacyDocument($agencyId, $legacyPath, $payload);

        self::assertSame(0, Artisan::call('app:backfill-document-media', ['--dry-run' => true]));

        $document->refresh();
        self::assertFalse($document->hasMedia('kyc_documents'));
        self::assertSame('local', $document->disk);
        self::assertSame($legacyPath, $document->path);
        Storage::disk('local')->assertExists($legacyPath);
    }

    public function test_backfill_attaches_media_preserves_legacy_file_and_tags_batch_metadata(): void
    {
        Storage::fake('local');

        $agencyId = $this->createAgency('BFACT');
        $legacyPath = 'legacy/kyc-doc.pdf';
        $payload = 'backfill-payload-'.Str::random(16);
        Storage::disk('local')->put($legacyPath, $payload);

        $document = $this->createLegacyDocument($agencyId, $legacyPath, $payload);

        self::assertSame(0, Artisan::call('app:backfill-document-media'));

        $document->refresh();
        $media = $document->getFirstMedia('kyc_documents');

        self::assertNotNull($media);
        self::assertSame('local', $document->disk);
        self::assertNotSame($legacyPath, $document->path);
        self::assertSame($media->disk, $document->disk);
        self::assertSame($media->getPathRelativeToRoot(), $document->path);

        Storage::disk('local')->assertExists($legacyPath);
        Storage::disk('local')->assertExists($media->getPathRelativeToRoot());

        self::assertSame('local', $media->getCustomProperty('source_disk'));
        self::assertSame(hash('sha256', $legacyPath), $media->getCustomProperty('source_path_hash'));
        $batchId = $media->getCustomProperty('backfill_batch_id');
        self::assertIsString($batchId);
        self::assertNotSame('', $batchId);
    }

    public function test_backfill_reports_checksum_mismatch_without_creating_media(): void
    {
        Storage::fake('local');

        $agencyId = $this->createAgency('BFCHK');
        $legacyPath = 'legacy/mismatch.pdf';
        Storage::disk('local')->put($legacyPath, 'actual-content');

        $document = Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'uploaded_by_user_id' => null,
            'category' => 'kyc',
            'title' => 'Legacy Mismatch',
            'disk' => 'local',
            'path' => $legacyPath,
            'original_name' => 'mismatch.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => Storage::disk('local')->size($legacyPath),
            'checksum_sha256' => hash('sha256', 'different-content'),
            'status' => Document::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(1, Artisan::call('app:backfill-document-media'));

        $document->refresh();
        self::assertFalse($document->hasMedia('kyc_documents'));
        self::assertSame($legacyPath, $document->path);
        Storage::disk('local')->assertExists($legacyPath);
    }

    public function test_backfill_skips_documents_on_disallowed_source_disks(): void
    {
        Storage::fake('local');

        $agencyId = $this->createAgency('BFDSK');
        $document = Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'uploaded_by_user_id' => null,
            'category' => 'kyc',
            'title' => 'Legacy On Unsupported Disk',
            'disk' => 's3',
            'path' => 'legacy/unsupported.pdf',
            'original_name' => 'unsupported.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 128,
            'checksum_sha256' => hash('sha256', 'legacy'),
            'status' => Document::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertSame(0, Artisan::call('app:backfill-document-media'));
        self::assertStringContainsString('Skipped: 1', Artisan::output());

        $document->refresh();
        self::assertFalse($document->hasMedia('kyc_documents'));
        self::assertSame('s3', $document->disk);
    }

    private function createLegacyDocument(int $agencyId, string $legacyPath, string $payload): Document
    {
        return Document::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'uploaded_by_user_id' => null,
            'category' => 'kyc',
            'title' => 'Legacy KYC',
            'disk' => 'local',
            'path' => $legacyPath,
            'original_name' => basename($legacyPath),
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($payload),
            'checksum_sha256' => hash('sha256', $payload),
            'status' => Document::STATUS_ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createAgency(string $code): int
    {
        return DB::table('agencies')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'code' => $code,
            'name' => $code.' Agency',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
