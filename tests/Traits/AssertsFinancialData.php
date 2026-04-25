<?php

declare(strict_types=1);

namespace Tests\Traits;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Brick\Money\Money;

trait AssertsFinancialData
{
    protected function assertMoneyEquals(int|float|string $expected, Money $actual, ?string $currency = null): void
    {
        $expectedAmount = BigDecimal::of((string) $expected);
        $actualAmount = $actual->getAmount();

        $diff = $expectedAmount->minus($actualAmount)->abs();

        \PHPUnit\Framework\Assert::assertTrue(
            $diff->isLessThanOrEqualTo(BigDecimal::of('0.01')),
            sprintf(
                'Expected money amount %s but got %s %s.',
                $expectedAmount,
                $actualAmount,
                $actual->getCurrency()
            )
        );

        if ($currency !== null) {
            \PHPUnit\Framework\Assert::assertSame(
                strtoupper($currency),
                (string) $actual->getCurrency(),
                sprintf('Expected currency %s but got %s.', $currency, $actual->getCurrency())
            );
        }
    }

    protected function assertMoneySame(string $expected, Money $actual): void
    {
        \PHPUnit\Framework\Assert::assertTrue(
            $actual->getAmount()->isEqualTo($expected),
            sprintf(
                'Expected money amount %s but got %s.',
                $expected,
                $actual->getAmount()
            )
        );
    }

    /** @return array<string, array{mixed}> */
    public static function moneyDataset(): array
    {
        return [
            'zero' => ['0.00'],
            'small' => ['1.50'],
            'medium' => ['100.75'],
            'large' => ['1000000.00'],
            'high precision' => ['1234567.89'],
        ];
    }
}
