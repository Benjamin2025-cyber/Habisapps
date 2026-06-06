<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\BatchRuns\BatchProcedureRegistry;
use App\Http\Controllers\BaseController;
use App\Http\Resources\BatchProcedureCollection;
use App\Http\Resources\BatchProcedureResource;
use App\Http\Resources\ExecutableBatchProcedureCodeCollection;
use App\Models\BatchProcedure;
use App\Models\BatchProcedureOperationCode;
use App\Models\OperationCode;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class BatchProcedureController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    /**
     * List batch procedures
     *
     * @authenticated
     *
     * @response BatchProcedureCollection
     */
    public function index(Request $request): BatchProcedureCollection
    {
        $this->authorize('viewAny', BatchProcedure::class);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        $query = BatchProcedure::query()->with('operationCodes')->latest();
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('code', 'ilike', '%'.$term.'%')
                    ->orWhere('name', 'ilike', '%'.$term.'%')
                    ->orWhere('description', 'ilike', '%'.$term.'%')
                    ->orWhere('schedule_type', 'ilike', '%'.$term.'%');
            });
        }

        return new BatchProcedureCollection($query->paginate($perPage));
    }

    /**
     * List executable batch-procedure codes
     *
     * Returns the authoritative catalog of batch-procedure codes the backend can
     * actually execute, including stable machine codes and frontend-useful
     * metadata. This is the same registry execution dispatch routes against, so
     * the frontend never has to mirror backend source.
     *
     * @authenticated
     */
    public function executableCodes(Request $request): ExecutableBatchProcedureCodeCollection
    {
        $this->authorize('viewAny', BatchProcedure::class);

        return new ExecutableBatchProcedureCodeCollection(BatchProcedureRegistry::catalog());
    }

    /**
     * Create batch procedure
     *
     * @authenticated
     *
     * @response 201 BatchProcedureResource
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', BatchProcedure::class);

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:64', 'unique:batch_procedures,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schedule_type' => ['nullable', 'string', 'max:32'],
            'execution_priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'schedule_metadata' => ['nullable', 'array'],
            'operation_code_public_ids' => ['nullable', 'array'],
            'operation_code_public_ids.*' => ['string', 'distinct', 'exists:operation_codes,public_id'],
            'status' => ['nullable', 'string', Rule::in([BatchProcedure::STATUS_ACTIVE, BatchProcedure::STATUS_INACTIVE])],
        ])->validate();
        $operationCodeIds = array_key_exists('operation_code_public_ids', $validated)
            ? $this->activeOperationCodeIds($validated['operation_code_public_ids'] ?? [])
            : null;

        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'schedule_type' => $validated['schedule_type'] ?? null,
            'execution_priority' => $this->resolveExecutionPriority($validated['execution_priority'] ?? null, $validated['schedule_metadata'] ?? null),
            'schedule_metadata' => $validated['schedule_metadata'] ?? null,
            'status' => $validated['status'] ?? BatchProcedure::STATUS_ACTIVE,
        ]);
        if ($operationCodeIds !== null) {
            $this->syncOperationCodeIds($procedure, $operationCodeIds);
        }

        $this->securityAudit->record('batch.procedure.created', actor: $request->user(), subject: $procedure, request: $request);

        return $this->respondCreated(
            BatchProcedureResource::make($procedure->load('operationCodes')),
            'Batch procedure created successfully'
        );
    }

    /**
     * Get batch procedure
     *
     * @authenticated
     *
     * @response BatchProcedureResource
     */
    public function show(Request $request, BatchProcedure $batchProcedure): JsonResponse
    {
        $this->authorize('view', $batchProcedure);

        return $this->respondSuccess(
            BatchProcedureResource::make($batchProcedure->loadMissing('operationCodes'))
        );
    }

    /**
     * Update batch procedure
     *
     * @authenticated
     *
     * @response BatchProcedureResource
     */
    public function update(Request $request, BatchProcedure $batchProcedure): JsonResponse
    {
        $this->authorize('update', $batchProcedure);

        $validated = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'schedule_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'execution_priority' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'schedule_metadata' => ['sometimes', 'nullable', 'array'],
            'operation_code_public_ids' => ['sometimes', 'nullable', 'array'],
            'operation_code_public_ids.*' => ['string', 'distinct', 'exists:operation_codes,public_id'],
        ])->validate();

        // Derive priority from metadata when the caller updates metadata only.
        if (! array_key_exists('execution_priority', $validated) && array_key_exists('schedule_metadata', $validated)) {
            $derived = $this->resolveExecutionPriority(null, $validated['schedule_metadata']);
            if ($derived !== null) {
                $validated['execution_priority'] = $derived;
            }
        }

        $operationCodePayloadPresent = array_key_exists('operation_code_public_ids', $validated);
        $operationCodeIds = $operationCodePayloadPresent
            ? $this->activeOperationCodeIds($validated['operation_code_public_ids'] ?? [])
            : null;
        unset($validated['operation_code_public_ids']);

        $batchProcedure->update($validated);
        if ($operationCodePayloadPresent && $operationCodeIds !== null) {
            $this->syncOperationCodeIds($batchProcedure, $operationCodeIds);
        }

        $changedFields = array_keys($validated);
        if ($operationCodePayloadPresent) {
            $changedFields[] = 'operation_code_public_ids';
        }

        $this->securityAudit->record('batch.procedure.updated', actor: $request->user(), subject: $batchProcedure, properties: [
            'changed_fields' => $changedFields,
        ], request: $request);

        return $this->respondSuccess(
            BatchProcedureResource::make($batchProcedure->refresh()->load('operationCodes')),
            'Batch procedure updated successfully'
        );
    }

    /**
     * Update batch procedure status
     *
     * @authenticated
     *
     * @response BatchProcedureResource
     */
    public function updateStatus(Request $request, BatchProcedure $batchProcedure): JsonResponse
    {
        $this->authorize('updateStatus', $batchProcedure);

        $validated = Validator::make($request->all(), [
            'status' => ['required', 'string', Rule::in([BatchProcedure::STATUS_ACTIVE, BatchProcedure::STATUS_INACTIVE])],
        ])->validate();

        $batchProcedure->forceFill(['status' => $validated['status']])->save();
        $this->securityAudit->record('batch.procedure.status_changed', actor: $request->user(), subject: $batchProcedure, properties: [
            'status' => $validated['status'],
        ], request: $request);

        return $this->respondSuccess(
            BatchProcedureResource::make($batchProcedure->refresh()->load('operationCodes')),
            'Batch procedure status updated successfully'
        );
    }

    public function attachOperationCodes(Request $request, BatchProcedure $batchProcedure): JsonResponse
    {
        $this->authorize('update', $batchProcedure);

        $validated = Validator::make($request->all(), [
            'operation_code_public_ids' => ['required', 'array', 'min:1'],
            'operation_code_public_ids.*' => ['string', 'distinct', 'exists:operation_codes,public_id'],
        ])->validate();

        $this->attachActiveOperationCodes($batchProcedure, $validated['operation_code_public_ids']);
        $this->securityAudit->record('batch.procedure.operation_codes_attached', actor: $request->user(), subject: $batchProcedure, properties: [
            'operation_code_public_ids' => $validated['operation_code_public_ids'],
        ], request: $request);

        return $this->respondSuccess(
            BatchProcedureResource::make($batchProcedure->refresh()->load('operationCodes')),
            'Batch procedure operation codes attached successfully'
        );
    }

    public function detachOperationCode(Request $request, BatchProcedure $batchProcedure, OperationCode $operationCode): JsonResponse
    {
        $this->authorize('update', $batchProcedure);

        $batchProcedure->operationCodes()->detach($operationCode->id);
        $this->securityAudit->record('batch.procedure.operation_code_detached', actor: $request->user(), subject: $batchProcedure, properties: [
            'operation_code_public_id' => $operationCode->public_id,
        ], request: $request);

        return $this->respondSuccess(
            BatchProcedureResource::make($batchProcedure->refresh()->load('operationCodes')),
            'Batch procedure operation code detached successfully'
        );
    }

    private function resolveExecutionPriority(mixed $explicitPriority, mixed $scheduleMetadata): ?int
    {
        if (is_numeric($explicitPriority)) {
            return (int) $explicitPriority;
        }

        if (! is_array($scheduleMetadata)) {
            return null;
        }

        $metadataPriority = $scheduleMetadata['execution_priority'] ?? null;

        return is_numeric($metadataPriority) && (int) $metadataPriority >= 0 && (int) $metadataPriority <= 65535
            ? (int) $metadataPriority
            : null;
    }

    /**
     * @param  array<int, int>  $operationIds
     */
    private function syncOperationCodeIds(BatchProcedure $procedure, array $operationIds): void
    {
        $procedure->operationCodes()->syncWithPivotValues($operationIds, [
            'status' => BatchProcedureOperationCode::STATUS_ACTIVE,
        ]);
    }

    /**
     * @param  array<int, string>  $publicIds
     */
    private function attachActiveOperationCodes(BatchProcedure $procedure, array $publicIds): void
    {
        $operationIds = $this->activeOperationCodeIds($publicIds);
        $procedure->operationCodes()->syncWithoutDetaching(
            collect($operationIds)
                ->mapWithKeys(fn (int $id): array => [$id => ['status' => BatchProcedureOperationCode::STATUS_ACTIVE]])
                ->all()
        );
    }

    /**
     * @param  array<int, string>  $publicIds
     * @return array<int, int>
     */
    private function activeOperationCodeIds(array $publicIds): array
    {
        $codes = OperationCode::whereIn('public_id', $publicIds)
            ->get(['id', 'public_id', 'status']);

        $inactive = $codes
            ->filter(fn (OperationCode $code): bool => $code->status !== OperationCode::STATUS_ACTIVE)
            ->map(fn (OperationCode $code): string => $code->public_id)
            ->values()
            ->all();

        if ($inactive !== []) {
            throw ValidationException::withMessages([
                'operation_code_public_ids' => ['Only active operation codes can be attached. Inactive selections: '.implode(', ', $inactive).'.'],
            ]);
        }

        return $codes
            ->map(fn (OperationCode $code): int => $code->id)
            ->values()
            ->all();
    }
}
