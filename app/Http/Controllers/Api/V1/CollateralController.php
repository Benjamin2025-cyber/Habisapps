<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Resources\CollateralItemResource;
use App\Http\Resources\CollateralResource;
use App\Models\Client;
use App\Models\Collateral;
use App\Models\CollateralItem;
use App\Models\Document;
use App\Models\Loan;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CollateralController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseCollateral($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        $query = Collateral::query()
            ->with(['agency', 'client', 'loan', 'document', 'items'])
            ->where('loan_id', $loan->id);
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(static function (Builder $builder) use ($term): void {
                $builder->where('collateral_type', 'ilike', '%'.$term.'%')
                    ->orWhere('description', 'ilike', '%'.$term.'%')
                    ->orWhere('owner_full_name', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('currency', 'ilike', '%'.$term.'%');
            });
        }
        $collaterals = $query->latest()->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return $this->respondSuccess([
            'collaterals' => CollateralResource::collection($collaterals->getCollection()),
        ], meta: [
            'pagination' => [
                'current_page' => $collaterals->currentPage(),
                'per_page' => $collaterals->perPage(),
                'total' => $collaterals->total(),
                'last_page' => $collaterals->lastPage(),
            ],
        ]);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseCollateral($actor) || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden();
        }

        $validated = $this->validateCollateral($request);
        $errors = [];
        $client = $this->resolveClient($loan, $validated['client_public_id'] ?? null, $errors);
        $document = $this->resolveDocument($loan, $validated['document_public_id'] ?? null, $errors);
        if ($errors !== []) {
            return $this->respondUnprocessable(errors: $errors);
        }

        $collateral = Collateral::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $loan->agency_id,
            'client_id' => $client instanceof Client ? $client->id : $loan->client_id,
            'loan_id' => $loan->id,
            'document_id' => $document?->id,
            'collateral_type' => $validated['collateral_type'],
            'description' => $validated['description'] ?? null,
            'owner_full_name' => $validated['owner_full_name'] ?? null,
            'status' => Collateral::STATUS_ACTIVE,
            'valuation_date' => $validated['valuation_date'] ?? null,
            'declared_value_minor' => $validated['declared_value_minor'] ?? null,
            'currency' => $validated['currency'] ?? $loan->currency,
        ]);

        $this->securityAudit->record('loan.collateral.created', actor: $actor, subject: $collateral, request: $request);

        return $this->respondCreated(CollateralResource::make($collateral->loadMissing(['agency', 'client', 'loan', 'document', 'items'])), 'Collateral created successfully');
    }

    public function update(Request $request, Loan $loan, Collateral $collateral): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseCollateral($actor) || ! $this->canAccessCollateral($actor, $loan, $collateral)) {
            return $this->respondForbidden();
        }

        $validated = $this->validateCollateral($request, updating: true);
        $errors = [];
        $document = array_key_exists('document_public_id', $validated)
            ? $this->resolveDocument($loan, $validated['document_public_id'], $errors)
            : null;
        if ($errors !== []) {
            return $this->respondUnprocessable(errors: $errors);
        }

        unset($validated['client_public_id']);
        if (array_key_exists('document_public_id', $validated)) {
            unset($validated['document_public_id']);
            $validated['document_id'] = $document?->id;
        }

        $collateral->fill($validated)->save();
        $this->securityAudit->record('loan.collateral.updated', actor: $actor, subject: $collateral, request: $request);

        return $this->respondSuccess(CollateralResource::make($collateral->refresh()->loadMissing(['agency', 'client', 'loan', 'document', 'items'])), 'Collateral updated successfully');
    }

    public function release(Request $request, Loan $loan, Collateral $collateral): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseCollateral($actor) || ! $this->canAccessCollateral($actor, $loan, $collateral)) {
            return $this->respondForbidden();
        }

        if ($loan->status !== Loan::STATUS_CLOSED) {
            return $this->respondUnprocessable(errors: ['loan' => [__('Collateral can only be released after loan closure.')]]);
        }

        if ($collateral->status === Collateral::STATUS_RELEASED) {
            return $this->respondSuccess(CollateralResource::make($collateral->loadMissing(['agency', 'client', 'loan', 'document', 'items'])), 'Collateral already released');
        }

        if ($collateral->status !== Collateral::STATUS_ACTIVE) {
            return $this->respondUnprocessable(errors: ['collateral' => [__('Only active collateral can be released.')]]);
        }

        $validated = Validator::make($request->all(), [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();
        $collateral->update(['status' => Collateral::STATUS_RELEASED]);
        $this->securityAudit->record('loan.collateral.released', actor: $actor, subject: $collateral, properties: [
            'release_reason' => $validated['reason'] ?? 'loan_closed',
        ], request: $request);

        return $this->respondSuccess(CollateralResource::make($collateral->refresh()->loadMissing(['agency', 'client', 'loan', 'document', 'items'])), 'Collateral released successfully');
    }

    public function storeItem(Request $request, Loan $loan, Collateral $collateral): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseCollateral($actor) || ! $this->canAccessCollateral($actor, $loan, $collateral)) {
            return $this->respondForbidden();
        }

        $validated = $this->validateItem($request);
        $item = CollateralItem::query()->create([
            'public_id' => (string) Str::ulid(),
            'collateral_id' => $collateral->id,
            ...$validated,
        ]);

        $this->securityAudit->record('loan.collateral_item.created', actor: $actor, subject: $collateral, properties: [
            'collateral_item_public_id' => $item->public_id,
        ], request: $request);

        return $this->respondCreated(CollateralItemResource::make($item), 'Collateral item created successfully');
    }

    public function updateItem(Request $request, Loan $loan, Collateral $collateral, CollateralItem $item): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canUseCollateral($actor) || ! $this->canAccessCollateral($actor, $loan, $collateral) || $item->collateral_id !== $collateral->id) {
            return $this->respondForbidden();
        }

        $validated = $this->validateItem($request, updating: true);
        $item->fill($validated)->save();
        $this->securityAudit->record('loan.collateral_item.updated', actor: $actor, subject: $collateral, properties: [
            'collateral_item_public_id' => $item->public_id,
        ], request: $request);

        return $this->respondSuccess(CollateralItemResource::make($item->refresh()), 'Collateral item updated successfully');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCollateral(Request $request, bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return Validator::make($request->all(), [
            'client_public_id' => ['sometimes', 'nullable', 'string', 'exists:clients,public_id'],
            'document_public_id' => ['sometimes', 'nullable', 'string', 'exists:documents,public_id'],
            'collateral_type' => [$presence, Rule::in([Collateral::TYPE_REAL_ESTATE, Collateral::TYPE_MOVABLE, Collateral::TYPE_PERSONAL_GUARANTEE])],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'owner_full_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'valuation_date' => ['sometimes', 'nullable', 'date'],
            'declared_value_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ])->validate();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateItem(Request $request, bool $updating = false): array
    {
        $presence = $updating ? 'sometimes' : 'required';

        return Validator::make($request->all(), [
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'description' => [$presence, 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:128'],
            'chassis_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'registration_number' => ['sometimes', 'nullable', 'string', 'max:128'],
            'amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'metadata.*' => ['nullable'],
        ])->validate();
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function resolveClient(Loan $loan, mixed $publicId, array &$errors): ?Client
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $client = Client::query()->where('public_id', $publicId)->first();
        if (! $client instanceof Client || $client->agency_id !== $loan->agency_id) {
            $errors['client_public_id'] = ['Selected client must belong to the loan agency.'];

            return null;
        }

        return $client;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function resolveDocument(Loan $loan, mixed $publicId, array &$errors): ?Document
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $document = Document::query()->where('public_id', $publicId)->first();
        if (! $document instanceof Document || $document->agency_id !== $loan->agency_id || $document->status !== Document::STATUS_ACTIVE) {
            $errors['document_public_id'] = ['Selected document must be active and belong to the loan agency.'];

            return null;
        }

        return $document;
    }

    private function canUseCollateral(User $actor): bool
    {
        return $actor->hasRole('platform-admin') || $actor->hasPermissionTo('loans.collaterals.manage');
    }

    private function canAccessLoanAgency(User $actor, Loan $loan): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->can('crm.scope.institution.read')
            || $this->staffAgencyScope->currentAgencyId($actor) === $loan->agency_id;
    }

    private function canAccessCollateral(User $actor, Loan $loan, Collateral $collateral): bool
    {
        return $collateral->loan_id === $loan->id
            && $collateral->agency_id === $loan->agency_id
            && $this->canAccessLoanAgency($actor, $loan);
    }
}
