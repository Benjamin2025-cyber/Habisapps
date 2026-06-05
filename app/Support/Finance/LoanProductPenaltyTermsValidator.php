<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\LoanProduct;

final class LoanProductPenaltyTermsValidator
{
    private const array PERCENTAGE_FORMULA_TYPES = ['flat_rate', 'variable_rate', 'percentage'];

    /**
     * @param  array<string, mixed>|LoanProduct  $source
     * @return array<string, array<int, string>>
     */
    public static function errors(array|LoanProduct $source): array
    {
        $valueType = self::value($source, 'penalty_value_type');
        $value = self::value($source, 'penalty_value');
        $formulaBase = self::value($source, 'penalty_formula_base');
        $formulaType = self::value($source, 'penalty_formula_type');

        if (! self::isSet($valueType) && ! self::isSet($value) && ! self::isSet($formulaBase) && ! self::isSet($formulaType)) {
            return [];
        }

        $errors = [];

        if (! self::isSet($valueType)) {
            $errors['penalty_value_type'][] = 'A penalty value type is required when penalty terms are configured.';
        }

        if (! self::isSet($value)) {
            $errors['penalty_value'][] = 'A penalty value is required when penalty terms are configured.';
        }

        if ($valueType === 'amount' && self::isSet($formulaType) && $formulaType !== 'fixed') {
            $errors['penalty_formula_type'][] = 'An "amount" penalty value type requires the "fixed" formula type.';
        }

        if ($valueType === 'percentage') {
            if (! self::isSet($formulaBase)) {
                $errors['penalty_formula_base'][] = 'A penalty formula base is required for percentage penalties.';
            }

            if (self::isSet($formulaType) && ! in_array($formulaType, self::PERCENTAGE_FORMULA_TYPES, true)) {
                $errors['penalty_formula_type'][] = 'A "percentage" penalty value type requires a rate-based formula type (flat_rate, variable_rate, or percentage).';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>|LoanProduct  $source
     */
    private static function value(array|LoanProduct $source, string $key): mixed
    {
        if (is_array($source)) {
            return $source[$key] ?? null;
        }

        return match ($key) {
            'penalty_value_type' => $source->penalty_value_type,
            'penalty_value' => $source->penalty_value,
            'penalty_formula_base' => $source->penalty_formula_base,
            'penalty_formula_type' => $source->penalty_formula_type,
            default => null,
        };
    }

    private static function isSet(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }
}
