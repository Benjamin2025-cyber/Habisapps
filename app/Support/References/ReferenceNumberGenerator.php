<?php

declare(strict_types=1);

namespace App\Support\References;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ReferenceNumberGenerator
{
    public function reserve(string $key): string
    {
        $definition = $this->definition($key);

        return $this->reserveSeries($key, $definition['prefix'], $definition['padding']);
    }

    /**
     * Reserves from a series whose key is computed at runtime rather than
     * declared in config/reference_numbers.php.
     *
     * Cash vouchers restart their counter in every (document type, guichet,
     * year, month) bucket, so the set of keys is open-ended and grows with the
     * branch network and the calendar — there is nothing to pre-declare. The
     * caller supplies the shape; the locking and the increment stay here so
     * every series in the system is numbered the same way.
     *
     * @param  string  $prefix  Everything before the counter, e.g. `REC-G001-2026-09-`.
     * @param  int  $padding  Counter width, zero-padded.
     */
    public function reserveSeries(string $key, string $prefix, int $padding): string
    {
        if ($prefix === '' || $padding < 1) {
            throw new InvalidArgumentException(sprintf('Reference series [%s] is invalid.', $key));
        }

        $definition = ['prefix' => $prefix, 'padding' => $padding];

        return DB::transaction(function () use ($key, $definition): string {
            $sequence = DB::table('reference_sequences')
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            if ($sequence === null) {
                DB::table('reference_sequences')->insert([
                    'key' => $key,
                    'prefix' => $definition['prefix'],
                    'padding' => $definition['padding'],
                    'next_number' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $sequence = DB::table('reference_sequences')
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();
            }

            if (! is_object($sequence)
                || ! property_exists($sequence, 'prefix')
                || ! property_exists($sequence, 'padding')
                || ! property_exists($sequence, 'next_number')) {
                throw new InvalidArgumentException(sprintf('Reference sequence [%s] could not be reserved.', $key));
            }

            /** @var \stdClass $sequence */
            $prefix = (string) $sequence->prefix;
            $padding = (int) $sequence->padding;
            $nextNumber = (int) $sequence->next_number;
            $reference = $prefix.str_pad((string) $nextNumber, $padding, '0', STR_PAD_LEFT);

            DB::table('reference_sequences')
                ->where('key', $key)
                ->update([
                    'next_number' => $nextNumber + 1,
                    'updated_at' => now(),
                ]);

            return $reference;
        });
    }

    /**
     * @return array{prefix: string, padding: int}
     */
    private function definition(string $key): array
    {
        $definition = config('reference_numbers.sequences.'.$key);

        if (! is_array($definition)) {
            throw new InvalidArgumentException(sprintf('Reference sequence [%s] is not configured.', $key));
        }

        $prefix = $definition['prefix'] ?? null;
        $padding = $definition['padding'] ?? null;

        if (! is_string($prefix) || $prefix === '' || ! is_int($padding) || $padding < 1) {
            throw new InvalidArgumentException(sprintf('Reference sequence [%s] is invalid.', $key));
        }

        return [
            'prefix' => $prefix,
            'padding' => $padding,
        ];
    }
}
