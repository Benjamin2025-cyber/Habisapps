<?php

declare(strict_types=1);

namespace App\Support\Finance;

use Brick\Money\Money;
use InvalidArgumentException;

final readonly class MoneyAmount
{
    private function __construct(public Money $money) {}

    public static function of(int|string $amount, ?string $currency = null): self
    {
        $resolvedCurrency = $currency ?? self::defaultCurrency();

        return new self(Money::of((string) $amount, $resolvedCurrency));
    }

    public static function zero(?string $currency = null): self
    {
        return self::of('0', $currency);
    }

    public function currency(): string
    {
        return (string) $this->money->getCurrency();
    }

    public function amount(): string
    {
        return (string) $this->money->getAmount();
    }

    public function plus(self $other): self
    {
        $this->ensureSameCurrency($other);

        return new self($this->money->plus($other->money));
    }

    public function minus(self $other): self
    {
        $this->ensureSameCurrency($other);

        return new self($this->money->minus($other->money));
    }

    public function isEqualTo(self $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->money->getAmount()->isEqualTo($other->money->getAmount());
    }

    public function isZero(): bool
    {
        return $this->money->getAmount()->isZero();
    }

    private function ensureSameCurrency(self $other): void
    {
        if ($this->currency() !== $other->currency()) {
            throw new InvalidArgumentException(sprintf(
                'Currency mismatch: expected %s, got %s.',
                $this->currency(),
                $other->currency()
            ));
        }
    }

    private static function defaultCurrency(): string
    {
        $currency = config('money.default_currency', 'XAF');

        return is_string($currency) && $currency !== '' ? $currency : 'XAF';
    }
}
