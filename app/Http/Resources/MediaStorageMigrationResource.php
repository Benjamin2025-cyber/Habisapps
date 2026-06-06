<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MediaStorageMigration;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MediaStorageMigration
 */
final class MediaStorageMigrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MediaStorageMigration $migration */
        $migration = $this->resource;

        return [
            'public_id' => $migration->public_id,
            'source_disk' => $migration->source_disk,
            'target_disk' => $migration->target_disk,
            'status' => $migration->status,
            'dry_run' => $migration->dry_run,
            'total_candidates' => $migration->total_candidates,
            'processed_count' => $migration->processed_count,
            'migrated_count' => $migration->migrated_count,
            'failed_count' => $migration->failed_count,
            'total_bytes' => $migration->total_bytes,
            'failure_summary' => $migration->failure_summary,
            // Bounded per-item failures expose only document public ids and a
            // safe reason — never raw storage paths, bucket names, or keys.
            'failures' => $this->failureDetails($migration),
            'started_at' => $this->formatDate($migration->started_at),
            'completed_at' => $this->formatDate($migration->completed_at),
            'created_at' => $this->formatDate($migration->created_at),
        ];
    }

    /**
     * @return array<int, array{document: string|null, reason: string}>
     */
    private function failureDetails(MediaStorageMigration $migration): array
    {
        $metadata = $migration->metadata;
        if (! is_array($metadata) || ! isset($metadata['failures']) || ! is_array($metadata['failures'])) {
            return [];
        }

        $failures = [];
        foreach ($metadata['failures'] as $failure) {
            if (! is_array($failure)) {
                continue;
            }
            $document = $failure['document'] ?? null;
            $reason = $failure['reason'] ?? null;
            $failures[] = [
                'document' => is_string($document) ? $document : null,
                'reason' => is_string($reason) ? $reason : 'Unknown error.',
            ];
        }

        return $failures;
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format(DateTimeInterface::ATOM);
    }
}
