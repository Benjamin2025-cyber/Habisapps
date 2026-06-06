<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement\Contracts;

use App\Models\DatabaseBackup;
use App\Support\DatabaseManagement\BackupRunResult;

/**
 * Abstraction over the mechanism that produces a database backup artifact.
 *
 * Implementations write the artifact to the backup's configured disk/path and
 * return descriptive metadata. Feature tests bind a fake so no real shell tool
 * is invoked (ADM-DB-003, Implementation Notes).
 */
interface DatabaseBackupRunner
{
    public function run(DatabaseBackup $backup): BackupRunResult;
}
