<?php

declare(strict_types=1);

namespace App\Support\Finance;

final class PhysicalCashAmount
{
    public static function validMinorAmount(int $amountMinor, string $currency): bool
    {
        $divisor = self::minorUnitDivisor($currency);

        return $divisor <= 1 || $amountMinor % $divisor === 0;
    }

    public static function validationMessage(string $currency): string
    {
        $divisor = self::minorUnitDivisor($currency);
        if ($divisor <= 1) {
            return (string) __('cash_journal.physical_cash_amount_invalid');
        }

        return (string) __('cash_journal.physical_cash_whole_units', ['currency' => strtoupper($currency)]);
    }

    private static function minorUnitDivisor(string $currency): int
    {
        if (strtoupper($currency) !== 'XAF') {
            return 1;
        }

        $accountScale = self::intConfig('money.default_scale', 2);
        $physicalScale = self::intConfig('money.physical_cash_scale', 0);
        $difference = max(0, $accountScale - $physicalScale);

        $divisor = 1;
        for ($i = 0; $i < $difference; $i++) {
            $divisor *= 10;
        }

        return $divisor;
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? max(0, $value) : $default;
    }
}
