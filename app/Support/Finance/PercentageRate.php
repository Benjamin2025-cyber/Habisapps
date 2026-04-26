<?php

declare(strict_types=1);

namespace App\Support\Finance;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class PercentageRate
{
    private function __construct(private BigDecimal $percent) {}

    public static function of(string|int $percent): self
    {
        $value = BigDecimal::of((string) $percent);

        if ($value->isLessThan('0')) {
            throw new InvalidArgumentException('Percentage rate cannot be negative.');
        }

        return new self($value);
    }

    public function percent(): string
    {
        return (string) $this->percent;
    }

    public function decimal(): BigDecimal
    {
        return $this->percent->dividedBy('100');
    }
}
