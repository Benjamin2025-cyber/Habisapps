<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DatabaseRestoreOperation;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DatabaseRestoreOperation
 */
final class DatabaseRestoreOperationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DatabaseRestoreOperation $operation */
        $operation = $this->resource;

        $metadata = is_array($operation->metadata) ? $operation->metadata : [];

        return [
            'public_id' => $operation->public_id,
            'status' => $operation->status,
            'target' => $operation->target,
            'mode' => $operation->mode,
            'confirmation_method' => $operation->confirmation_method,
            'destructive' => (bool) ($metadata['destructive'] ?? false),
            // Link backup metadata by public id + checksum, never raw paths.
            'backup_public_id' => $operation->relationLoaded('backup') ? $operation->backup?->public_id : null,
            'backup_checksum_sha256' => $operation->relationLoaded('backup') ? $operation->backup?->checksum_sha256 : null,
            'pre_restore_backup_public_id' => $operation->relationLoaded('preRestoreBackup') ? $operation->preRestoreBackup?->public_id : null,
            'started_at' => $this->formatDate($operation->started_at),
            'completed_at' => $this->formatDate($operation->completed_at),
            'expires_at' => $this->formatDate($operation->expires_at),
            'failure_reason' => $operation->failure_reason,
            'created_at' => $this->formatDate($operation->created_at),
            'updated_at' => $this->formatDate($operation->updated_at),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : null;
    }
}
