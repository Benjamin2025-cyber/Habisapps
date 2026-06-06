<?php

declare(strict_types=1);

namespace App\Support\Media;

use App\Application\Notifications\UserNotificationFeed;
use App\Models\Document;
use App\Models\MediaStorageMigration;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Security\SecurityAudit;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Copies regulated document media from allowed source disks to Cloudflare R2,
 * verifying byte-for-byte checksum equality before any metadata is updated.
 *
 * Invariants:
 *  - Original bytes are preserved exactly; nothing is optimized, recompressed,
 *    transformed, or metadata-stripped (the object is streamed verbatim).
 *  - Source files are never deleted by this service; retention cleanup is a
 *    separate, explicit operation.
 *  - Spatie media disk/path and the owning Document disk/path are updated only
 *    after the target checksum matches the source.
 *  - Re-running is idempotent: media already on the target disk is not a
 *    candidate, so completed work is skipped.
 */
final class MediaMigrationService
{
    private const string TARGET_DISK = MediaStorageDiskResolver::DISK_R2;

    private const string COLLECTION = 'kyc_documents';

    /** Cap on per-item failure detail retained in the operation metadata. */
    private const int MAX_FAILURE_DETAILS = 50;

    public function __construct(
        private readonly SecurityAudit $audit,
        private readonly UserNotificationFeed $notifications,
    ) {}

    /**
     * @return array<int, string>
     */
    public function allowedSourceDisks(): array
    {
        $configured = config('security.documents.backfill.allowed_source_disks', [MediaStorageDiskResolver::DISK_LOCAL]);
        if (! is_array($configured)) {
            $configured = [MediaStorageDiskResolver::DISK_LOCAL];
        }

        $disks = [];
        foreach ($configured as $disk) {
            // The target disk is never a valid *source*; that would be a no-op
            // copy and risks self-overwrite.
            if (is_string($disk) && $disk !== '' && $disk !== self::TARGET_DISK) {
                $disks[] = $disk;
            }
        }

        return $disks === [] ? [MediaStorageDiskResolver::DISK_LOCAL] : array_values(array_unique($disks));
    }

    /**
     * Count candidates and total bytes without touching any record.
     *
     * @return array{count: int, bytes: int}
     */
    public function candidateSummary(): array
    {
        $query = $this->candidateQuery()->getQuery();

        return [
            'count' => $query->count(),
            'bytes' => (int) $query->sum('size'),
        ];
    }

