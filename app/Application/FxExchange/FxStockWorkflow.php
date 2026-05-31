<?php

declare(strict_types=1);

namespace App\Application\FxExchange;

use App\Http\Controllers\BaseController;
use App\Models\Till;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class FxStockWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function storeStockMovement(Request $request, string $tillPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'movement_type' => ['required', Rule::in(['partner_replenishment', 'partner_sale', 'adjustment_correction'])],
            'currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'movement_date' => ['sometimes', 'nullable', 'date'],
            'counterparty_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($actor, $tillPublicId, $validated): object {
                $till = $this->lockTill($tillPublicId);
                $this->assertExchangeTill($till);
                $movementType = (string) $validated['movement_type'];
                $currency = mb_strtoupper((string) $validated['currency']);
                $this->assertActiveCurrency($currency);
                $amount = (int) $validated['amount_minor'];

                $movementDate = is_string($validated['movement_date'] ?? null) && $validated['movement_date'] !== ''
                    ? $validated['movement_date']
                    : now()->toDateString();

                $id = DB::table('fx_stock_movements')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $till->agency_id,
                    'till_id' => $till->id,
                    'currency' => $currency,
                    'movement_type' => $movementType,
                    'amount_minor' => $amount,
                    'movement_date' => $movementDate,
                    'counterparty_name' => $this->nullableString($validated['counterparty_name'] ?? null),
                    'status' => 'pending',
                    'requested_by_user_id' => $actor->id,
                    'notes' => $this->nullableString($validated['notes'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('fx_stock_movements')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Stock movement could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_stock_movement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('fx.stock_movement.posted', actor: $actor, properties: [
            'movement_public_id' => $this->rowString($row, 'public_id'),
            'till_public_id' => $tillPublicId,
        ], request: $request);

        return $this->respondCreated($this->stockMovementPayload($row), 'FX stock movement recorded');
    }

    public function approveStockMovement(Request $request, string $movementPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($actor, $movementPublicId): object {
                $movement = DB::table('fx_stock_movements')
                    ->where('public_id', $movementPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($movement)) {
                    throw new InvalidArgumentException('FX stock movement is invalid.');
                }
                if ($this->rowString($movement, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending FX stock movements can be approved.');
                }
                if ($this->rowInt($movement, 'requested_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('Requester cannot approve their own FX stock movement.');
                }

                $till = Till::query()->whereKey($this->rowInt($movement, 'till_id'))->first();
                if (! $till instanceof Till) {
                    throw new InvalidArgumentException('Till is invalid.');
                }
                $this->assertExchangeTill($till);

                $currency = $this->rowString($movement, 'currency');
                $this->assertActiveCurrency($currency);
                $balance = $this->lockOrCreateStockBalance($this->rowInt($movement, 'till_id'), $currency);
                $movementType = $this->rowString($movement, 'movement_type');
                $signature = $movementType === 'partner_replenishment' ? +1 : -1;
                $newBalance = $this->rowInt($balance, 'current_balance_minor') + ($signature * $this->rowInt($movement, 'amount_minor'));
                if ($newBalance < 0) {
                    throw new InvalidArgumentException('Stock movement would push foreign-currency stock negative.');
                }

                DB::table('till_currency_balances')->where('id', $this->rowInt($balance, 'id'))->update([
                    'current_balance_minor' => $newBalance,
                    'updated_at' => now(),
                ]);
                DB::table('fx_stock_movements')->where('id', $this->rowInt($movement, 'id'))->update([
                    'status' => 'posted',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('fx_stock_movements')->where('id', $this->rowInt($movement, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Stock movement could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_stock_movement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('fx.stock_movement.approved', actor: $actor, properties: [
            'movement_public_id' => $movementPublicId,
        ], request: $request);

        return $this->respondSuccess($this->stockMovementPayload($row), 'FX stock movement approved');
    }

    public function storeReconciliation(Request $request, string $tillPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'business_date' => ['required', 'date'],
            'currency' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'counted_minor' => ['required', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($actor, $tillPublicId, $validated): object {
                $till = $this->lockTill($tillPublicId);
                $this->assertExchangeTill($till);
                $currency = mb_strtoupper((string) $validated['currency']);
                $this->assertActiveCurrency($currency);
                $counted = (int) $validated['counted_minor'];

                $balance = $this->lockOrCreateStockBalance($till->id, $currency);
                $theoretical = $this->rowInt($balance, 'current_balance_minor');
                $variance = $counted - $theoretical;
                $status = $variance === 0 ? 'closed' : 'variance_blocked';

                $id = DB::table('fx_reconciliations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'till_id' => $till->id,
                    'agency_id' => $till->agency_id,
                    'business_date' => (string) $validated['business_date'],
                    'currency' => $currency,
                    'counted_minor' => $counted,
                    'theoretical_minor' => $theoretical,
                    'variance_minor' => $variance,
                    'status' => $status,
                    'notes' => $this->nullableString($validated['notes'] ?? null),
                    'closed_by_user_id' => $status === 'closed' ? $actor->id : null,
                    'closed_at' => $status === 'closed' ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($status === 'closed') {
                    DB::table('till_currency_balances')->where('id', $this->rowInt($balance, 'id'))->update([
                        'last_closing_balance_minor' => $counted,
                        'last_reconciled_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $row = DB::table('fx_reconciliations')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Reconciliation could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['fx_reconciliation' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('fx.reconciliation.recorded', actor: $actor, properties: [
            'reconciliation_public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
        ], request: $request);

        return $this->respondCreated($this->reconciliationPayload($row), 'FX reconciliation recorded');
    }

    public function register(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->query(), [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ])->validate();

        $query = DB::table('fx_transactions as tx')
            ->join('agencies as agency', 'agency.id', '=', 'tx.agency_id')
            ->whereBetween('tx.transaction_date', [(string) $validated['from'], (string) $validated['to']])
            ->orderBy('tx.transaction_date')
            ->orderBy('tx.id');

        $agencyId = $this->agencyIdByPublicId($validated['agency_public_id'] ?? null);
        if ($agencyId !== null) {
            $query->where('tx.agency_id', $agencyId);
        }
        if (is_string($validated['currency'] ?? null) && $validated['currency'] !== '') {
            $query->where('tx.foreign_currency', mb_strtoupper($validated['currency']));
        }

        $entries = $query->get([
            'tx.public_id',
            'tx.transaction_date',
            'tx.transaction_number',
            'tx.slip_number',
            'tx.register_number',
            'tx.direction',
            'tx.foreign_currency',
            'tx.foreign_amount_minor',
            'tx.local_currency',
            'tx.local_amount_minor',
            'tx.reference_rate',
            'tx.applied_rate',
            'tx.margin_rate',
            'tx.margin_amount_minor',
            'tx.client_name',
            'tx.client_identity_number',
            'tx.client_identity_type',
            'tx.client_identity_issuing_country',
            'tx.status',
            'agency.code as agency_code',
        ])->map(fn (object $row): array => $this->registerEntryPayload($row))->all();

        $search = $this->searchTerm($request);
        if ($search !== null) {
            $entries = array_values(array_filter($entries, fn (array $entry): bool => $this->registerEntryMatchesSearch($entry, $search)));
        }

        $pagination = $this->paginateArray($entries, $request, 100);

        return $this->respondSuccess([
            'from' => (string) $validated['from'],
            'to' => (string) $validated['to'],
            'entries' => $pagination['items'],
        ], 'FX register generated', ['pagination' => $pagination['pagination']]);
    }

    private function actor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin') ? $actor : null;
    }

    private function lockTill(string $publicId): Till
    {
        DB::table('tills')->where('public_id', $publicId)->lockForUpdate()->first(['id']);
        $till = Till::query()->where('public_id', $publicId)->first();
        if (! $till instanceof Till) {
            throw new InvalidArgumentException('Till is invalid.');
        }

        return $till;
    }

    private function assertExchangeTill(Till $till): void
    {
        if ($till->status !== Till::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Till must be active.');
        }
        if ($till->nature !== 'exchange') {
            throw new InvalidArgumentException('Currency exchange operations require a till with nature=exchange.');
        }
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

    private function lockOrCreateStockBalance(int $tillId, string $currency): object
    {
        $balance = DB::table('till_currency_balances')
            ->where('till_id', $tillId)
            ->where('currency', $currency)
            ->lockForUpdate()
            ->first();
        if (is_object($balance)) {
            return $balance;
        }

        $id = DB::table('till_currency_balances')->insertGetId([
            'till_id' => $tillId,
            'currency' => $currency,
            'opening_balance_minor' => 0,
            'current_balance_minor' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $balance = DB::table('till_currency_balances')->where('id', $id)->lockForUpdate()->first();
        if (! is_object($balance)) {
            throw new InvalidArgumentException('Stock balance row could not be reloaded.');
        }

        return $balance;
    }

    private function agencyIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $agency = DB::table('agencies')->where('public_id', $publicId)->first(['id']);

        return is_object($agency) && is_numeric($agency->id) ? (int) $agency->id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function stockMovementPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'movement_type' => $this->rowString($row, 'movement_type'),
            'currency' => $this->rowString($row, 'currency'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'movement_date' => $this->rowNullableString($row, 'movement_date'),
            'status' => $this->rowString($row, 'status'),
            'approved_at' => $this->rowNullableString($row, 'approved_at'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reconciliationPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'business_date' => $this->rowNullableString($row, 'business_date'),
            'currency' => $this->rowString($row, 'currency'),
            'counted_minor' => $this->rowInt($row, 'counted_minor'),
            'theoretical_minor' => $this->rowInt($row, 'theoretical_minor'),
            'variance_minor' => $this->rowInt($row, 'variance_minor'),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function registerEntryPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'agency_code' => $this->rowString($row, 'agency_code'),
            'transaction_date' => $this->rowNullableString($row, 'transaction_date'),
            'transaction_number' => $this->rowString($row, 'transaction_number'),
            'slip_number' => $this->rowNullableString($row, 'slip_number'),
            'register_number' => $this->rowNullableString($row, 'register_number'),
            'direction' => $this->rowString($row, 'direction'),
            'foreign_currency' => $this->rowString($row, 'foreign_currency'),
            'foreign_amount_minor' => $this->rowInt($row, 'foreign_amount_minor'),
            'local_currency' => $this->rowString($row, 'local_currency'),
            'local_amount_minor' => $this->rowInt($row, 'local_amount_minor'),
            'reference_rate' => $this->rowNullableString($row, 'reference_rate'),
            'applied_rate' => $this->rowNullableString($row, 'applied_rate'),
            'margin_rate' => $this->rowNullableString($row, 'margin_rate'),
            'margin_amount_minor' => $this->rowInt($row, 'margin_amount_minor'),
            'client_name' => $this->rowNullableString($row, 'client_name'),
            'client_identity_number' => $this->rowNullableString($row, 'client_identity_number'),
            'client_identity_type' => $this->rowNullableString($row, 'client_identity_type'),
            'client_identity_issuing_country' => $this->rowNullableString($row, 'client_identity_issuing_country'),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    private function searchTerm(Request $request): ?string
    {
        $search = $request->query('search');
        if (! is_string($search) || trim($search) === '') {
            return null;
        }

        return mb_strtolower(trim($search));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function registerEntryMatchesSearch(array $entry, string $search): bool
    {
        $haystack = mb_strtolower(implode(' ', array_map(
            static fn (mixed $value): string => is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
            $entry,
        )));

        return str_contains($haystack, $search);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    private function paginateArray(array $items, Request $request, int $defaultPerPage): array
    {
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max($request->integer('per_page', $defaultPerPage), 1), 100);
        $total = count($items);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($items, $offset, $perPage),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
            ],
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
