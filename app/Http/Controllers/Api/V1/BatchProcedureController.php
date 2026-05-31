<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\BatchProcedureCollection;
use App\Http\Resources\BatchProcedureResource;
use App\Models\BatchProcedure;
use App\Support\Security\SecurityAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

        $query = BatchProcedure::query()->latest();
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
            'schedule_metadata' => ['nullable', 'array'],
            'status' => ['nullable', 'string', Rule::in([BatchProcedure::STATUS_ACTIVE, BatchProcedure::STATUS_INACTIVE])],
        ])->validate();

        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'schedule_type' => $validated['schedule_type'] ?? null,
            'schedule_metadata' => $validated['schedule_metadata'] ?? null,
            'status' => $validated['status'] ?? BatchProcedure::STATUS_ACTIVE,
        ]);

        $this->securityAudit->record('batch.procedure.created', actor: $request->user(), subject: $procedure, request: $request);

        return $this->respondCreated(
            BatchProcedureResource::make($procedure),
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
            BatchProcedureResource::make($batchProcedure)
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
            'schedule_metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $batchProcedure->update($validated);

        $this->securityAudit->record('batch.procedure.updated', actor: $request->user(), subject: $batchProcedure, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(
            BatchProcedureResource::make($batchProcedure->refresh()),
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
            BatchProcedureResource::make($batchProcedure->refresh()),
            'Batch procedure status updated successfully'
        );
    }
}
