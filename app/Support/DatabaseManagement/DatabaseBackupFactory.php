<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

use App\Models\DatabaseBackup;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Creates a `pending` backup artifact row with a generated public id and a
 * relative (never absolute) storage path. Shared by the create-backup workflow
 * and the pre-restore safety backup so both build artifacts identically.
 */
final class DatabaseBackupFactory
{
    public function __construct(
        private readonly DatabaseManagementConfig $config,
        private readonly BackupArtifactStore $store,
    ) {}

    public function createPending(?User $actor): DatabaseBackup
    {
        $publicId = (string) Str::ulid();
        $location = $this->store->buildArtifactLocation($publicId, Carbon::now()->format('Ymd_His'));

        return DatabaseBackup::query()->create([
            'public_id' => $publicId,
            'filename' => $location['filename'],
            'disk' => $this->config->disk(),
            'path' => $location['path'],
            'status' => DatabaseBackup::STATUS_PENDING,
            'database_connection' => $this->config->connection(),
            'database_driver' => $this->config->driver(),
            'encrypted' => false,
            'compression' => $this->config->compression(),
            'created_by_user_id' => $actor?->id,
            'metadata' => [],
        ]);
    }
}
