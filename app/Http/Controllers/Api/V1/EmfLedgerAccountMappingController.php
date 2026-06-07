<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreEmfLedgerAccountMappingRequest;
use App\Http\Requests\UpdateEmfLedgerAccountMappingRequest;
use App\Http\Resources\EmfLedgerAccountMappingCollection;
use App\Http\Resources\EmfLedgerAccountMappingResource;
use App\Models\EmfLedgerAccountMapping;
use App\Models\EmfRegulatoryAccount;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EmfLedgerAccountMappingController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{emf_ledger_account_mappings: array<int, \App\Http\Resources\EmfLedgerAccountMappingResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): EmfLedgerAccountMappingCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', EmfLedgerAccountMapping::class)) {
            return $this->respondForbidden();
        }

        $query = EmfLedgerAccountMapping::query()
            ->with(['emfRegulatoryAccount', 'ledgerAccount.agency'])
            ->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('ledger_account_public_id')) {
            $ledgerAccount = LedgerAccount::query()->where('public_id', $request->string('ledger_account_public_id'))->first();
            if (! $ledgerAccount instanceof LedgerAccount) {
                return $this->respondUnprocessable(errors: ['ledger_account_public_id' => [__('The selected ledger account is invalid.')]]);
            }

            $query->where('ledger_account_id', $ledgerAccount->id);
        }

        if ($request->filled('emf_regulatory_account_public_id')) {
            $emfAccount = EmfRegulatoryAccount::query()->where('public_id', $request->string('emf_regulatory_account_public_id'))->first();
            if (! $emfAccount instanceof EmfRegulatoryAccount) {
                return $this->respondUnprocessable(errors: ['emf_regulatory_account_public_id' => [__('The selected EMF regulatory account is invalid.')]]);
            }

            $query->where('emf_regulatory_account_id', $emfAccount->id);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('status', 'ilike', '%'.$term.'%')
                    ->orWhereHas('emfRegulatoryAccount', static function (Builder $emfBuilder) use ($term): void {
                        $emfBuilder->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('name', 'ilike', '%'.$term.'%');
                    })
                    ->orWhereHas('ledgerAccount', static function (Builder $ledgerBuilder) use ($term): void {
                        $ledgerBuilder->where('code', 'ilike', '%'.$term.'%')
                            ->orWhere('name', 'ilike', '%'.$term.'%');
                    });
            });
        }

        return new EmfLedgerAccountMappingCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{emf_ledger_account_mapping: \App\Http\Resources\EmfLedgerAccountMappingResource}, errors: null, meta: null}')]
    public function store(StoreEmfLedgerAccountMappingRequest $request): JsonResponse
    {
        $emfAccount = EmfRegulatoryAccount::query()
            ->where('public_id', $request->string('emf_regulatory_account_public_id'))
            ->first();
        $ledgerAccount = LedgerAccount::query()
            ->where('public_id', $request->string('ledger_account_public_id'))
            ->first();

        if (! $emfAccount instanceof EmfRegulatoryAccount || $emfAccount->status !== EmfRegulatoryAccount::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['emf_regulatory_account_public_id' => [__('The selected EMF regulatory account must be active.')]]);
        }
        if (! $ledgerAccount instanceof LedgerAccount || $ledgerAccount->status !== LedgerAccount::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['ledger_account_public_id' => [__('The selected ledger account must be active.')]]);
        }

        $duplicateExists = DB::table('emf_ledger_account_mappings')
            ->where('emf_regulatory_account_id', $emfAccount->id)
            ->where('ledger_account_id', $ledgerAccount->id)
            ->exists();
        if ($duplicateExists) {
            return $this->respondUnprocessable('EMF ledger account mapping already exists.');
        }

        try {
            $mapping = EmfLedgerAccountMapping::query()->create([
                'public_id' => (string) Str::ulid(),
                'emf_regulatory_account_id' => $emfAccount->id,
                'ledger_account_id' => $ledgerAccount->id,
                'status' => $request->input('status', EmfLedgerAccountMapping::STATUS_ACTIVE),
            ]);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) === '23505') {
                return $this->respondUnprocessable('EMF ledger account mapping already exists.');
            }

            throw $exception;
        }

        $this->securityAudit->record('emf.ledger_account_mapping.created', actor: $request->user(), subject: $mapping, properties: [
            'emf_regulatory_account_public_id' => $emfAccount->public_id,
            'ledger_account_public_id' => $ledgerAccount->public_id,
        ], request: $request);

        return $this->respondCreated(
            EmfLedgerAccountMappingResource::make($mapping->loadMissing(['emfRegulatoryAccount', 'ledgerAccount.agency'])),
            'EMF ledger account mapping created successfully'
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{emf_ledger_account_mapping: \App\Http\Resources\EmfLedgerAccountMappingResource}, errors: null, meta: null}')]
    public function show(Request $request, EmfLedgerAccountMapping $emfLedgerAccountMapping): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $emfLedgerAccountMapping)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(
            EmfLedgerAccountMappingResource::make($emfLedgerAccountMapping->loadMissing(['emfRegulatoryAccount', 'ledgerAccount.agency']))
        );
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{emf_ledger_account_mapping: \App\Http\Resources\EmfLedgerAccountMappingResource}, errors: null, meta: null}')]
    public function update(UpdateEmfLedgerAccountMappingRequest $request, EmfLedgerAccountMapping $emfLedgerAccountMapping): JsonResponse
    {
        $emfLedgerAccountMapping->fill($request->validated())->save();

        $this->securityAudit->record('emf.ledger_account_mapping.updated', actor: $request->user(), subject: $emfLedgerAccountMapping, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(
            EmfLedgerAccountMappingResource::make($emfLedgerAccountMapping->refresh()->loadMissing(['emfRegulatoryAccount', 'ledgerAccount.agency'])),
            'EMF ledger account mapping updated successfully'
        );
    }

    public function destroy(Request $request, EmfLedgerAccountMapping $emfLedgerAccountMapping): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $emfLedgerAccountMapping)) {
            return $this->respondForbidden();
        }

        $emfLedgerAccountMapping->update(['status' => EmfLedgerAccountMapping::STATUS_ARCHIVED]);
        $this->securityAudit->record('emf.ledger_account_mapping.archived', actor: $actor, subject: $emfLedgerAccountMapping, request: $request);

        return $this->respondSuccess(message: 'EMF ledger account mapping archived successfully');
    }
}
