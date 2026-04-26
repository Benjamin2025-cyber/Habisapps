<?php

declare(strict_types=1);

namespace App\Support\Readiness;

final readonly class ReadinessCheckResult
{
    private function __construct(
        public string $key,
        public bool $passed,
        public string $message,
    ) {}

    public static function pass(string $key, string $message): self
    {
        return new self($key, true, $message);
    }

    public static function fail(string $key, string $message): self
    {
        return new self($key, false, $message);
    }
}
