<?php

declare(strict_types=1);

namespace App\Support\DatabaseManagement;

/**
 * A non-fatal, operator-facing reason a database-management operation cannot
 * proceed (feature disabled, unsupported driver, misconfigured storage, etc).
 *
 * Carrying an explicit HTTP status lets workflows surface a clear 422/503
 * disabled-state response instead of a 500 (ADM-DB-001, ADM-DB-012).
 */
final class DatabaseManagementError
{
    public function __construct(
        public readonly int $status,
        public readonly string $code,
        public readonly string $message,
    ) {}
}
