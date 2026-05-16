<?php

declare(strict_types=1);

namespace App\Support\Finance;

use Brick\Math\BigDecimal;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money;
use InvalidArgumentException;

final readonly class MoneyAmount
{
    private function __construct(public Money $money) {}

    public static function of(int|string $amount, ?string $currency = null): self
    {
        $resolvedCurrency = $currency ?? self::defaultCurrency();

        return new self(Money::of((string) $amount, $resolvedCurrency, self::accountContext()));
    }

    public static function ofMinor(int|string $minorAmount, ?string $currency = null): self
    {
        $amount = BigDecimal::of($minorAmount)->withPointMovedLeft(self::accountScale());

        return self::of((string) $amount, $currency);
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

    public function minorAmount(): string
    {
        return (string) $this->money->getAmount()->withPointMovedRight(self::accountScale());
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

    private static function accountContext(): CustomContext
    {
        return new CustomContext(scale: self::accountScale());
    }

    private static function accountScale(): int
    {
        $scale = config('money.default_scale', 2);

        return is_int($scale) ? max(0, $scale) : 2;
    }
}
