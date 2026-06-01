<?php

declare(strict_types=1);

namespace App\Support\AccountingDay;

/**
 * Outcome of evaluating whether an accounting day can be closed.
 *
 * @phpstan-type Blocker array{control: string, message: string, count?: int}
 */
final class CloseReadinessResult
{
    /**
     * @param  array<int, array{control: string, message: string, count?: int}>  $blockers
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly bool $ready,
        public readonly array $blockers = [],
        public readonly array $summary = [],
    ) {}

    public function isReady(): bool
    {
        return $this->ready && $this->blockers === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ready' => $this->isReady(),
            'blockers' => array_values($this->blockers),
            'summary' => $this->summary,
        ];
    }
}
