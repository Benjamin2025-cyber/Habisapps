<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\BatchProcedureResource;
use App\Models\BatchProcedure;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class BatchProcedureController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->user()?->can('batch.procedures.view') !== true && $request->user()?->can('batch.procedures.manage') !== true) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $procedures = BatchProcedure::query()->latest()->paginate($perPage);

        return $this->respondSuccess([
            'procedures' => BatchProcedureResource::collection($procedures->getCollection())->resolve(),
        ], meta: [
            'pagination' => [
                'current_page' => $procedures->currentPage(),
                'per_page' => $procedures->perPage(),
                'total' => $procedures->total(),
                'last_page' => $procedures->lastPage(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($request->user()?->can('batch.procedures.manage') !== true) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:64', 'unique:batch_procedures,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'schedule_type' => ['nullable', 'string', 'max:32'],
            'schedule_metadata' => ['nullable', 'array'],
            'status' => ['nullable', 'string', Rule::in([BatchProcedure::STATUS_ACTIVE, BatchProcedure::STATUS_INACTIVE])],
        ])->validate();

        $procedure = BatchProcedure::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
            'code' => $validated['code'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'schedule_type' => $validated['schedule_type'] ?? null,
            'schedule_metadata' => $validated['schedule_metadata'] ?? null,
            'status' => $validated['status'] ?? BatchProcedure::STATUS_ACTIVE,
        ]);

        $this->securityAudit->record('batch.procedure.created', actor: $request->user(), subject: $procedure, request: $request);

        return $this->respondCreated([
            'procedure' => BatchProcedureResource::make($procedure)->resolve(),
        ], 'Batch procedure created successfully');
    }

    public function show(Request $request, BatchProcedure $batchProcedure): JsonResponse
    {
        if ($request->user()?->can('batch.procedures.view') !== true && $request->user()?->can('batch.procedures.manage') !== true) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess([
            'procedure' => BatchProcedureResource::make($batchProcedure)->resolve(),
        ]);
    }

    public function update(Request $request, BatchProcedure $batchProcedure): JsonResponse
    {
        if ($request->user()?->can('batch.procedures.manage') !== true) {
            return $this->respondForbidden();
        }

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

        return $this->respondSuccess([
            'procedure' => BatchProcedureResource::make($batchProcedure->refresh())->resolve(),
        ], 'Batch procedure updated successfully');
    }

    public function updateStatus(Request $request, BatchProcedure $batchProcedure): JsonResponse
    {
        if ($request->user()?->can('batch.procedures.manage') !== true) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'status' => ['required', 'string', Rule::in([BatchProcedure::STATUS_ACTIVE, BatchProcedure::STATUS_INACTIVE])],
        ])->validate();

        $batchProcedure->forceFill(['status' => $validated['status']])->save();
        $this->securityAudit->record('batch.procedure.status_changed', actor: $request->user(), subject: $batchProcedure, properties: [
            'status' => $validated['status'],
        ], request: $request);

        return $this->respondSuccess([
            'procedure' => BatchProcedureResource::make($batchProcedure->refresh())->resolve(),
        ], 'Batch procedure status updated successfully');
    }
}
