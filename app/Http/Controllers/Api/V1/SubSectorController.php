<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreSubSectorRequest;
use App\Http\Requests\UpdateSubSectorRequest;
use App\Http\Resources\SubSectorCollection;
use App\Http\Resources\SubSectorResource;
use App\Models\Sector;
use App\Models\SubSector;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SubSectorController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{sub_sectors: array<int, \App\Http\Resources\SubSectorResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): SubSectorCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', SubSector::class)) {
            return $this->respondForbidden();
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new SubSectorCollection(SubSector::query()->with('sector')->latest()->paginate($perPage));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{sub_sector: \App\Http\Resources\SubSectorResource}, errors: null, meta: null}')]
    public function store(StoreSubSectorRequest $request): JsonResponse
    {
        $sector = Sector::query()->where('public_id', $request->string('sector_public_id'))->first();
        if (! $sector instanceof Sector) {
            return $this->respondUnprocessable(errors: ['sector_public_id' => ['The selected sector is invalid.']]);
        }

        $subSector = SubSector::query()->create([
            'public_id' => (string) Str::ulid(),
            'sector_id' => $sector->id,
            'code' => $request->string('code')->toString(),
            'name' => $request->string('name')->toString(),
            'status' => $request->input('status', SubSector::STATUS_ACTIVE),
        ]);

        $this->securityAudit->record('sub_sector.created', actor: $request->user(), subject: $subSector, request: $request);

        return $this->respondCreated(SubSectorResource::make($subSector->loadMissing('sector')), 'Sub-sector created successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{sub_sector: \App\Http\Resources\SubSectorResource}, errors: null, meta: null}')]
    public function show(Request $request, SubSector $subSector): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $subSector)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(SubSectorResource::make($subSector->loadMissing('sector')));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{sub_sector: \App\Http\Resources\SubSectorResource}, errors: null, meta: null}')]
    public function update(UpdateSubSectorRequest $request, SubSector $subSector): JsonResponse
    {
        $validated = $request->validated();

        if (array_key_exists('sector_public_id', $validated)) {
            $sector = Sector::query()->where('public_id', $validated['sector_public_id'])->first();
            if (! $sector instanceof Sector) {
                return $this->respondUnprocessable(errors: ['sector_public_id' => ['The selected sector is invalid.']]);
            }

            $validated['sector_id'] = $sector->id;
            unset($validated['sector_public_id']);
        }

        $subSector->fill($validated)->save();

        $this->securityAudit->record('sub_sector.updated', actor: $request->user(), subject: $subSector, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(SubSectorResource::make($subSector->loadMissing('sector')), 'Sub-sector updated successfully');
    }

    public function destroy(Request $request, SubSector $subSector): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('delete', $subSector)) {
            return $this->respondForbidden();
        }

        $subSector->delete();
        $this->securityAudit->record('sub_sector.deleted', actor: $request->user(), subject: $subSector, request: $request);

        return $this->respondSuccess(message: 'Sub-sector deleted successfully');
    }
}
