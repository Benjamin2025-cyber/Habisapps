<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

use App\Models\DatabaseBackup;
use App\Support\DatabaseManagement\Contracts\DatabaseBackupRunner;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Produces a PostgreSQL backup with native `pg_dump`, invoked through Symfony
 * Process with an explicit argument array (never string concatenation, so a
 * malicious connection value can never inject a shell command).
 *
 * The artifact is dumped to a private temp file, checksummed, then streamed onto
 * the configured disk; an optional encrypted copy is pushed to a remote disk.
 * This class is bound in production but is swapped for a fake in feature tests,
 * so no real shell tool runs under test (Implementation Notes).
 */
final class NativePostgresBackupRunner implements DatabaseBackupRunner
{
    public function __construct(
        private readonly DatabaseManagementConfig $config,
        private readonly BackupArtifactStore $store,
    ) {}

    public function run(DatabaseBackup $backup): BackupRunResult
    {
        $connection = config('database.connections.'.$backup->database_connection);
        if (! is_array($connection)) {
            throw new RuntimeException('Unknown database connection for backup.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pgdump_');
        if ($tmp === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the backup.');
        }

        try {
            $result = Process::env($this->processEnvironment($connection))
                ->timeout(0)
                ->run($this->command($connection, $tmp));

            if (! $result->successful()) {
                // Never surface raw command output (may contain a DSN); the
                // job records a bounded, non-secret failure reason instead.
                throw new RuntimeException('pg_dump exited with a non-zero status.');
            }

            $handle = fopen($tmp, 'rb');
            if ($handle === false) {
                throw new RuntimeException('Unable to read the generated backup artifact.');
            }
            $this->store->disk($backup->disk)->writeStream($backup->path, $handle);
            if (is_resource($handle)) {
                fclose($handle);
            }

            $this->pushRemoteCopy($backup, $tmp);

            $size = $this->store->size($backup) ?? 0;
            $checksum = $this->store->checksum($backup) ?? '';

            return new BackupRunResult(
                sizeBytes: $size,
                checksumSha256: $checksum,
                encrypted: false,
                compression: $this->config->compression(),
            );
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
    private function command(array $connection, string $outputPath): array
    {
        return array_values(array_filter([
            $this->config->tool('pg_dump'),
            '--format=custom',
            '--no-owner',
            '--no-privileges',
            '--host='.$this->stringValue($connection, 'host', '127.0.0.1'),
            '--port='.$this->stringValue($connection, 'port', '5432'),
            '--username='.$this->stringValue($connection, 'username', 'postgres'),
            '--file='.$outputPath,
            $this->stringValue($connection, 'database', ''),
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

    private function pushRemoteCopy(DatabaseBackup $backup, string $localPath): void
    {
        $remoteDisk = $this->config->remoteDisk();
        if ($remoteDisk === null) {
            return;
        }

        $contents = file_get_contents($localPath);
        if ($contents === false) {
            return;
        }

        if ($this->config->encryptionEnabled()) {
            $contents = $this->encryptRemoteCopy($contents);
        }

        $this->store->disk($remoteDisk)->put($backup->path, $contents);
    }

    private function encryptRemoteCopy(string $contents): string
    {
        $key = $this->config->encryptionKey();
        if ($key === null) {
            throw new RuntimeException('Backup encryption key is not configured.');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('Backup encryption key is invalid.');
            }
            $key = $decoded;
        }

        $cipher = config('app.cipher', 'AES-256-CBC');

        return (new Encrypter($key, is_string($cipher) ? $cipher : 'AES-256-CBC'))->encryptString($contents);
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