    /**
     * Execute (or dry-run) a migration, mutating the tracking operation in
     * place. The operation must already be persisted with source/target disk,
     * dry_run flag, and (optionally) requested_by_user_id set.
     */
    public function execute(MediaStorageMigration $operation): MediaStorageMigration
    {
        $actor = $operation->requested_by_user_id !== null
            ? User::query()->find($operation->requested_by_user_id)
            : null;

        $operation->forceFill([
            'status' => MediaStorageMigration::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        $this->audit->record('media.migration.started', actor: $actor, subject: $operation, properties: [
            'migration_public_id' => $operation->public_id,
            'dry_run' => $operation->dry_run,
            'target_disk' => self::TARGET_DISK,
        ]);

        // R2 must be fully configured to be a migration target. We do not need
        // it to be reachable for a dry-run (which is read-only against the
        // source), but a real copy does.
        if (! $operation->dry_run && ! MediaStorageDiskResolver::fromConfig()->isR2FullyConfigured()) {
            return $this->failOperation(
                $operation,
                $actor,
                'R2 is not fully configured; cannot migrate to the target disk.',
            );
        }

        $summary = $this->candidateSummary();
        $operation->forceFill([
            'total_candidates' => $summary['count'],
            'total_bytes' => $summary['bytes'],
        ])->save();

        if ($operation->dry_run) {
            $operation->forceFill([
                'status' => MediaStorageMigration::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            $this->audit->record('media.migration.completed', actor: $actor, subject: $operation, properties: [
                'migration_public_id' => $operation->public_id,
                'dry_run' => true,
                'total_candidates' => $summary['count'],
                'total_bytes' => $summary['bytes'],
            ]);

            return $operation->refresh();
        }

        $processed = 0;
        $migrated = 0;
        $failed = 0;
        /** @var array<int, array{document: string|null, reason: string}> $failures */
        $failures = [];

        foreach ($this->candidateQuery()->cursor() as $media) {
            /** @var Media $media */
            $processed++;
            $result = $this->migrateOne($media, $actor, $operation);
            if ($result === null) {
                $migrated++;

                continue;
            }

            $failed++;
            if (count($failures) < self::MAX_FAILURE_DETAILS) {
                $failures[] = $result;
            }
        }

        $hadFailures = $failed > 0;
        $status = ($hadFailures && $migrated === 0 && $processed > 0)
            ? MediaStorageMigration::STATUS_FAILED
            : MediaStorageMigration::STATUS_COMPLETED;

        $metadata = $operation->metadata ?? [];
        if ($failures !== []) {
            $metadata['failures'] = $failures;
        }

        $operation->forceFill([
            'status' => $status,
            'processed_count' => $processed,
            'migrated_count' => $migrated,
            'failed_count' => $failed,
            'completed_at' => now(),
            'failure_summary' => $hadFailures
                ? "{$failed} of {$processed} item(s) failed to migrate."
                : null,
            'metadata' => $metadata === [] ? null : $metadata,
        ])->save();

        if ($status === MediaStorageMigration::STATUS_FAILED) {
            $this->audit->record('media.migration.failed', actor: $actor, subject: $operation, properties: [
                'migration_public_id' => $operation->public_id,
                'processed_count' => $processed,
                'failed_count' => $failed,
            ]);
            $this->notifyFailure($operation, "Media migration {$operation->public_id} failed: all {$processed} candidate(s) failed to copy to R2.");
        } else {
            $this->audit->record('media.migration.completed', actor: $actor, subject: $operation, properties: [
                'migration_public_id' => $operation->public_id,
                'processed_count' => $processed,
                'migrated_count' => $migrated,
                'failed_count' => $failed,
            ]);
            if ($hadFailures) {
                $this->notifyFailure($operation, "Media migration {$operation->public_id} completed with {$failed} failed item(s).");
            }
        }

        return $operation->refresh();
    }

    /**
     * Copy one media object to R2. Returns null on success, or a failure
     * descriptor (document public id + safe reason, never a storage path).
     *
     * @return array{document: string|null, reason: string}|null
     */
    private function migrateOne(Media $media, ?User $actor, MediaStorageMigration $operation): ?array
    {
        $document = $media->model instanceof Document ? $media->model : null;
        $documentPublicId = $document?->public_id;

        try {
            $sourceDisk = $media->disk;
            $path = $media->getPathRelativeToRoot();

            if (! in_array($sourceDisk, $this->allowedSourceDisks(), true)) {
                return $this->itemFailure($actor, $operation, $documentPublicId, 'Source disk is not an allowed migration source.');
            }

            $source = Storage::disk($sourceDisk);
            if (! $source->exists($path)) {
                return $this->itemFailure($actor, $operation, $documentPublicId, 'Source object is missing on its disk.');
            }

            $target = Storage::disk(self::TARGET_DISK);

            // Stream the object across disks verbatim — no buffering of the full
            // file in memory, no transformation. Then verify byte-for-byte via
            // streamed hashes of both sides before any metadata is touched.
            $readStream = $source->readStream($path);
            if (! is_resource($readStream)) {
                return $this->itemFailure($actor, $operation, $documentPublicId, 'Source object could not be read.');
            }
            $target->writeStream($path, $readStream);
            if (is_resource($readStream)) {
                fclose($readStream);
            }

            $sourceHash = $this->streamHash($source, $path);
            $targetHash = $this->streamHash($target, $path);

            if ($sourceHash === null || $targetHash === null || $targetHash !== $sourceHash) {
                // Verification failed: remove the bad copy and leave all source
                // metadata untouched.
                try {
                    $target->delete($path);
                } catch (Throwable) {
                    // best-effort cleanup
                }

                return $this->itemFailure($actor, $operation, $documentPublicId, 'Checksum verification failed after copy.');
            }

            // Verified — update metadata only now. The relative key is identical
            // on both disks, so document.path is unchanged.
            $media->forceFill(['disk' => self::TARGET_DISK])->save();
            if ($document instanceof Document) {
                $document->forceFill(['disk' => self::TARGET_DISK])->save();
            }

            return null;
        } catch (Throwable) {
            return $this->itemFailure($actor, $operation, $documentPublicId, 'Unexpected error during copy.');
        }
    }

    /**
     * Compute the SHA-256 of an object by streaming it in chunks, so the whole
     * file is never held in memory. Returns null if the object cannot be read.
     */
    private function streamHash(Filesystem $disk, string $path): ?string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        while (! feof($stream)) {
            $chunk = fread($stream, 1_048_576);
            if ($chunk === false) {
                fclose($stream);

                return null;
            }
            hash_update($context, $chunk);
        }
        fclose($stream);

        return hash_final($context);
    }

    /**
     * @return array{document: string|null, reason: string}
     */
    private function itemFailure(?User $actor, MediaStorageMigration $operation, ?string $documentPublicId, string $reason): array
    {
        $this->audit->record('media.migration.item_failed', actor: $actor, subject: $operation, properties: array_filter([
            'migration_public_id' => $operation->public_id,
            'document_public_id' => $documentPublicId,
            'reason' => $reason,
        ], static fn (mixed $value): bool => $value !== null));

        return ['document' => $documentPublicId, 'reason' => $reason];
    }

    private function failOperation(MediaStorageMigration $operation, ?User $actor, string $reason): MediaStorageMigration
    {
        $operation->forceFill([
            'status' => MediaStorageMigration::STATUS_FAILED,
            'completed_at' => now(),
            'failure_summary' => $reason,
        ])->save();

        $this->audit->record('media.migration.failed', actor: $actor, subject: $operation, properties: [
            'migration_public_id' => $operation->public_id,
            'reason' => $reason,
        ]);

        $this->notifyFailure($operation, "Media migration {$operation->public_id} failed: {$reason}");

        return $operation->refresh();
    }

    private function notifyFailure(MediaStorageMigration $operation, string $message): void
    {
        try {
            $this->notifications->notifyPlatform(
                type: UserNotification::TYPE_ERROR,
                category: 'media_storage',
                title: 'Media storage migration failed',
                message: $message,
                sourceType: MediaStorageMigration::class,
                sourcePublicId: $operation->public_id,
                metadata: [
                    'migration_public_id' => $operation->public_id,
                    'failed_count' => $operation->failed_count,
                ],
            );
        } catch (Throwable) {
            // Notification failure must never mask the migration outcome.
        }
    }

    /**
     * @return Builder<Media>
     */
    private function candidateQuery()
    {
        $query = Media::query()->where('collection_name', self::COLLECTION);
        // whereIn on the underlying query builder avoids the larastan
        // forwarded-method false positive on the Eloquent builder.
        $query->getQuery()->whereIn('disk', $this->allowedSourceDisks());

        return $query;
    }
}
