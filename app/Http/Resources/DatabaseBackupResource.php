<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DatabaseBackup;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseBackup
 */
final class DatabaseBackupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DatabaseBackup $backup */
        $backup = $this->resource;

        return [
            'public_id' => $backup->public_id,
            'filename' => $backup->filename,
            // Logical disk name only — the raw storage path is never exposed.
            'disk' => $backup->disk,
            'status' => $backup->status,
            'database_connection' => $backup->database_connection,
            'database_driver' => $backup->database_driver,
            'size_bytes' => $backup->size_bytes,
            'checksum_sha256' => $backup->checksum_sha256,
            'encrypted' => $backup->encrypted,
            'compression' => $backup->compression,
            'verification_status' => $backup->verification_status,
            'verified_at' => $this->formatDate($backup->verified_at),
            'started_at' => $this->formatDate($backup->started_at),
            'completed_at' => $this->formatDate($backup->completed_at),
            'expires_at' => $this->formatDate($backup->expires_at),
            'failure_reason' => $backup->failure_reason,
            'metadata' => $this->safeMetadata($backup->metadata),
            'is_downloadable' => $backup->isDownloadable(),
            'created_at' => $this->formatDate($backup->created_at),
            'updated_at' => $this->formatDate($backup->updated_at),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function safeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $safe = [];
        foreach ($metadata as $key => $value) {
            if ($this->isSensitiveMetadataKey($key)) {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    private function isSensitiveMetadataKey(string $key): bool
    {
        return preg_match('/(path|dsn|password|secret|token|key|command|output|stderr|stdout)/i', $key) === 1;
    }
}
