<?php

declare(strict_types=1);

namespace Tests\Support\DatabaseManagement;

use App\Models\DatabaseBackup;
use App\Support\DatabaseManagement\BackupRunResult;
use App\Support\DatabaseManagement\Contracts\DatabaseBackupRunner;
use Illuminate\Support\Facades\Storage;

/**
 * Test double that writes a small deterministic artifact to the (faked) disk and
 * returns its real size/checksum — so verification and download exercise the
 * genuine integrity path without invoking pg_dump.
 */
final class FakeDatabaseBackupRunner implements DatabaseBackupRunner
{
    public int $runs = 0;

    public function run(DatabaseBackup $backup): BackupRunResult
    {
        $this->runs++;

        $contents = 'FAKE-PG-DUMP::'.$backup->public_id;
        Storage::disk($backup->disk)->put($backup->path, $contents);

        return new BackupRunResult(
            sizeBytes: strlen($contents),
            checksumSha256: hash('sha256', $contents),
            encrypted: false,
            compression: 'gzip',
        );
    }
}
