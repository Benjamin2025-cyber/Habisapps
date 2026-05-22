<?php

declare(strict_types=1);

namespace App\Application\HrPayroll;

use App\Http\Controllers\BaseController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class HrFormulaSetWorkflow extends BaseController
{
    public function storeFormulaSet(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:64'],
            'jurisdiction' => ['sometimes', 'string', 'size:2'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_from'],
            'regulatory_source_public_id' => ['required', 'string', 'exists:regulatory_sources,public_id'],
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.branch' => ['required', Rule::in(['pvid', 'family_benefits', 'occupational_risk', 'irpp', 'cac', 'other'])],
            'rates.*.sector' => ['sometimes', 'nullable', 'string', 'max:32'],
            'rates.*.payer' => ['required', Rule::in(['employer', 'employee'])],
            'rates.*.rate' => ['required', 'numeric', 'min:0', 'max:1'],
            'rates.*.ceiling_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rates.*.basis' => ['sometimes', 'nullable', 'string', 'max:32'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($validated, $actor): object {
                $code = (string) $validated['code'];
                $latest = DB::table('hr_payroll_formula_sets')->where('code', $code)->orderByDesc('version')->first(['version']);
                $version = is_object($latest) && is_numeric($latest->version) ? (int) $latest->version + 1 : 1;

                $sourceId = $this->idByPublicId('regulatory_sources', $validated['regulatory_source_public_id'] ?? null);
                if ($sourceId === null) {
                    throw new InvalidArgumentException('Payroll formula sets require a regulatory/legal source.');
                }

                $setId = DB::table('hr_payroll_formula_sets')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'code' => $code,
                    'version' => $version,
                    'jurisdiction' => is_string($validated['jurisdiction'] ?? null) && $validated['jurisdiction'] !== '' ? $validated['jurisdiction'] : 'cm',
                    'currency' => is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : 'XAF',
                    'effective_from' => (string) $validated['effective_from'],
                    'effective_to' => $this->nullableString($validated['effective_to'] ?? null),
                    'status' => 'draft',
                    'source_regulatory_source_id' => $sourceId,
                    'created_by_user_id' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $rates = is_array($validated['rates'] ?? null) ? $validated['rates'] : [];
                foreach ($rates as $rate) {
                    if (! is_array($rate)) {
                        continue;
                    }
                    $branch = is_string($rate['branch'] ?? null) ? $rate['branch'] : '';
                    $payer = is_string($rate['payer'] ?? null) ? $rate['payer'] : '';
                    $rateValue = is_numeric($rate['rate'] ?? null) ? (float) $rate['rate'] : 0.0;
                    DB::table('hr_payroll_formula_rates')->insert([
                        'hr_payroll_formula_set_id' => $setId,
                        'branch' => $branch,
                        'sector' => is_string($rate['sector'] ?? null) && $rate['sector'] !== '' ? $rate['sector'] : null,
                        'payer' => $payer,
                        'rate' => (string) $rateValue,
                        'ceiling_minor' => is_numeric($rate['ceiling_minor'] ?? null) ? (int) $rate['ceiling_minor'] : null,
                        'basis' => is_string($rate['basis'] ?? null) && $rate['basis'] !== '' ? $rate['basis'] : 'gross_salary',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $row = DB::table('hr_payroll_formula_sets')->where('id', $setId)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Formula set could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['hr_payroll_formula_set' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->formulaSetPayload($row), 'Payroll formula set created');
    }

    public function activateFormulaSet(Request $request, string $setPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($setPublicId, $actor): object {
                $set = DB::table('hr_payroll_formula_sets')->where('public_id', $setPublicId)->lockForUpdate()->first();
                if (! is_object($set)) {
                    throw new InvalidArgumentException('Formula set is invalid.');
                }
                if ($this->rowString($set, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft formula sets can be activated.');
                }
                if ($this->rowInt($set, 'created_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('Maker cannot approve their own formula set.');
                }

                DB::table('hr_payroll_formula_sets')
                    ->where('code', $this->rowString($set, 'code'))
                    ->where('status', 'active')
                    ->update(['status' => 'superseded', 'updated_at' => now()]);

                DB::table('hr_payroll_formula_sets')->where('id', $this->rowInt($set, 'id'))->update([
                    'status' => 'active',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                $updated = DB::table('hr_payroll_formula_sets')->where('id', $this->rowInt($set, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Formula set could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['hr_payroll_formula_set' => [$exception->getMessage()]]);
        }

        return $this->respondSuccess($this->formulaSetPayload($row), 'Payroll formula set activated');
    }

    /**
     * @return array<string, mixed>
     */
    private function formulaSetPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'version' => $this->rowInt($row, 'version'),
            'status' => $this->rowString($row, 'status'),
            'effective_from' => $this->rowNullableString($row, 'effective_from'),
            'effective_to' => $this->rowNullableString($row, 'effective_to'),
            'currency' => $this->rowString($row, 'currency'),
        ];
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    private function idByPublicId(string $table, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);

        return is_object($row) && is_numeric($row->id) ? (int) $row->id : null;
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
