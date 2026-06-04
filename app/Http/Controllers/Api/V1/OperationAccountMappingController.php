<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreOperationAccountMappingRequest;
use App\Http\Requests\UpdateOperationAccountMappingRequest;
use App\Http\Resources\OperationAccountMappingCollection;
use App\Http\Resources\OperationAccountMappingResource;
use App\Models\Agency;
use App\Models\LedgerAccount;
use App\Models\OperationAccountMapping;
use App\Models\OperationCode;
use App\Models\User;
use App\Support\Accounting\AgencyLedgerMappingResolver;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class OperationAccountMappingController extends BaseController
{
    /**
     * Mapping-backed loan posting operations evaluated by the readiness endpoint.
     *
     * @var array<int, array{code: string, module: string, leg: string}>
     */
    private const array READINESS_OPERATIONS = [
        ['code' => 'loan_principal_disbursement', 'module' => 'loan', 'leg' => AgencyLedgerMappingResolver::LEG_DEBIT],
        ['code' => 'loan_setup_dossier_fee', 'module' => 'loan', 'leg' => AgencyLedgerMappingResolver::LEG_CREDIT],
        ['code' => 'loan_setup_tax', 'module' => 'loan', 'leg' => AgencyLedgerMappingResolver::LEG_CREDIT],
        ['code' => 'loan_setup_guarantee_deposit', 'module' => 'loan', 'leg' => AgencyLedgerMappingResolver::LEG_CREDIT],
    ];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly AgencyLedgerMappingResolver $mappingResolver,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    /**
     * FBI2-031 — report whether the agency/currency has usable approved mappings
     * for each loan posting operation, categorising blockers (missing, inactive,
     * unapproved, expired, cross_agency, overlapping) so the UI can warn before a
     * user reaches disbursement.
     */
    #[QueryParameter('agency_public_id', 'Agency to check. Non-platform users may only check their current agency; defaults to it.', type: 'string')]
    #[QueryParameter('currency', 'ISO currency to check (default XAF).', type: 'string')]
    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{agency_public_id: string, currency: string, ready: bool, operations: array<int, array{operation_code: string, leg: string, status: string, resolvable: bool}>}, errors: null, meta: null}')]
    public function readiness(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', OperationAccountMapping::class)) {
            return $this->respondForbidden();
        }

        $requested = $request->input('agency_public_id');
        if (is_string($requested) && $requested !== '') {
            $agency = Agency::query()->where('public_id', $requested)->first();
            if (! $agency instanceof Agency) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency is invalid.']]);
            }
        } else {
            $currentAgencyId = $this->staffAgencyScope->currentAgencyId($actor);
            $agency = $currentAgencyId !== null ? Agency::query()->whereKey($currentAgencyId)->first() : null;
            if (! $agency instanceof Agency) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => ['An agency is required to check mapping readiness.']]);
            }
        }

        if (! $actor->hasRole('platform-admin') && $this->staffAgencyScope->currentAgencyId($actor) !== $agency->id) {
            return $this->respondForbidden('You can only check mapping readiness for your current agency.');
        }

        $currency = $request->filled('currency') ? strtoupper($request->string('currency')->toString()) : 'XAF';

        $operations = [];
        $ready = true;
        foreach (self::READINESS_OPERATIONS as $operation) {
            $resolution = $this->mappingResolver->resolve($operation['code'], $operation['module'], $agency->id, $currency, $operation['leg']);
            $resolvable = $resolution['status'] === AgencyLedgerMappingResolver::READY
                || $resolution['status'] === AgencyLedgerMappingResolver::OVERLAPPING;
            if (! $resolvable) {
                $ready = false;
            }
            $operations[] = [
                'operation_code' => $operation['code'],
                'leg' => $operation['leg'],
                'status' => $resolution['status'],
                'resolvable' => $resolvable,
            ];
        }

        return $this->respondSuccess([
            'agency_public_id' => $agency->public_id,
            'currency' => $currency,
            'ready' => $ready,
            'operations' => $operations,
        ]);
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{operation_account_mappings: array<int, \App\Http\Resources\OperationAccountMappingResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): OperationAccountMappingCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', OperationAccountMapping::class)) {
            return $this->respondForbidden();
        }

        $query = OperationAccountMapping::query()
            ->with(['operationCode', 'agency', 'debitLedgerAccount', 'creditLedgerAccount'])
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
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

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

        $agency = $this->resolveAgency($request->input('agency_public_id'));
        if ($agency === false) {
            return $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency is invalid.']]);
        }
        $agencyId = $agency instanceof Agency ? $agency->id : null;

        $ledgerAgencyError = $this->ledgerAgencyErrors($agencyId, $debit, $credit);
        if ($ledgerAgencyError !== null) {
            return $this->respondUnprocessable(errors: $ledgerAgencyError);
        }

        $status = is_string($request->input('status')) ? $request->string('status')->toString() : OperationAccountMapping::STATUS_ACTIVE;
        $approvalStatus = is_string($request->input('approval_status')) ? $request->string('approval_status')->toString() : OperationAccountMapping::APPROVAL_DRAFT;
        $currency = $request->filled('currency') ? strtoupper($request->string('currency')->toString()) : null;
        $effectiveFrom = $this->dateInput($request->input('effective_from'));
        $effectiveTo = $this->dateInput($request->input('effective_to'));

        if ($this->conflictsWithActiveApproved($status, $approvalStatus, $operationCode->id, $agencyId, $currency, $effectiveFrom, $effectiveTo, null)) {
            return $this->respondUnprocessable(errors: ['effective_from' => ['An overlapping active, approved mapping already exists for this operation code, agency, currency, and effective window.']]);
        }

        $approved = $approvalStatus === OperationAccountMapping::APPROVAL_APPROVED;
        $mapping = OperationAccountMapping::query()->create([
            'public_id' => (string) Str::ulid(),
            'operation_code_id' => $operationCode->id,
            'agency_id' => $agencyId,
            'debit_ledger_account_id' => $debit instanceof LedgerAccount ? $debit->id : null,
            'credit_ledger_account_id' => $credit instanceof LedgerAccount ? $credit->id : null,
            'currency' => $currency,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'status' => $status,
            'approval_status' => $approvalStatus,
            'accounting_owner_user_id' => $actor->id,
            'approved_by_user_id' => $approved ? $actor->id : null,
            'approved_at' => $approved ? now() : null,
            'rules' => $request->input('rules'),
        ]);

        $this->securityAudit->record('operation.account_mapping.created', actor: $actor, subject: $mapping, properties: [
            'operation_code_public_id' => $operationCode->public_id,
            'agency_id' => $agencyId,
            'approval_status' => $approvalStatus,
        ], request: $request);

        return $this->respondCreated(
            OperationAccountMappingResource::make($mapping->loadMissing(['operationCode', 'agency', 'debitLedgerAccount', 'creditLedgerAccount'])),
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

        return $this->respondSuccess(OperationAccountMappingResource::make($operationAccountMapping->loadMissing(['operationCode', 'agency', 'debitLedgerAccount', 'creditLedgerAccount'])));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{operation_account_mapping: \App\Http\Resources\OperationAccountMappingResource}, errors: null, meta: null}')]
    public function update(UpdateOperationAccountMappingRequest $request, OperationAccountMapping $operationAccountMapping): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = $request->validated();
        $changes = [];

        if (array_key_exists('agency_public_id', $validated)) {
            $agency = $this->resolveAgency($validated['agency_public_id']);
            if ($agency === false) {
                return $this->respondUnprocessable(errors: ['agency_public_id' => ['The selected agency is invalid.']]);
            }
            $changes['agency_id'] = $agency instanceof Agency ? $agency->id : null;
        }

        foreach (['debit_ledger_account_public_id' => 'debit_ledger_account_id', 'credit_ledger_account_public_id' => 'credit_ledger_account_id'] as $field => $column) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $ledger = $this->resolveActiveLedgerAccount($validated[$field]);
            if ($ledger === false) {
                return $this->respondUnprocessable(errors: [$field => ['The selected ledger account must be active.']]);
            }
            $changes[$column] = $ledger instanceof LedgerAccount ? $ledger->id : null;
        }

        if (array_key_exists('currency', $validated)) {
            $changes['currency'] = is_string($validated['currency']) && $validated['currency'] !== '' ? strtoupper($validated['currency']) : null;
        }
        foreach (['effective_from', 'effective_to'] as $field) {
            if (array_key_exists($field, $validated)) {
                $changes[$field] = $this->dateInput($validated[$field]);
            }
        }
        if (array_key_exists('status', $validated)) {
            $changes['status'] = $validated['status'];
        }
        if (array_key_exists('approval_status', $validated)) {
            $changes['approval_status'] = $validated['approval_status'];
            $approved = $validated['approval_status'] === OperationAccountMapping::APPROVAL_APPROVED;
            $changes['approved_by_user_id'] = $approved ? $actor->id : null;
            $changes['approved_at'] = $approved ? now() : null;
        }
        if (array_key_exists('rules', $validated)) {
            $changes['rules'] = $validated['rules'];
        }

        // Validate the resulting (merged) ledger/agency consistency and uniqueness.
        $resultingAgencyId = $this->intOrNull($changes['agency_id'] ?? $operationAccountMapping->agency_id);
        $debitLedger = $this->ledgerById($changes['debit_ledger_account_id'] ?? $operationAccountMapping->debit_ledger_account_id);
        $creditLedger = $this->ledgerById($changes['credit_ledger_account_id'] ?? $operationAccountMapping->credit_ledger_account_id);
        if ($debitLedger instanceof LedgerAccount && $creditLedger instanceof LedgerAccount && $debitLedger->agency_id !== $creditLedger->agency_id) {
            return $this->respondUnprocessable('Debit and credit ledger accounts must belong to the same agency.');
        }
        $ledgerAgencyError = $this->ledgerAgencyErrors($resultingAgencyId, $debitLedger, $creditLedger);
        if ($ledgerAgencyError !== null) {
            return $this->respondUnprocessable(errors: $ledgerAgencyError);
        }

        $resultingStatus = is_string($changes['status'] ?? null) ? $changes['status'] : $operationAccountMapping->status;
        $resultingApproval = is_string($changes['approval_status'] ?? null) ? $changes['approval_status'] : $operationAccountMapping->approval_status;
        $resultingCurrency = array_key_exists('currency', $changes) ? $changes['currency'] : $operationAccountMapping->currency;
        $resultingFrom = array_key_exists('effective_from', $changes) ? $changes['effective_from'] : $this->dateInput($operationAccountMapping->effective_from);
        $resultingTo = array_key_exists('effective_to', $changes) ? $changes['effective_to'] : $this->dateInput($operationAccountMapping->effective_to);
        if ($this->conflictsWithActiveApproved($resultingStatus, $resultingApproval, $operationAccountMapping->operation_code_id, $resultingAgencyId, $resultingCurrency, $resultingFrom, $resultingTo, $operationAccountMapping->id)) {
            return $this->respondUnprocessable(errors: ['effective_from' => ['An overlapping active, approved mapping already exists for this operation code, agency, currency, and effective window.']]);
        }

        $operationAccountMapping->fill($changes)->save();

        $this->securityAudit->record('operation.account_mapping.updated', actor: $actor, subject: $operationAccountMapping, properties: [
            'changed_fields' => array_keys($changes),
        ], request: $request);

        return $this->respondSuccess(
            OperationAccountMappingResource::make($operationAccountMapping->refresh()->loadMissing(['operationCode', 'agency', 'debitLedgerAccount', 'creditLedgerAccount'])),
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

    private function resolveAgency(mixed $publicId): Agency|false|null
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $agency = Agency::query()->where('public_id', $publicId)->first();

        return $agency instanceof Agency ? $agency : false;
    }

    private function ledgerById(mixed $id): ?LedgerAccount
    {
        if (! is_numeric($id)) {
            return null;
        }

        return LedgerAccount::query()->whereKey((int) $id)->first();
    }

    /**
     * @return array<string, array<int, string>>|null
     */
    private function ledgerAgencyErrors(?int $agencyId, LedgerAccount|false|null $debit, LedgerAccount|false|null $credit): ?array
    {
        if ($agencyId === null) {
            return null;
        }

        $errors = [];
        if ($debit instanceof LedgerAccount && $debit->agency_id !== $agencyId) {
            $errors['debit_ledger_account_public_id'] = ['The debit ledger account must belong to the mapping agency.'];
        }
        if ($credit instanceof LedgerAccount && $credit->agency_id !== $agencyId) {
            $errors['credit_ledger_account_public_id'] = ['The credit ledger account must belong to the mapping agency.'];
        }

        return $errors === [] ? null : $errors;
    }

    private function conflictsWithActiveApproved(string $status, string $approvalStatus, int $operationCodeId, ?int $agencyId, ?string $currency, ?string $from, ?string $to, ?int $exceptId): bool
    {
        if ($status !== OperationAccountMapping::STATUS_ACTIVE || $approvalStatus !== OperationAccountMapping::APPROVAL_APPROVED) {
            return false;
        }

        $query = OperationAccountMapping::query()
            ->where('operation_code_id', $operationCodeId)
            ->where('status', OperationAccountMapping::STATUS_ACTIVE)
            ->where('approval_status', OperationAccountMapping::APPROVAL_APPROVED);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        if ($agencyId === null) {
            $query->getQuery()->whereNull('agency_id');
        } else {
            $query->where('agency_id', $agencyId);
        }
        if ($currency === null) {
            $query->getQuery()->whereNull('currency');
        } else {
            $query->where('currency', $currency);
        }

        foreach ($query->get(['effective_from', 'effective_to']) as $existing) {
            if ($this->windowsOverlap($from, $to, $this->dateInput($existing->effective_from), $this->dateInput($existing->effective_to))) {
                return true;
            }
        }

        return false;
    }

    private function windowsOverlap(?string $from1, ?string $to1, ?string $from2, ?string $to2): bool
    {
        $firstAfterSecond = $to2 !== null && $from1 !== null && $from1 > $to2;
        $secondAfterFirst = $to1 !== null && $from2 !== null && $from2 > $to1;

        return ! $firstAfterSecond && ! $secondAfterFirst;
    }

    private function dateInput(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
