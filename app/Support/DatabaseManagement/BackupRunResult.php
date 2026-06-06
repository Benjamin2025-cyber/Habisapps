<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

/**
 * Metadata produced by a backup runner once an artifact has been written to the
 * configured disk. Carries only non-secret descriptive values.
 */
final class BackupRunResult
{
    public function __construct(
        public readonly int $sizeBytes,
        public readonly string $checksumSha256,
        public readonly bool $encrypted,
        public readonly ?string $compression,
    ) {}
}
