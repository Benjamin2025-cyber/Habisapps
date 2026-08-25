<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Illuminate\Contracts\Validation\Validator;

/**
 * Keeps a denomination's stored value in step with the face value its code
 * declares.
 *
 * `value_minor` is at the account scale, so a 10 000 F note is 1 000 000. Typed
 * as 10 000 it is silently a 100 F piece, and nothing downstream can tell: the
 * amount is a positive integer and a whole number of francs, so every rule it
 * has to clear passes. The billetage then counts 100 notes of 10 000 as
 * 10 000 FCFA instead of 1 000 000 — off by exactly the scale, on the screen a
 * teller opens their day with.
 *
 * The seeded code format carries the answer: `XAF-10000-B` is a 10 000 F
 * banknote. When a code says so, the value must agree.
 */
trait ValidatesDenominationFaceValue
{
    /** Minor units per franc — `money.default_scale` is 2. */
    private const int MINOR_PER_UNIT = 100;

    protected function validateFaceValueAgainstCode(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $value = $this->input('value_minor');
            if (! is_numeric($value)) {
                return;
            }

            $valueMinor = (int) $value;
            if ($valueMinor % self::MINOR_PER_UNIT !== 0) {
                $validator->errors()->add('value_minor', (string) __('domain.denomination_value_must_be_whole_units'));

                return;
            }

            $code = $this->input('code');
            $face = is_string($code) ? $this->faceFromCode($code) : null;
            if ($face === null || $valueMinor === $face * self::MINOR_PER_UNIT) {
                return;
            }

            $validator->errors()->add('value_minor', (string) __('domain.denomination_value_must_match_code_face', [
                'face' => $face,
                'expected' => $face * self::MINOR_PER_UNIT,
            ]));
        });
    }

    private function faceFromCode(string $code): ?int
    {
        if (preg_match('/^[A-Z]{3}-(\d+)-[BC]$/', strtoupper($code), $matches) !== 1) {
            return null;
        }

        $face = (int) $matches[1];

        return $face > 0 ? $face : null;
    }
}
