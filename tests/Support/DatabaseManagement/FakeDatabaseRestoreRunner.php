<?php

declare(strict_types=1);

namespace Tests\Support\DatabaseManagement;

use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreOperation;
use App\Support\DatabaseManagement\Contracts\DatabaseRestoreRunner;

/**
 * Test double that records the restore call but never touches a real database,
 * proving feature tests cannot mutate live data (ADM-DB-007).
 *
 * @phpstan-type RecordedRestore array{operation_public_id: string, backup_public_id: string, mode: string, target: string}
 */
final class FakeDatabaseRestoreRunner implements DatabaseRestoreRunner
{
    /** @var array<int, array{operation_public_id: string, backup_public_id: string, mode: string, target: string}> */
    public array $calls = [];

    public bool $shouldFail = false;

    public function run(DatabaseRestoreOperation $operation, DatabaseBackup $backup): void
    {
        $this->calls[] = [
            'operation_public_id' => $operation->public_id,
            'backup_public_id' => $backup->public_id,
            'mode' => $operation->mode,
            'target' => $operation->target,
        ];

        if ($this->shouldFail) {
            throw new \RuntimeException('Simulated restore failure.');
        }
    }
}
