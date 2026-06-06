<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

use App\Models\DatabaseBackup;
use App\Models\DatabaseRestoreOperation;
use App\Support\DatabaseManagement\Contracts\DatabaseRestoreRunner;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Restores a PostgreSQL database from a backup artifact using native
 * `pg_restore`, invoked through Symfony Process with an explicit argument array.
 *
 * Non-destructive modes (dry_run / verify_only) only list the archive table of
 * contents and never connect to or mutate a target database. This class is
 * swapped for a fake in feature tests so no real database is ever mutated
 * (ADM-DB-007).
 */
final class NativePostgresRestoreRunner implements DatabaseRestoreRunner
{
    public function __construct(
        private readonly DatabaseManagementConfig $config,
        private readonly BackupArtifactStore $store,
    ) {}

    public function run(DatabaseRestoreOperation $operation, DatabaseBackup $backup): void
    {
        $connection = config('database.connections.'.$backup->database_connection);
        if (! is_array($connection)) {
            throw new RuntimeException('Unknown database connection for restore.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pgrestore_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the restore.');
        }

        try {
            $stream = $this->store->readStream($backup);
            if ($stream === null) {
                throw new RuntimeException('Backup artifact is not readable.');
            }
            $target = fopen($tmp, 'wb');
            if ($target === false) {
                throw new RuntimeException('Unable to stage the backup artifact for restore.');
            }
            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            $result = Process::env($this->processEnvironment($connection))
                ->timeout(0)
                ->run($this->command($connection, $operation, $tmp));

            if (! $result->successful()) {
                throw new RuntimeException('pg_restore exited with a non-zero status.');
            }
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $connection
     * @return array<int, string>
     */
    private function command(array $connection, DatabaseRestoreOperation $operation, string $artifactPath): array
    {
        // Non-destructive modes only inspect the archive — never touch a target.
        if ($operation->mode !== DatabaseRestoreOperation::MODE_REPLACE) {
            return [$this->config->tool('pg_restore'), '--list', $artifactPath];
        }

        if ($operation->target !== DatabaseRestoreOperation::TARGET_SAME_DATABASE) {
            throw new RuntimeException('Replacement restore target is not supported by the native PostgreSQL runner.');
        }

        return array_values(array_filter([
            $this->config->tool('pg_restore'),
            '--clean',
            '--if-exists',
            '--no-owner',
            '--no-privileges',
            '--host='.$this->stringValue($connection, 'host', '127.0.0.1'),
            '--port='.$this->stringValue($connection, 'port', '5432'),
            '--username='.$this->stringValue($connection, 'username', 'postgres'),
            '--dbname='.$this->stringValue($connection, 'database', ''),
            $artifactPath,
        ], static fn (string $arg): bool => $arg !== ''));
    }

    /**
     * @param  array<array-key, mixed>  $connection
     * @return array<string, string>
     */
    private function processEnvironment(array $connection): array
    {
        $password = $this->stringValue($connection, 'password', '');

        return $password === '' ? [] : ['PGPASSWORD' => $password];
    }

    /**
     * @param  array<array-key, mixed>  $connection
     */
    private function stringValue(array $connection, string $key, string $default): string
    {
        $value = $connection[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }
}
