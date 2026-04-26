<?php

declare(strict_types=1);

namespace App\Support\Finance;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class DateRange
{
    private function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
    ) {}

    public static function make(CarbonImmutable $startsAt, CarbonImmutable $endsAt): self
    {
        if ($endsAt->lessThan($startsAt)) {
            throw new InvalidArgumentException('Date range end cannot be before start.');
        }

        return new self($startsAt, $endsAt);
    }

    public function daysInclusive(): int
    {
        return (int) floor($this->startsAt->diffInDays($this->endsAt)) + 1;
    }
}
