<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreDenominationRequest;
use App\Http\Requests\UpdateDenominationRequest;
use App\Http\Resources\DenominationCollection;
use App\Http\Resources\DenominationResource;
use App\Models\Denomination;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class DenominationController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{denominations: array<int, \App\Http\Resources\DenominationResource>}, errors: null, meta: array{pagination: array{current_page: int, per_page: int, total: int, last_page: int}}}')]
    public function index(Request $request): DenominationCollection|JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', Denomination::class)) {
            return $this->respondForbidden();
        }

        $query = Denomination::query()->latest();
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('code', 'ilike', '%'.$term.'%')
                    ->orWhere('label', 'ilike', '%'.$term.'%')
                    ->orWhere('currency', 'ilike', '%'.$term.'%')
                    ->orWhere('type', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%');
            });
        }

        $perPage = min(max($request->integer('per_page', 25), 1), 100);

        return new DenominationCollection($query->paginate($perPage));
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{denomination: \App\Http\Resources\DenominationResource}, errors: null, meta: null}')]
    public function store(StoreDenominationRequest $request): JsonResponse
    {
        $denomination = Denomination::query()->create([
            'public_id' => (string) Str::ulid(),
            'code' => $request->string('code')->toString(),
            'label' => $request->string('label')->toString(),
            'value_minor' => $request->integer('value_minor'),
            'currency' => strtoupper($request->string('currency')->toString()),
            'type' => $request->string('type')->toString(),
            'status' => $request->input('status', Denomination::STATUS_ACTIVE),
        ]);

        $this->securityAudit->record('cash.denomination.created', actor: $request->user(), subject: $denomination, properties: [
            'code' => $denomination->code,
            'currency' => $denomination->currency,
        ], request: $request);

        return $this->respondCreated(DenominationResource::make($denomination), 'Denomination created successfully');
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{denomination: \App\Http\Resources\DenominationResource}, errors: null, meta: null}')]
    public function show(Request $request, Denomination $denomination): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $denomination)) {
            return $this->respondForbidden();
        }

        return $this->respondSuccess(DenominationResource::make($denomination));
    }

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{denomination: \App\Http\Resources\DenominationResource}, errors: null, meta: null}')]
    public function update(UpdateDenominationRequest $request, Denomination $denomination): JsonResponse
    {
        $validated = $request->validated();
        if (array_key_exists('currency', $validated) && is_string($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        $denomination->fill($validated)->save();

        $this->securityAudit->record('cash.denomination.updated', actor: $request->user(), subject: $denomination, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(DenominationResource::make($denomination->refresh()), 'Denomination updated successfully');
    }
}
