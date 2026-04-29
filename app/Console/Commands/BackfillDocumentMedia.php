<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

#[Signature('app:backfill-document-media {--dry-run : Simulate the backfill without making changes} {--strict : Stop on first error}')]
#[Description('Backfill existing document files into Media Library')]
class BackfillDocumentMedia extends Command
{
    private int $successCount = 0;

    private int $skippedCount = 0;

    private int $errorCount = 0;

    private string $targetDisk = 'local';

    /**
     * @var array<int, string>
     */
    private array $allowedSourceDisks = [];

    private string $batchId = '';

    /**
     * @var array<int, array{document: string, error: string}>
     */
    private array $errors = [];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $strict = $this->option('strict');
        $this->targetDisk = $this->resolveTargetDisk();
        $this->allowedSourceDisks = $this->resolveAllowedSourceDisks($this->targetDisk);
        $this->batchId = (string) Str::ulid();

        $this->info('Starting document media backfill...');
        if ($dryRun) {
            $this->warn('DRY-RUN MODE: No changes will be made.');
        }
        $this->line("Backfill batch ID: {$this->batchId}");
        $this->line("Target media disk: {$this->targetDisk}");
        $this->line('Allowed source disks: '.implode(', ', $this->allowedSourceDisks));

        $documents = Document::query()
            ->where('disk', '!=', '')
            ->where('path', '!=', '')
            ->where('checksum_sha256', '!=', '')
            ->whereDoesntHave('media', function ($query) {
                $query->where('collection_name', 'kyc_documents');
            })
            ->get();

        $total = $documents->count();
        $this->info("Found {$total} documents to backfill.");

        if ($total === 0) {
            $this->info('No documents require backfill.');

            return self::SUCCESS;
        }

        foreach ($documents as $document) {
            $this->processDocument($document, $dryRun);

            if ($strict && $this->errorCount > 0) {
                $this->error('Strict mode enabled: stopping on first error.');
                break;
            }
        }

        $this->reportResults();

        return $this->errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function processDocument(Document $document, bool $dryRun): void
    {
        $safeId = $this->maskIdentifier($document->public_id);

        try {
            $disk = $document->disk;
            $path = $document->path;
            $storedChecksum = $document->checksum_sha256;

            if (! is_string($disk) || $disk === '' || ! in_array($disk, $this->allowedSourceDisks, true)) {
                $this->addSkip($safeId, "Skipped document on disallowed disk: {$disk}");

                return;
            }

            if (! is_string($path) || $path === '') {
                $this->addError($safeId, 'Invalid source path');

                return;
            }

            if (! Storage::disk($disk)->exists($path)) {
                $this->addError($safeId, 'File not found in storage');

                return;
            }

            if ($disk === 'local' && ! $this->isPathWithinLocalPrivateRoot($path)) {
                $this->addError($safeId, 'File is outside private storage root');

                return;
            }

            $actualChecksum = $this->computeChecksum($disk, $path);

            if (! is_string($actualChecksum)) {
                $this->addError($safeId, 'Failed to compute file checksum');

                return;
            }

            if (! is_string($storedChecksum) || $storedChecksum === '' || $actualChecksum !== $storedChecksum) {
                $this->addError($safeId, 'Checksum mismatch: file may be corrupted');

                return;
            }

            if ($dryRun) {
                $this->line("  [DRY-RUN] Would attach media for document {$safeId}");
                $this->successCount++;

                return;
            }

            DB::transaction(function () use ($document, $disk, $path): void {
                $originalName = is_string($document->original_name) && $document->original_name !== ''
                    ? $document->original_name
                    : 'document';

                $media = $document->addMediaFromDisk($path, $disk)
                    ->preservingOriginal()
                    ->usingFileName($this->sanitizeFileName($originalName))
                    ->withCustomProperties([
                        'backfill_batch_id' => $this->batchId,
                        'source_disk' => $disk,
                        'source_path_hash' => hash('sha256', $path),
                    ])
                    ->toMediaCollection('kyc_documents', $this->targetDisk);

                $document->update([
                    'disk' => $media->disk,
                    'path' => $media->getPathRelativeToRoot(),
                    'original_name' => $media->file_name,
                    'mime_type' => $media->mime_type,
                    'size_bytes' => $media->size,
                ]);
            });

            $this->line("  ✓ Backfilled document {$safeId}");
            $this->successCount++;
        } catch (Throwable $e) {
            $this->addError($safeId, $e->getMessage());
        }
    }

    private function addError(string $safeId, string $message): void
    {
        $this->errorCount++;
        $this->errors[] = [
            'document' => $safeId,
            'error' => $message,
        ];
        $this->line("  ✗ Failed for document {$safeId}: {$message}");
    }

    private function addSkip(string $safeId, string $message): void
    {
        $this->skippedCount++;
        $this->line("  - {$message} ({$safeId})");
    }

    private function reportResults(): void
    {
        $this->newLine();
        $this->info('Backfill complete.');
        $this->line("  Success: {$this->successCount}");
        $this->line("  Skipped: {$this->skippedCount}");
        $this->line("  Errors: {$this->errorCount}");

        if ($this->errors !== []) {
            $this->newLine();
            $this->warn('Error details:');
            foreach ($this->errors as $error) {
                $this->line("  - Document {$error['document']}: {$error['error']}");
            }
        }
    }

    private function maskIdentifier(string $identifier): string
    {
        if (strlen($identifier) <= 8) {
            return '****';
        }

        return substr($identifier, 0, 4).'****'.substr($identifier, -4);
    }

    /**
     * @return array<int, string>
     */
    private function resolveAllowedSourceDisks(string $targetDisk): array
    {
        $configured = config('security.documents.backfill.allowed_source_disks', ['local']);

        if (! is_array($configured)) {
            $configured = ['local'];
        }

        $disks = [];
        foreach ($configured as $value) {
            if (is_string($value) && $value !== '') {
                $disks[] = $value;
            }
        }

        if ($disks === []) {
            $disks[] = 'local';
        }

        if (! in_array($targetDisk, $disks, true)) {
            $disks[] = $targetDisk;
        }

        return array_values(array_unique($disks));
    }

    private function resolveTargetDisk(): string
    {
        $disk = config('media-library.disk_name', 'local');

        if (! is_string($disk) || $disk === '') {
            return 'local';
        }

        return $disk;
    }

    private function isPathWithinLocalPrivateRoot(string $path): bool
    {
        $fullPath = Storage::disk('local')->path($path);
        $realPath = realpath($fullPath);
        $realRoot = realpath(Storage::disk('local')->path(''));

        if (! is_string($realPath) || ! is_string($realRoot)) {
            return false;
        }

        $normalizedRoot = rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $realPath === rtrim($realRoot, DIRECTORY_SEPARATOR)
            || str_starts_with($realPath, $normalizedRoot);
    }

    private function computeChecksum(string $disk, string $path): ?string
    {
        if ($disk === 'local') {
            $fullPath = Storage::disk('local')->path($path);
            $checksum = hash_file('sha256', $fullPath);

            return is_string($checksum) ? $checksum : null;
        }

        $content = Storage::disk($disk)->get($path);

        return is_string($content) ? hash('sha256', $content) : null;
    }

    private function sanitizeFileName(string $fileName): string
    {
        $name = pathinfo($fileName, PATHINFO_FILENAME);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $safeName = trim((string) $safeName, '-_.');
        if ($safeName === '') {
            $safeName = 'document';
        }

        $safeExtension = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $extension));

        return $safeExtension === '' ? $safeName : $safeName.'.'.$safeExtension;
    }
}
