<?php

declare(strict_types=1);

namespace App\Application\IslamicFinance;

use App\Http\Controllers\BaseController;
use App\Models\OperationAccountMapping;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class IslamicMappingApprovalWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly IslamicApprovalWorkflowService $approvalWorkflow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'status' => ['sometimes', 'nullable', Rule::in([
                OperationAccountMapping::STATUS_ACTIVE,
                OperationAccountMapping::STATUS_INACTIVE,
                OperationAccountMapping::STATUS_ARCHIVED,
            ])],
            'approval_status' => ['sometimes', 'nullable', Rule::in(OperationAccountMapping::APPROVAL_STATUSES)],
            'operation_code' => ['sometimes', 'nullable', 'string', 'max:128'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'sharia_approval_required' => ['sometimes', 'boolean'],
        ])->validate();

        $query = DB::table('operation_account_mappings as map')
            ->leftJoin('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->where('op.module', 'islamic_finance')
            ->orderByDesc('map.id')
            ->select(['map.*', 'op.code as operation_code']);

        foreach (['status', 'approval_status', 'currency'] as $key) {
            if (is_string($validated[$key] ?? null) && $validated[$key] !== '') {
                $query->where('map.'.$key, $validated[$key]);
            }
        }
        if (is_string($validated['operation_code'] ?? null) && $validated['operation_code'] !== '') {
            $query->where('op.code', $validated['operation_code']);
        }
        if (isset($validated['sharia_approval_required'])) {
            $query->where('map.sharia_approval_required', (bool) $validated['sharia_approval_required']);
        }
        if (is_string($validated['agency_public_id'] ?? null) && $validated['agency_public_id'] !== '') {
            $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id']);
            if (is_int($agencyId)) {
                $query->where('map.agency_id', $agencyId);
            }
        }

        $rows = $query->get();

        return $this->respondSuccess(
            $rows->map(fn (object $row): array => $this->payload($row))->all(),
            'Islamic mappings retrieved'
        );
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = $this->validatePayload($request, false);

        try {
            $row = DB::transaction(function () use ($validated, $actor, $request): object {
                $operationCode = DB::table('operation_codes')
                    ->where('public_id', $this->requiredString($validated, 'operation_code_public_id'))
                    ->where('module', 'islamic_finance')
                    ->where('status', 'active')
                    ->first(['id']);
                if (! is_object($operationCode)) {
                    throw new InvalidArgumentException('Operation code must be an active Islamic finance operation.');
                }

                $mappingPublicId = (string) Str::ulid();
                $id = DB::table('operation_account_mappings')->insertGetId([
                    'public_id' => $mappingPublicId,
                    'operation_code_id' => (int) $operationCode->id,
                    'agency_id' => $this->idByPublicId('agencies', $validated['agency_public_id'] ?? null),
                    'debit_ledger_account_id' => $this->ledgerIdByPublicId($validated['debit_ledger_account_public_id'] ?? null),
                    'credit_ledger_account_id' => $this->ledgerIdByPublicId($validated['credit_ledger_account_public_id'] ?? null),
                    'currency' => is_string($validated['currency'] ?? null) ? strtoupper($validated['currency']) : null,
                    'effective_from' => $this->requiredString($validated, 'effective_from'),
                    'effective_to' => is_string($validated['effective_to'] ?? null) ? $validated['effective_to'] : null,
                    'status' => OperationAccountMapping::STATUS_INACTIVE,
                    'approval_status' => OperationAccountMapping::APPROVAL_DRAFT,
                    'accounting_owner_user_id' => $this->idByPublicId('users', $validated['accounting_owner_user_public_id'] ?? null),
                    'sharia_approval_required' => (bool) $validated['sharia_approval_required'],
                    'sharia_approval_status' => (bool) $validated['sharia_approval_required']
                        ? OperationAccountMapping::SHARIA_PENDING
                        : OperationAccountMapping::SHARIA_NOT_REQUIRED,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'rules' => isset($validated['rules']) ? json_encode($validated['rules'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->approvalWorkflow->ensureWorkflow(
                    IslamicApprovalStateMachine::SUBJECT_MAPPING,
                    $mappingPublicId,
                    $actor,
                    $request,
                );

                $row = DB::table('operation_account_mappings')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic mapping could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mapping' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.mapping.created', actor: $actor, properties: [
            'mapping_public_id' => $this->rowString($row, 'public_id'),
            'approval_status' => $this->rowString($row, 'approval_status'),
        ], request: $request);

        return $this->respondCreated($this->payload($row), 'Islamic mapping created');
    }

    public function show(Request $request, string $mappingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $row = DB::table('operation_account_mappings')->where('public_id', $mappingPublicId)->first();
        if (! is_object($row)) {
            return $this->respondNotFound('Islamic mapping not found.');
        }

        return $this->respondSuccess($this->payload($row), 'Islamic mapping retrieved');
    }

    public function updateDraft(Request $request, string $mappingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = $this->validatePayload($request, true);

        try {
            $row = DB::transaction(function () use ($mappingPublicId, $validated): object {
                $mapping = DB::table('operation_account_mappings')->where('public_id', $mappingPublicId)->lockForUpdate()->first();
                if (! is_object($mapping)) {
                    throw new InvalidArgumentException('Islamic mapping not found.');
                }
                if (! in_array($this->rowString($mapping, 'approval_status'), [
                    OperationAccountMapping::APPROVAL_DRAFT,
                    OperationAccountMapping::APPROVAL_SUBMITTED,
                    OperationAccountMapping::APPROVAL_REJECTED,
                ], true)) {
                    throw new InvalidArgumentException('Only draft/submitted/rejected mappings can be updated.');
                }

                $update = [];
                foreach (['currency', 'effective_from', 'effective_to'] as $field) {
                    if (array_key_exists($field, $validated)) {
                        $update[$field] = $validated[$field];
                    }
                }
                if (array_key_exists('agency_public_id', $validated)) {
                    $update['agency_id'] = $this->idByPublicId('agencies', $validated['agency_public_id']);
                }
                if (array_key_exists('debit_ledger_account_public_id', $validated)) {
                    $update['debit_ledger_account_id'] = $this->ledgerIdByPublicId($validated['debit_ledger_account_public_id']);
                }
                if (array_key_exists('credit_ledger_account_public_id', $validated)) {
                    $update['credit_ledger_account_id'] = $this->ledgerIdByPublicId($validated['credit_ledger_account_public_id']);
                }
                if (array_key_exists('accounting_owner_user_public_id', $validated)) {
                    $update['accounting_owner_user_id'] = $this->idByPublicId('users', $validated['accounting_owner_user_public_id']);
                }
                if (array_key_exists('sharia_approval_required', $validated)) {
                    $required = (bool) $validated['sharia_approval_required'];
                    $update['sharia_approval_required'] = $required;
                    $update['sharia_approval_status'] = $required
                        ? OperationAccountMapping::SHARIA_PENDING
                        : OperationAccountMapping::SHARIA_NOT_REQUIRED;
                }
                if (array_key_exists('sharia_approval_status', $validated)) {
                    $update['sharia_approval_status'] = $validated['sharia_approval_status'];
                }
                if (array_key_exists('rules', $validated)) {
                    $update['rules'] = is_array($validated['rules']) ? json_encode($validated['rules'], JSON_THROW_ON_ERROR) : null;
                }

                if ($update !== []) {
                    $update['updated_at'] = now();
                    DB::table('operation_account_mappings')->where('id', $this->rowInt($mapping, 'id'))->update($update);
                }

                $row = DB::table('operation_account_mappings')->where('id', $this->rowInt($mapping, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic mapping could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mapping' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.mapping.updated', actor: $actor, properties: [
            'mapping_public_id' => $this->rowString($row, 'public_id'),
            'approval_status' => $this->rowString($row, 'approval_status'),
        ], request: $request);

        return $this->respondSuccess($this->payload($row), 'Islamic mapping updated');
    }

    public function submit(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->transition($request, $mappingPublicId, IslamicApprovalStateMachine::DECISION_SUBMIT, 'submitted');
    }

    public function approve(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->transition($request, $mappingPublicId, IslamicApprovalStateMachine::DECISION_APPROVE, 'approved', true);
    }

    public function reject(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->transition($request, $mappingPublicId, IslamicApprovalStateMachine::DECISION_REJECT, 'rejected');
    }

    public function suspend(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->transition($request, $mappingPublicId, IslamicApprovalStateMachine::DECISION_SUSPEND, 'suspended');
    }

    public function revoke(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->transition($request, $mappingPublicId, IslamicApprovalStateMachine::DECISION_REVOKE, 'revoked');
    }

    public function archive(Request $request, string $mappingPublicId): JsonResponse
    {
        return $this->transition($request, $mappingPublicId, IslamicApprovalStateMachine::DECISION_ARCHIVE, 'archived');
    }

    private function transition(
        Request $request,
        string $mappingPublicId,
        string $decision,
        string $status,
        bool $isApprove = false,
    ): JsonResponse {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'sharia_approval_status' => ['sometimes', 'nullable', Rule::in(OperationAccountMapping::SHARIA_APPROVAL_STATUSES)],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $row = DB::transaction(function () use ($mappingPublicId, $decision, $status, $isApprove, $validated, $actor, $request): object {
                $mapping = DB::table('operation_account_mappings')->where('public_id', $mappingPublicId)->lockForUpdate()->first();
                if (! is_object($mapping)) {
                    throw new InvalidArgumentException('Islamic mapping not found.');
                }

                $shariaStatus = $this->rowString($mapping, 'sharia_approval_status');
                if (is_string($validated['sharia_approval_status'] ?? null) && $validated['sharia_approval_status'] !== '') {
                    $shariaStatus = $validated['sharia_approval_status'];
                }
                if ($isApprove && $this->rowBool($mapping, 'sharia_approval_required') && $shariaStatus !== OperationAccountMapping::SHARIA_APPROVED) {
                    throw new InvalidArgumentException('Sharia-required mapping cannot be approved until Sharia approval status is approved.');
                }

                $this->approvalWorkflow->ensureWorkflow(
                    IslamicApprovalStateMachine::SUBJECT_MAPPING,
                    $mappingPublicId,
                    $actor,
                    $request,
                );
                $this->approvalWorkflow->applyDecision(
                    IslamicApprovalStateMachine::SUBJECT_MAPPING,
                    $mappingPublicId,
                    $actor,
                    $decision,
                    [
                        'effective_from' => $this->nullableString(((array) $mapping)['effective_from'] ?? null),
                        'effective_to' => $this->nullableString(((array) $mapping)['effective_to'] ?? null),
                        'comments' => is_string($validated['comments'] ?? null) ? $validated['comments'] : null,
                    ],
                    $request,
                );

                $statusValue = match ($status) {
                    OperationAccountMapping::APPROVAL_APPROVED => OperationAccountMapping::STATUS_ACTIVE,
                    OperationAccountMapping::APPROVAL_ARCHIVED => OperationAccountMapping::STATUS_ARCHIVED,
                    default => OperationAccountMapping::STATUS_INACTIVE,
                };
                $update = [
                    'approval_status' => $status,
                    'status' => $statusValue,
                    'updated_at' => now(),
                ];
                if ($isApprove) {
                    $update['approved_by_user_id'] = $actor->id;
                    $update['approved_at'] = now();
                }
                if (array_key_exists('sharia_approval_status', $validated)) {
                    $update['sharia_approval_status'] = $validated['sharia_approval_status'];
                }
                DB::table('operation_account_mappings')->where('id', $this->rowInt($mapping, 'id'))->update($update);

                $row = DB::table('operation_account_mappings')->where('id', $this->rowInt($mapping, 'id'))->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Islamic mapping could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_mapping' => [$exception->getMessage()]]);
        }

        $event = match ($status) {
            OperationAccountMapping::APPROVAL_SUBMITTED => 'islamic.mapping.submitted',
            OperationAccountMapping::APPROVAL_APPROVED => 'islamic.mapping.approved',
            OperationAccountMapping::APPROVAL_REJECTED => 'islamic.mapping.rejected',
            OperationAccountMapping::APPROVAL_SUSPENDED => 'islamic.mapping.suspended',
            OperationAccountMapping::APPROVAL_REVOKED => 'islamic.mapping.revoked',
            OperationAccountMapping::APPROVAL_ARCHIVED => 'islamic.mapping.archived',
            default => 'islamic.mapping.status_changed',
        };
        $this->securityAudit->record($event, actor: $actor, properties: [
            'mapping_public_id' => $this->rowString($row, 'public_id'),
            'approval_status' => $this->rowString($row, 'approval_status'),
            'status' => $this->rowString($row, 'status'),
        ], request: $request);

        return $this->respondSuccess($this->payload($row), 'Islamic mapping updated');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $updating): array
    {
        $required = $updating ? 'sometimes' : 'required';

        $validated = Validator::make($request->all(), [
            'operation_code_public_id' => [$required, 'string', 'exists:operation_codes,public_id'],
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'debit_ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'credit_ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'effective_from' => [$required, 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
            'accounting_owner_user_public_id' => ['sometimes', 'nullable', 'string', 'exists:users,public_id'],
            'sharia_approval_required' => ['sometimes', 'boolean'],
            'sharia_approval_status' => ['sometimes', 'nullable', Rule::in(OperationAccountMapping::SHARIA_APPROVAL_STATUSES)],
            'rules' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $debit = array_key_exists('debit_ledger_account_public_id', $validated) ? $validated['debit_ledger_account_public_id'] : null;
        $credit = array_key_exists('credit_ledger_account_public_id', $validated) ? $validated['credit_ledger_account_public_id'] : null;
        if (! $updating && ! is_string($debit) && ! is_string($credit)) {
            throw new InvalidArgumentException('At least one ledger side (debit or credit) must be configured.');
        }

        return $validated;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(object $row): array
    {
        $operationCode = DB::table('operation_codes')->where('id', $this->rowInt($row, 'operation_code_id'))->first(['public_id', 'code']);
        $agencyPublicId = $this->publicIdById('agencies', $this->rowNullableInt($row, 'agency_id'));
        $debitPublicId = $this->publicIdById('ledger_accounts', $this->rowNullableInt($row, 'debit_ledger_account_id'));
        $creditPublicId = $this->publicIdById('ledger_accounts', $this->rowNullableInt($row, 'credit_ledger_account_id'));

        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'operation_code_public_id' => is_object($operationCode) ? $this->rowString($operationCode, 'public_id') : null,
            'operation_code' => is_object($operationCode) ? $this->rowString($operationCode, 'code') : null,
            'agency_public_id' => $agencyPublicId,
            'debit_ledger_account_public_id' => $debitPublicId,
            'credit_ledger_account_public_id' => $creditPublicId,
            'currency' => $this->nullableString(((array) $row)['currency'] ?? null),
            'effective_from' => $this->nullableString(((array) $row)['effective_from'] ?? null),
            'effective_to' => $this->nullableString(((array) $row)['effective_to'] ?? null),
            'status' => $this->rowString($row, 'status'),
            'approval_status' => $this->rowString($row, 'approval_status'),
            'accounting_owner_user_public_id' => $this->publicIdById('users', $this->rowNullableInt($row, 'accounting_owner_user_id')),
            'sharia_approval_required' => $this->rowBool($row, 'sharia_approval_required'),
            'sharia_approval_status' => $this->rowString($row, 'sharia_approval_status'),
            'approved_by_user_public_id' => $this->publicIdById('users', $this->rowNullableInt($row, 'approved_by_user_id')),
            'approved_at' => $this->nullableString(((array) $row)['approved_at'] ?? null),
            'rules' => $this->decodeJson(((array) $row)['rules'] ?? null),
            'created_at' => $this->nullableString(((array) $row)['created_at'] ?? null),
            'updated_at' => $this->nullableString(((array) $row)['updated_at'] ?? null),
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

    private function publicIdById(string $table, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        $row = DB::table($table)->where('id', $id)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
    }

    private function ledgerIdByPublicId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table('ledger_accounts')->where('public_id', $publicId)->where('status', 'active')->first(['id']);
        if (! is_object($row) || ! is_numeric($row->id)) {
            throw new InvalidArgumentException('Ledger account must exist and be active.');
        }

        return (int) $row->id;
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    private function rowBool(object $row, string $key): bool
    {
        return (bool) (((array) $row)[$key] ?? false);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function requiredString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Expected non-empty string for %s.', $key));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>|array<int, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}
