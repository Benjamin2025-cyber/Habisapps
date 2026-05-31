<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreOperationAccountMappingRequest;
use App\Http\Requests\UpdateOperationAccountMappingRequest;
use App\Http\Resources\OperationAccountMappingCollection;
use App\Http\Resources\OperationAccountMappingResource;
use App\Models\LedgerAccount;
use App\Models\OperationAccountMapping;
use App\Models\OperationCode;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class OperationAccountMappingController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{operation_account_mappings: array<int, \App\Http\Resources\OperationAccountMappingResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): OperationAccountMappingCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', OperationAccountMapping::class)) {
            return $this->respondForbidden();
        }

        $query = OperationAccountMapping::query()
            ->with(['operationCode', 'debitLedgerAccount', 'creditLedgerAccount'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('currency', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhereHas('operationCode', function (Builder $codeQuery) use ($term): void {
                        $codeQuery
                            ->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('label', 'ilike', '%'.$term.'%');
                    })
                    ->orWhereHas('debitLedgerAccount', function (Builder $ledgerQuery) use ($term): void {
                        $ledgerQuery
                            ->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('name', 'ilike', '%'.$term.'%');
                    })
                    ->orWhereHas('creditLedgerAccount', function (Builder $ledgerQuery) use ($term): void {
                        $ledgerQuery
                            ->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('name', 'ilike', '%'.$term.'%');
                    });
            });
        }

        return new OperationAccountMappingCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{operation_account_mapping: \App\Http\Resources\OperationAccountMappingResource}, errors: null, meta: null}')]
    public function store(StoreOperationAccountMappingRequest $request): JsonResponse
    {
        $operationCode = OperationCode::query()->where('public_id', $request->string('operation_code_public_id'))->first();
        if (! $operationCode instanceof OperationCode || $operationCode->status !== OperationCode::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['operation_code_public_id' => ['The selected operation code must be active.']]);
        }

        $debit = $this->resolveActiveLedgerAccount($request->input('debit_ledger_account_public_id'));
        if ($debit === false) {
            return $this->respondUnprocessable(errors: ['debit_ledger_account_public_id' => ['The selected debit ledger account must be active.']]);
        }
        $credit = $this->resolveActiveLedgerAccount($request->input('credit_ledger_account_public_id'));
        if ($credit === false) {
            return $this->respondUnprocessable(errors: ['credit_ledger_account_public_id' => ['The selected credit ledger account must be active.']]);
        }
        if ($debit instanceof LedgerAccount && $credit instanceof LedgerAccount && $debit->agency_id !== $credit->agency_id) {
            return $this->respondUnprocessable('Debit and credit ledger accounts must belong to the same agency.');
        }

        $mapping = OperationAccountMapping::query()->create([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $operationCode->id,
            'debit_ledger_account_id' => $debit instanceof LedgerAccount ? $debit->id : null,
            'credit_ledger_account_id' => $credit instanceof LedgerAccount ? $credit->id : null,
            'currency' => $request->filled('currency') ? strtoupper($request->string('currency')->toString()) : null,
            'status' => $request->input('status', OperationAccountMapping::STATUS_ACTIVE),
            'rules' => $request->input('rules'),
        ]);

        $this->securityAudit->record('operation.account_mapping.created', actor: $request->user(), subject: $mapping, properties: [
            'operation_code_public_id' => $operationCode->public_id,
        ], request: $request);

        return $this->respondCreated(
            OperationAccountMappingResource::make($mapping->loadMissing(['operationCode', 'debitLedgerAccount', 'creditLedgerAccount'])),
            'Operation account mapping created successfully'
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{operation_account_mapping: \App\Http\Resources\OperationAccountMappingResource}, errors: null, meta: null}')]
    public function show(Request $request, OperationAccountMapping $operationAccountMapping): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $operationAccountMapping)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(OperationAccountMappingResource::make($operationAccountMapping->loadMissing(['operationCode', 'debitLedgerAccount', 'creditLedgerAccount'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{operation_account_mapping: \App\Http\Resources\OperationAccountMappingResource}, errors: null, meta: null}')]
    public function update(UpdateOperationAccountMappingRequest $request, OperationAccountMapping $operationAccountMapping): JsonResponse
    {
        $operationAccountMapping->fill($request->validated())->save();

        $this->securityAudit->record('operation.account_mapping.updated', actor: $request->user(), subject: $operationAccountMapping, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(
            OperationAccountMappingResource::make($operationAccountMapping->refresh()->loadMissing(['operationCode', 'debitLedgerAccount', 'creditLedgerAccount'])),
            'Operation account mapping updated successfully'
        );
    }

    public function destroy(Request $request, OperationAccountMapping $operationAccountMapping): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $operationAccountMapping)) {
            return $this->respondForbidden();
        }

        $operationAccountMapping->update(['status' => OperationAccountMapping::STATUS_ARCHIVED]);
        $this->securityAudit->record('operation.account_mapping.archived', actor: $actor, subject: $operationAccountMapping, request: $request);

        return $this->respondSuccess(message: 'Operation account mapping archived successfully');
    }

    private function resolveActiveLedgerAccount(mixed $publicId): LedgerAccount|false|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $account = LedgerAccount::query()->where('public_id', $publicId)->first();

        return $account instanceof LedgerAccount && $account->status === LedgerAccount::STATUS_ACTIVE ? $account : false;
    }
}
