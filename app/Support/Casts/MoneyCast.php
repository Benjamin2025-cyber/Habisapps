<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** @implements CastsAttributes<Money|null, Money|numeric|string> */
class MoneyCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }

        $currencyKey = $this->currencyKey($key);
        $currency = $attributes[$currencyKey] ?? null;
        $defaultCurrency = $this->defaultCurrency();

        $currencyStr = (is_string($currency) || is_int($currency))
            ? $currency
            : $defaultCurrency;

        $amountStr = match (true) {
            is_string($value) => $value,
            is_numeric($value) => (string) $value,
            default => '0',
        };

        try {
            return Money::of($amountStr, $currencyStr);
        } catch (UnknownCurrencyException) {
            return Money::of($amountStr, $defaultCurrency);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        $currencyKey = $this->currencyKey($key);
        $defaultCurrency = $this->defaultCurrency();

        if ($value instanceof Money) {
            return [
                $key => (string) $value->getAmount(),
                $currencyKey => (string) $value->getCurrency(),
            ];
        }

        if (is_numeric($value)) {
            $money = Money::of((string) $value, $defaultCurrency);

            return [
                $key => (string) $money->getAmount(),
                $currencyKey => (string) $money->getCurrency(),
            ];
        }

        throw new InvalidArgumentException(
            sprintf(
                'Unsupported value type for MoneyCast: %s. Expected Brick\Money\Money, numeric, or numeric string.',
                get_debug_type($value)
            )
        );
    }

    private function currencyKey(string $key): string
    {
        if (str_ends_with($key, '_amount')) {
            return substr($key, 0, -7).'_currency';
        }

        if (str_ends_with($key, '_subunit')) {
            return substr($key, 0, -7).'_currency';
        }

        return $key.'_currency';
    }

    private function defaultCurrency(): string
    {
        $value = config('money.default_currency', 'TZS');

        return is_string($value) ? $value : 'TZS';
    }
}
