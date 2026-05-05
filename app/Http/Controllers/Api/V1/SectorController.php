<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreSectorRequest;
use App\Http\Requests\UpdateSectorRequest;
use App\Http\Resources\SectorCollection;
use App\Http\Resources\SectorResource;
use App\Models\Sector;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SectorController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{sectors: array<int, \App\Http\Resources\SectorResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): SectorCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', Sector::class)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new SectorCollection(Sector::query()->latest()->paginate($perPage));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{sector: \App\Http\Resources\SectorResource}, errors: null, meta: null}')]
    public function store(StoreSectorRequest $request): JsonResponse
    {
        $sector = Sector::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'status' => $request->input('status', Sector::STATUS_ACTIVE),
        ]);

        $this->securityAudit->record('sector.created', actor: $request->user(), subject: $sector, request: $request);

        return $this->respondCreated(SectorResource::make($sector), 'Sector created successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{sector: \App\Http\Resources\SectorResource}, errors: null, meta: null}')]
    public function show(Request $request, Sector $sector): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $sector)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(SectorResource::make($sector));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{sector: \App\Http\Resources\SectorResource}, errors: null, meta: null}')]
    public function update(UpdateSectorRequest $request, Sector $sector): JsonResponse
    {
        $sector->fill($request->validated())->save();

        $this->securityAudit->record('sector.updated', actor: $request->user(), subject: $sector, properties: [
            'changed_fields' => array_keys($request->validated()),
        ], request: $request);

        return $this->respondSuccess(SectorResource::make($sector), 'Sector updated successfully');
    }

    public function destroy(Request $request, Sector $sector): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $sector)) {
            return $this->respondForbidden();
        }

        $sector->delete();
        $this->securityAudit->record('sector.deleted', actor: $request->user(), subject: $sector, request: $request);

        return $this->respondSuccess(message: 'Sector deleted successfully');
    }
}
