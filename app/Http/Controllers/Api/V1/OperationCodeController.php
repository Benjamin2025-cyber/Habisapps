<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreOperationCodeRequest;
use App\Http\Requests\UpdateOperationCodeRequest;
use App\Http\Resources\OperationCodeCollection;
use App\Http\Resources\OperationCodeResource;
use App\Models\OperationCode;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OperationCodeController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{operation_codes: array<int, \App\Http\Resources\OperationCodeResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): OperationCodeCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', OperationCode::class)) {
            return $this->respondForbidden();
        }

        $query = OperationCode::query()->latest();

        if ($request->filled('module')) {
            $query->where('module', $request->string('module'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return new OperationCodeCollection($query->paginate(min(max($request->integer('per_page', 25), 1), 100)));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{operation_code: \App\Http\Resources\OperationCodeResource}, errors: null, meta: null}')]
    public function store(StoreOperationCodeRequest $request): JsonResponse
    {
        $code = OperationCode::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $request->string('code')->toString(),
            'label' => $request->string('label')->toString(),
            'module' => $request->string('module')->toString(),
            'operation_type' => $request->input('operation_type'),
            'direction' => $request->input('direction'),
            'status' => $request->input('status', OperationCode::STATUS_ACTIVE),
            'metadata' => $request->input('metadata'),
        ]);

        $this->securityAudit->record('operation.code.created', actor: $request->user(), subject: $code, properties: [
            'code' => $code->code,
            'module' => $code->module,
        ], request: $request);

        return $this->respondCreated(OperationCodeResource::make($code), 'Operation code created successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{operation_code: \App\Http\Resources\OperationCodeResource}, errors: null, meta: null}')]
    public function show(Request $request, OperationCode $operationCode): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $operationCode)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(OperationCodeResource::make($operationCode));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{operation_code: \App\Http\Resources\OperationCodeResource}, errors: null, meta: null}')]
    public function update(UpdateOperationCodeRequest $request, OperationCode $operationCode): JsonResponse
    {
        $operationCode->fill($request->validated())->save();

        $this->securityAudit->record('operation.code.updated', actor: $request->user(), subject: $operationCode, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(OperationCodeResource::make($operationCode->refresh()), 'Operation code updated successfully');
    }

    public function destroy(Request $request, OperationCode $operationCode): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $operationCode)) {
            return $this->respondForbidden();
        }

        if (DB::table('operation_account_mappings')
            ->where('operation_code_id', $operationCode->id)
            ->where('status', '!=', OperationCode::STATUS_ARCHIVED)
            ->exists()) {
            return $this->respondUnprocessable('Operation code cannot be archived while active or inactive account mappings exist.');
        }

        $operationCode->update(['status' => OperationCode::STATUS_ARCHIVED]);
        $this->securityAudit->record('operation.code.archived', actor: $actor, subject: $operationCode, request: $request);

        return $this->respondSuccess(message: 'Operation code archived successfully');
    }
}
