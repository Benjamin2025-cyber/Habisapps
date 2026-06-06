<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Application\Dashboard\DashboardMetrics;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\UpdateLoanLinkedAccountsRequest;
use App\Http\Requests\UpdateLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Sector;
use App\Models\SubSector;
use App\Models\User;
use App\Support\Finance\LoanProductFormulaPolicySnapshotter;
use App\Support\Finance\LoanProductPenaltyTermsValidator;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class LoanCrudWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly LoanProductFormulaPolicySnapshotter $formulaPolicySnapshotter,
        private readonly LoanSetupState $setupState,
        private readonly DashboardMetrics $dashboardMetrics,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', Loan::class)) {
            return $this->respondForbidden();
        }

        $query = Loan::query()->with($this->relations())->latest();
        if (! $actor->hasRole('platform-admin') && ! $actor->can('crm.scope.institution.read')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null) {
                return $this->respondForbidden('Loan list requires an active agency assignment.');
            }
            $query->where('agency_id', $agencyId);
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function (Builder $builder) use ($term): void {
                $builder
                    ->where('loan_number', 'ilike', '%'.$term.'%')
                    ->orWhere('status', 'ilike', '%'.$term.'%')
                    ->orWhere('purpose', 'ilike', '%'.$term.'%')
                    ->orWhereHas('client', function (Builder $clientQuery) use ($term): void {
                        $clientQuery
                            ->where('client_reference', 'ilike', '%'.$term.'%')
                            ->orWhere('first_name', 'ilike', '%'.$term.'%')
                            ->orWhere('last_name', 'ilike', '%'.$term.'%')
                            ->orWhere('phone_number', 'ilike', '%'.$term.'%');
                    });
            });
        }

        $clientPublicId = $this->clientFilterValue($request);
        if ($clientPublicId !== null) {
            $clientId = Client::query()->where('public_id', $clientPublicId)->value('id');
            // An unknown or out-of-scope client id produces an impossible
            // predicate so the response is an empty, scope-safe page rather
            // than leaking another client's loans.
            $query->where('client_id', is_int($clientId) ? $clientId : 0);
        }

        if ($this->inArrearsFilterRequested($request)) {
            // Restrict to loans with overdue, unpaid schedule exposure as of
            // today, using the same delinquency definition as the dashboard.
            // The candidate set is the already scope-filtered query, so the
            // arrears predicate cannot widen visibility.
            $candidateIds = [];
            foreach ((clone $query)->pluck('id') as $id) {
                if (is_numeric($id)) {
                    $candidateIds[] = (int) $id;
                }
            }
            $delinquentIds = $this->dashboardMetrics->delinquentLoanIdsWithin($candidateIds, now()->toDateString());
            $query->whereKey($delinquentIds === [] ? [0] : $delinquentIds);
        }

        $loans = $query->paginate(min(max($request->integer('per_page', 25), 1), 100));

        return $this->respondSuccess([
            'loans' => LoanResource::collection($loans->getCollection()),
        ], meta: [
            'pagination' => [
                'current_page' => $loans->currentPage(),
                'per_page' => $loans->perPage(),
                'total' => $loans->total(),
                'last_page' => $loans->lastPage(),
            ],
        ]);
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $resolved = $this->resolveStoreReferences($validated);
        if ($resolved['errors'] !== []) {
            return $this->respondUnprocessable(errors: $resolved['errors']);
        }

        /** @var Client $client */
        $client = $resolved['client'];
        /** @var LoanProduct $product */
        $product = $resolved['product'];
        $actor = $request->user();
        if ($actor instanceof User
            && ! $actor->hasRole('platform-admin')
            && ! $actor->can('crm.scope.institution.manage')
            && $this->staffAgencyScope->currentAgencyId($actor) !== $client->agency_id) {
            return $this->respondForbidden('Loan can only be created inside your agency scope.');
        }

        $penaltyTermErrors = LoanProductPenaltyTermsValidator::errors($product);
        if ($penaltyTermErrors !== []) {
            return $this->respondUnprocessable(errors: $penaltyTermErrors);
        }

        // Defense in depth: a product created before formula-policy approval
        // gating (or mutated directly) can reference an unapproved policy.
        // Detect it here and return a field-level 422 naming the failing
        // policy instead of letting the snapshotter throw a 500.
        $policyErrors = $this->formulaPolicySnapshotter->approvalErrors($product);
        if ($policyErrors !== []) {
            return $this->respondUnprocessable(errors: $policyErrors);
        }

        $currency = $validated['currency'] ?? 'XAF';
        $appliedOn = $validated['applied_on'] ?? now()->toDateString();
        $loan = new Loan($this->payload($validated, $resolved));
        $loan->forceFill([
            'public_id' => (string) Str::ulid(),
            'loan_number' => 'LN-'.Str::ulid(),
            'client_id' => $client->id,
            'agency_id' => $client->agency_id,
            'loan_product_id' => $product->id,
            'status' => Loan::STATUS_APPLICATION,
            'currency' => is_string($currency) ? strtoupper($currency) : 'XAF',
            'applied_on' => is_string($appliedOn) ? $appliedOn : now()->toDateString(),
        ]);
        $this->formulaPolicySnapshotter->applyToLoan($loan, $product);
        $loan->save();

        $this->securityAudit->record('loan.application.created', actor: $request->user(), subject: $loan, request: $request);

        return $this->respondCreated(LoanResource::make($loan->loadMissing($this->relations())), 'Loan application created successfully');
    }

    public function show(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('view', $loan)) {
            return $this->respondForbidden();
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $loan->loadMissing($this->relations());
        if ($request->boolean('include_setup_charges')) {
            // Reuse the LoanSetupState serializer (FBI2-030) so this projection
            // matches GET /loans/{loan}/setup-charges exactly.
            $loan->setAttribute('setup_charges_state', $this->setupState->forLoan($loan));
        }

        return $this->respondSuccess(LoanResource::make($loan));
    }

    public function update(UpdateLoanRequest $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        if ($loan->status !== Loan::STATUS_APPLICATION) {
            return $this->respondUnprocessable(errors: ['status' => ['Only application-stage loans can be updated through this endpoint.']]);
        }

        $validated = $request->validated();
        $resolved = $this->resolveUpdateReferences($loan, $validated);
        if ($resolved['errors'] !== []) {
            return $this->respondUnprocessable(errors: $resolved['errors']);
        }

        $loan->fill($this->payload($validated, $resolved));
        $loan->save();

        $this->securityAudit->record('loan.application.updated', actor: $actor, subject: $loan, properties: [
            'changed_fields' => array_keys($validated),
        ], request: $request);

        return $this->respondSuccess(LoanResource::make($loan->refresh()->loadMissing($this->relations())), 'Loan application updated successfully');
    }

    /**
     * Update only the linked customer accounts (amortization, unpaid,
     * recovery, transfer) after the application stage. Loan terms are left
     * untouched. This lets operations attach a missing recovery account once
     * a loan is disbursed/active without reopening the full application.
     */
    public function updateLinkedAccounts(UpdateLoanLinkedAccountsRequest $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        if (in_array($loan->status, [Loan::STATUS_CLOSED, Loan::STATUS_REJECTED, Loan::STATUS_WRITTEN_OFF], true)) {
            return $this->respondUnprocessable(errors: ['status' => ['Linked accounts cannot be changed once the loan is closed, rejected, or written off.']]);
        }

        $loan->loadMissing('client');
        $client = $loan->client;
        if (! $client instanceof Client) {
            return $this->respondUnprocessable(errors: ['client_public_id' => ['Loan client is missing.']]);
        }

        // Reject no-op payloads: an empty body, or a body whose keys are all
        // unrecognized (e.g. the typo `recovery_account_id` instead of
        // `recovery_account_public_id`), must not return a misleading success
        // while changing nothing (AIR-005).
        $accountFieldNames = array_keys($this->accountFields());
        $unknownFields = array_values(array_diff(array_keys($request->all()), $accountFieldNames));
        if ($unknownFields !== []) {
            return $this->respondUnprocessable(errors: [
                'accounts' => ['Unknown linked-account field(s): '.implode(', ', $unknownFields).'.'],
            ]);
        }

        $presentFields = array_values(array_filter($accountFieldNames, fn (string $field): bool => $request->has($field)));
        if ($presentFields === []) {
            return $this->respondUnprocessable(errors: [
                'accounts' => ['Provide at least one linked-account field: '.implode(', ', $accountFieldNames).'.'],
            ]);
        }

        $validated = $request->validated();
        $accountErrors = [];
        foreach ($this->accountFields() as $field => $column) {
            $publicId = $validated[$field] ?? null;
            if ($publicId === null || $publicId === '') {
                continue;
            }

            $account = CustomerAccount::query()->where('public_id', $publicId)->first();
            if (! $account instanceof CustomerAccount
                || $account->status !== CustomerAccount::STATUS_ACTIVE
                || $account->client_id !== $client->id
                || $account->agency_id !== $client->agency_id) {
                $accountErrors[$field] = ['Selected account must be active and belong to the loan client and agency.'];
            }
        }

        if ($accountErrors !== []) {
            return $this->respondUnprocessable(errors: $accountErrors);
        }

        $updates = [];
        foreach ($this->accountFields() as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $updates[$column] = $this->resolveCustomerAccountId($validated[$field] ?? null);
            }
        }
        $loan->forceFill($updates)->save();

        $changedFields = array_values(array_intersect($accountFieldNames, array_keys($validated)));

        $this->securityAudit->record('loan.linked_accounts.updated', actor: $actor, subject: $loan, properties: [
            'changed_fields' => $changedFields,
        ], request: $request);

        return $this->respondSuccess(
            LoanResource::make($loan->refresh()->loadMissing($this->relations())),
            'Loan linked accounts updated successfully',
            meta: ['changed_fields' => $changedFields],
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function resolveStoreReferences(array $validated): array
    {
        $client = Client::query()->where('public_id', $validated['client_public_id'])->first();
        $product = LoanProduct::query()->where('public_id', $validated['loan_product_public_id'])->first();
        $errors = [];

        if (! $client instanceof Client || $client->status !== Client::STATUS_ACTIVE || $client->kyc_status !== Client::KYC_STATUS_VERIFIED) {
            $errors['client_public_id'] = ['Client must be active and KYC verified.'];
        }

        if (! $product instanceof LoanProduct || $product->status !== LoanProduct::STATUS_ACTIVE) {
            $errors['loan_product_public_id'] = ['Loan product must be active.'];
        }

        if ($client instanceof Client && $product instanceof LoanProduct) {
            $this->validateProductAmount($product, $this->intValue($validated['requested_amount_minor'] ?? 0), $errors);
        }

        if ($client instanceof Client) {
            $errors += $this->resolveScopedReferences($client, $validated);
        }

        if ($errors !== [] || ! $client instanceof Client || ! $product instanceof LoanProduct) {
            return [
                'client' => $client,
                'product' => $product,
                'errors' => $errors,
            ];
        }

        return [
            'client' => $client,
            'product' => $product,
            'errors' => $errors,
        ] + $this->resolvedScopedIds($client, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function resolveUpdateReferences(Loan $loan, array $validated): array
    {
        $loan->loadMissing(['client', 'loanProduct']);
        $client = $loan->client;
        if (! $client instanceof Client) {
            return ['errors' => ['client_public_id' => ['Loan client is missing.']]];
        }
        $errors = $this->resolveScopedReferences($client, $validated);
        $product = $loan->loanProduct;
        if ($product instanceof LoanProduct && array_key_exists('requested_amount_minor', $validated)) {
            $this->validateProductAmount($product, $this->intValue($validated['requested_amount_minor']), $errors);
        }

        if ($errors !== []) {
            return ['errors' => $errors];
        }

        return ['errors' => $errors] + $this->resolvedScopedIds($client, $validated);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array<int, string>>
     */
    private function resolveScopedReferences(Client $client, array $validated): array
    {
        $errors = [];
        $this->resolveCreditAgent($client->agency_id, $validated['credit_agent_public_id'] ?? null, $errors);
        $this->resolveSector($validated['sector_public_id'] ?? null, $validated['sub_sector_public_id'] ?? null, $errors);

        foreach ($this->accountFields() as $field => $column) {
            $publicId = $validated[$field] ?? null;
            if ($publicId === null || $publicId === '') {
                continue;
            }

            $account = CustomerAccount::query()->where('public_id', $publicId)->first();
            if (! $account instanceof CustomerAccount
                || $account->status !== CustomerAccount::STATUS_ACTIVE
                || $account->client_id !== $client->id
                || $account->agency_id !== $client->agency_id) {
                $errors[$field] = ['Selected account must be active and belong to the loan client and agency.'];
            }
        }

        return is_array($errors) ? $errors : [];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, int|null>
     */
    private function resolvedScopedIds(Client $client, array $validated): array
    {
        $resolved = [
            'credit_agent_id' => $this->resolveCreditAgent($client->agency_id, $validated['credit_agent_public_id'] ?? null),
        ];

        $sector = $this->resolveSector($validated['sector_public_id'] ?? null, $validated['sub_sector_public_id'] ?? null);
        $resolved['sector_id'] = $sector['sector_id'];
        $resolved['sub_sector_id'] = $sector['sub_sector_id'];

        foreach ($this->accountFields() as $field => $column) {
            if (array_key_exists($field, $validated)) {
                $resolved[$column] = $this->resolveCustomerAccountId($validated[$field] ?? null);
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function payload(array $payload, array $resolved): array
    {
        foreach (array_keys($this->accountFields()) as $publicIdField) {
            unset($payload[$publicIdField]);
        }

        unset(
            $payload['client_public_id'],
            $payload['loan_product_public_id'],
            $payload['credit_agent_public_id'],
            $payload['sector_public_id'],
            $payload['sub_sector_public_id'],
            $payload['currency'],
            $payload['applied_on'],
        );

        foreach ([
            'credit_agent_id',
            'amortization_account_id',
            'unpaid_account_id',
            'recovery_account_id',
            'transfer_account_id',
            'sector_id',
            'sub_sector_id',
        ] as $field) {
            if (array_key_exists($field, $resolved)) {
                $payload[$field] = $resolved[$field];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function validateProductAmount(LoanProduct $product, int $amount, array &$errors): void
    {
        if ($product->min_amount_minor !== null && $amount < $product->min_amount_minor) {
            $errors['requested_amount_minor'] = ['Requested amount is below the loan product minimum.'];
        }

        if ($product->max_amount_minor !== null && $amount > $product->max_amount_minor) {
            $errors['requested_amount_minor'] = ['Requested amount exceeds the loan product maximum.'];
        }
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    private function resolveCreditAgent(int $agencyId, mixed $publicId, ?array &$errors = null): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $user = User::query()->where('public_id', $publicId)->first();
        if (! $user instanceof User
            || $user->status !== User::STATUS_ACTIVE
            || ! in_array($user->id, $this->staffAgencyScope->currentAgencyStaffIdList($agencyId), true)) {
            if ($errors !== null) {
                $errors['credit_agent_public_id'] = ['Credit agent must be active and assigned to the loan agency.'];
            }

            return null;
        }

        return $user->id;
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     * @return array{sector_id:int|null, sub_sector_id:int|null}
     */
    private function resolveSector(mixed $sectorPublicId, mixed $subSectorPublicId, ?array &$errors = null): array
    {
        $sector = is_string($sectorPublicId) && $sectorPublicId !== ''
            ? Sector::query()->where('public_id', $sectorPublicId)->first()
            : null;
        $subSector = is_string($subSectorPublicId) && $subSectorPublicId !== ''
            ? SubSector::query()->where('public_id', $subSectorPublicId)->first()
            : null;

        if (is_string($sectorPublicId) && $sectorPublicId !== '' && ! $sector instanceof Sector) {
            if ($errors !== null) {
                $errors['sector_public_id'] = ['Selected sector is invalid.'];
            }
        }

        if (is_string($subSectorPublicId) && $subSectorPublicId !== '' && ! $subSector instanceof SubSector) {
            if ($errors !== null) {
                $errors['sub_sector_public_id'] = ['Selected sub-sector is invalid.'];
            }
        }

        if ($sector instanceof Sector && $subSector instanceof SubSector && $subSector->sector_id !== $sector->id) {
            if ($errors !== null) {
                $errors['sub_sector_public_id'] = ['Selected sub-sector must belong to the selected sector.'];
            }
        }

        return [
            'sector_id' => $sector?->id,
            'sub_sector_id' => $subSector?->id,
        ];
    }

    private function resolveCustomerAccountId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $id = CustomerAccount::query()->where('public_id', $publicId)->value('id');

        return is_int($id) ? $id : null;
    }

    /**
     * Read the client public id filter from either the top-level
     * `client_public_id` query parameter or the `filter[client_public_id]`
     * bracket form. Returns null when no usable value is present.
     */
    private function clientFilterValue(Request $request): ?string
    {
        $direct = $request->query('client_public_id');
        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        $filter = $request->query('filter');
        if (is_array($filter) && isset($filter['client_public_id']) && is_string($filter['client_public_id']) && $filter['client_public_id'] !== '') {
            return $filter['client_public_id'];
        }

        return null;
    }

    /**
     * Whether `filter[in_arrears]` (or top-level `in_arrears`) requests only
     * delinquent loans. Accepts the usual truthy strings.
     */
    private function inArrearsFilterRequested(Request $request): bool
    {
        $raw = $request->query('in_arrears');
        $filter = $request->query('filter');
        if ($raw === null && is_array($filter) && array_key_exists('in_arrears', $filter)) {
            $raw = $filter['in_arrears'];
        }

        if (is_bool($raw)) {
            return $raw;
        }

        return is_string($raw) && in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private function canAccessLoanAgency(User $actor, Loan $loan): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->can('crm.scope.institution.read')
            || $this->staffAgencyScope->currentAgencyId($actor) === $loan->agency_id;
    }

    /**
     * @return array<string, string>
     */
    private function accountFields(): array
    {
        return [
            'amortization_account_public_id' => 'amortization_account_id',
            'unpaid_account_public_id' => 'unpaid_account_id',
            'recovery_account_public_id' => 'recovery_account_id',
            'transfer_account_public_id' => 'transfer_account_id',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'client',
            'agency',
            'loanProduct',
            'creditAgent',
            'amortizationAccount',
            'unpaidAccount',
            'recoveryAccount',
            'transferAccount',
            'sector',
            'subSector',
        ];
    }

    private function intValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
