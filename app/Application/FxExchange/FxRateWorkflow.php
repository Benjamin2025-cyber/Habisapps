<?php

declare(strict_types=1);

namespace App\Application\FxExchange;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FxRateWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function storeRateDraft(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'base_currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'quote_currency' => ['required', 'string', 'size:3', 'exists:currencies,code', 'different:base_currency'],
            'reference_rate' => ['required', 'numeric', 'gt:0'],
            'buy_margin_rate' => ['required', 'numeric', 'min:0'],
            'sell_margin_rate' => ['required', 'numeric', 'min:0'],
            'effective_on' => ['required', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:effective_on'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($validated, $request): object {
                $base = mb_strtoupper((string) $validated['base_currency']);
                $quote = mb_strtoupper((string) $validated['quote_currency']);
                if ($base !== 'XAF') {
                    throw new InvalidArgumentException('Currency exchange rates must settle against XAF in this module.');
                }
                if ($quote === 'XAF') {
                    throw new InvalidArgumentException('Quote currency must be a foreign currency.');
                }
                $this->assertActiveCurrency($base);
                $this->assertActiveCurrency($quote);

                $reference = (float) $validated['reference_rate'];
                $buyMargin = (float) $validated['buy_margin_rate'];
                $sellMargin = (float) $validated['sell_margin_rate'];

                $buyRate = $reference * (1 - $buyMargin);
                $sellRate = $reference * (1 + $sellMargin);
                if ($buyRate <= 0) {
                    throw new InvalidArgumentException('Buy margin cannot wipe out the reference rate.');
                }

                $this->assertNoActiveRateOverlap(
                    $base,
                    $quote,
                    (string) $validated['effective_on'],
                    $this->nullableString($validated['effective_to'] ?? null),
                    null,
                );

                $id = DB::table('exchange_rates')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'base_currency' => $base,
                    'quote_currency' => $quote,
                    'reference_rate' => $reference,
                    'buy_margin_rate' => $buyMargin,
                    'sell_margin_rate' => $sellMargin,
                    'buy_rate' => $buyRate,
                    'sell_rate' => $sellRate,
                    'effective_on' => (string) $validated['effective_on'],
                    'effective_to' => $this->nullableString($validated['effective_to'] ?? null),
                    'status' => 'draft',
                    'created_by_user_id' => $request->user()?->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('exchange_rates')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Exchange rate draft could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['exchange_rate' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('fx.rate.draft_created', actor: $actor, properties: [
            'rate_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->ratePayload($row), 'Exchange rate draft created successfully');
    }

    public function approveRate(Request $request, string $ratePublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($ratePublicId, $actor): object {
                $rate = DB::table('exchange_rates')
                    ->where('public_id', $ratePublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($rate)) {
                    throw new InvalidArgumentException('Exchange rate is invalid.');
                }
                if ($this->rowString($rate, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft rates can be approved.');
                }
                if ($this->rowInt($rate, 'created_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('Maker cannot approve their own rate draft.');
                }
                $this->assertActiveCurrency($this->rowString($rate, 'base_currency'));
                $this->assertActiveCurrency($this->rowString($rate, 'quote_currency'));
                $this->assertNoActiveRateOverlap(
                    $this->rowString($rate, 'base_currency'),
                    $this->rowString($rate, 'quote_currency'),
                    $this->rowString($rate, 'effective_on'),
                    $this->rowNullableString($rate, 'effective_to'),
                    $this->rowInt($rate, 'id'),
                );

                DB::table('exchange_rates')->where('id', $this->rowInt($rate, 'id'))->update([
                    'status' => 'active',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                $updated = DB::table('exchange_rates')->where('id', $this->rowInt($rate, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Approved rate could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['exchange_rate' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('fx.rate.approved', actor: $actor, properties: [
            'rate_public_id' => $this->rowString($row, 'public_id'),
        ], request: $request);

        return $this->respondSuccess($this->ratePayload($row), 'Exchange rate approved');
    }

    private function actor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin') ? $actor : null;
    }

    private function assertActiveCurrency(string $code): void
    {
        $currency = DB::table('currencies')
            ->where('code', mb_strtoupper($code))
            ->where('status', 'active')
            ->first(['code']);
        if (! is_object($currency)) {
            throw new InvalidArgumentException('Currency '.$code.' is not active for currency-exchange operations.');
        }
    }

    private function assertNoActiveRateOverlap(
        string $base,
        string $quote,
        string $effectiveOn,
        ?string $effectiveTo,
        ?int $ignoreRateId,
    ): void {
        $newEnd = $effectiveTo ?? '9999-12-31';
        $query = DB::table('exchange_rates')
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->where('status', 'active')
            ->where('effective_on', '<=', $newEnd)
            ->where(function ($builder) use ($effectiveOn): void {
                $builder->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $effectiveOn);
            });

        if ($ignoreRateId !== null) {
            $query->where('id', '<>', $ignoreRateId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException('An active rate already covers this currency pair and effective window.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ratePayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'base_currency' => $this->rowString($row, 'base_currency'),
            'quote_currency' => $this->rowString($row, 'quote_currency'),
            'reference_rate' => $this->rowNullableString($row, 'reference_rate'),
            'buy_rate' => $this->rowNullableString($row, 'buy_rate'),
            'sell_rate' => $this->rowNullableString($row, 'sell_rate'),
            'buy_margin_rate' => $this->rowNullableString($row, 'buy_margin_rate'),
            'sell_margin_rate' => $this->rowNullableString($row, 'sell_margin_rate'),
            'effective_on' => $this->rowNullableString($row, 'effective_on'),
            'effective_to' => $this->rowNullableString($row, 'effective_to'),
            'status' => $this->rowString($row, 'status'),
        ];
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
