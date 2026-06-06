<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement\Contracts;

use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreOperation;

/**
 * Abstraction over the mechanism that restores a database from a backup
 * artifact. Implementations honour the operation's mode (dry_run / verify_only
 * must never mutate the target). Feature tests bind a fake so no real database
 * is ever mutated (ADM-DB-007).
 */
interface DatabaseRestoreRunner
{
    public function run(DatabaseRestoreOperation $operation, DatabaseBackup $backup): void;
}
