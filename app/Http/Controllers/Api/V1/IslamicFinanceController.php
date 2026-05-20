<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class IslamicFinanceController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function storeProduct(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'code' => ['required', 'string', 'max:64', 'unique:islamic_products,code'],
            'name' => ['required', 'string', 'max:255'],
            'contract_type' => ['required', Rule::in(['murabaha'])],
            'default_margin_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'rules' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $id = DB::transaction(function () use ($validated): int {
            $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id'] ?? null);

            return DB::table('islamic_products')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'code' => (string) $validated['code'],
                'name' => (string) $validated['name'],
                'contract_type' => (string) $validated['contract_type'],
                'default_margin_rate' => is_numeric($validated['default_margin_rate'] ?? null) ? (float) $validated['default_margin_rate'] : null,
                'status' => 'draft',
                'rules' => isset($validated['rules']) ? json_encode($validated['rules'], JSON_THROW_ON_ERROR) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $row = DB::table('islamic_products')->where('id', $id)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['islamic_product' => ['Product could not be reloaded.']]);
        }

        $this->securityAudit->record('islamic.product.created', actor: $actor, properties: [
            'product_public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'contract_type' => $this->rowString($row, 'contract_type'),
        ], request: $request);

        return $this->respondCreated($this->productPayload($row), 'Islamic product created');
    }

    public function storeComplianceReview(Request $request, string $productPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'checklist' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($productPublicId, $validated, $actor): object {
                $product = DB::table('islamic_products')->where('public_id', $productPublicId)->lockForUpdate()->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Islamic product is invalid.');
                }
                if ($this->rowString($product, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft products can be submitted for Sharia compliance review.');
                }

                $id = DB::table('islamic_compliance_reviews')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_product_id' => $this->rowInt($product, 'id'),
                    'islamic_financing_id' => null,
                    'requested_by_user_id' => $actor->id,
                    'status' => 'pending',
                    'decision' => 'pending',
                    'comments' => $this->nullableString($validated['comments'] ?? null),
                    'checklist' => isset($validated['checklist']) ? json_encode($validated['checklist'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_compliance_reviews')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Compliance review could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_compliance_review' => [$exception->getMessage()]]);
        }

        return $this->respondCreated($this->complianceReviewPayload($row), 'Sharia compliance review requested');
    }

    public function reviewCompliance(Request $request, string $reviewPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($reviewPublicId, $validated, $actor): object {
                $review = DB::table('islamic_compliance_reviews')
                    ->where('public_id', $reviewPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($review)) {
                    throw new InvalidArgumentException('Compliance review is invalid.');
                }
                if ($this->rowString($review, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Compliance review has already been decided.');
                }
                $requesterId = $this->rowNullableInt($review, 'requested_by_user_id');
                if ($requesterId !== null && $requesterId === $actor->id) {
                    throw new InvalidArgumentException('Requester cannot review their own compliance request.');
                }

                $newDecision = $validated['decision'] === 'approve' ? 'approved' : 'rejected';
                DB::table('islamic_compliance_reviews')->where('id', $this->rowInt($review, 'id'))->update([
                    'status' => $newDecision,
                    'decision' => $newDecision,
                    'reviewed_by_user_id' => $actor->id,
                    'reviewed_at' => now(),
                    'comments' => $this->nullableString($validated['comments'] ?? null),
                    'updated_at' => now(),
                ]);

                if ($newDecision === 'approved') {
                    $productId = $this->rowNullableInt($review, 'islamic_product_id');
                    if ($productId !== null) {
                        DB::table('islamic_products')->where('id', $productId)->update([
                            'status' => 'approved',
                            'updated_at' => now(),
                        ]);
                    }
                }

                $updated = DB::table('islamic_compliance_reviews')->where('id', $this->rowInt($review, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Compliance review could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_compliance_review' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.compliance.reviewed', actor: $actor, properties: [
            'review_public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
        ], request: $request);

        return $this->respondSuccess($this->complianceReviewPayload($row), 'Compliance review completed');
    }

    public function storeFinancing(Request $request): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'client_public_id' => ['required', 'string', 'exists:clients,public_id'],
            'agency_public_id' => ['required', 'string', 'exists:agencies,public_id'],
            'product_public_id' => ['required', 'string', 'exists:islamic_products,public_id'],
            'contract_type' => ['required', Rule::in(['murabaha'])],
            'purchase_cost_minor' => ['required', 'integer', 'min:1'],
            'allowed_costs_minor' => ['sometimes', 'integer', 'min:0'],
            'markup_minor' => ['required', 'integer', 'min:0'],
            'supplier_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3', Rule::in(['XAF'])],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $financingPublicId = DB::transaction(function () use ($validated): string {
                $product = DB::table('islamic_products')->where('public_id', (string) $validated['product_public_id'])->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Islamic product is invalid.');
                }
                if ($this->rowString($product, 'status') !== 'approved') {
                    throw new InvalidArgumentException('Islamic product must be Sharia-approved before use.');
                }

                $client = DB::table('clients')->where('public_id', (string) $validated['client_public_id'])->first(['id', 'agency_id']);
                if (! is_object($client) || ! is_numeric($client->id) || ! is_numeric($client->agency_id)) {
                    throw new InvalidArgumentException('Client is invalid.');
                }
                $clientId = (int) $client->id;

                $agencyId = $this->idByPublicId('agencies', $validated['agency_public_id']);
                if ($agencyId === null) {
                    throw new InvalidArgumentException('Agency is invalid.');
                }
                if ((int) $client->agency_id !== $agencyId) {
                    throw new InvalidArgumentException('Client must belong to the financing agency.');
                }

                $productAgencyId = $this->rowNullableInt($product, 'agency_id');
                if ($productAgencyId !== null && $productAgencyId !== $agencyId) {
                    throw new InvalidArgumentException('Islamic product must belong to the financing agency or be global.');
                }

                $purchaseCost = (int) $validated['purchase_cost_minor'];
                $allowedCosts = (int) ($validated['allowed_costs_minor'] ?? 0);
                $markup = (int) $validated['markup_minor'];
                $salePrice = $purchaseCost + $allowedCosts + $markup;
                $contractType = (string) $validated['contract_type'];
                if ($contractType !== $this->rowString($product, 'contract_type')) {
                    throw new InvalidArgumentException('Financing contract type must match the approved Islamic product.');
                }
                $currency = is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : 'XAF';

                $publicId = (string) Str::ulid();
                DB::table('islamic_financings')->insert([
                    'public_id' => $publicId,
                    'client_id' => $clientId,
                    'agency_id' => $agencyId,
                    'islamic_product_id' => $this->rowInt($product, 'id'),
                    'loan_id' => null,
                    'contract_number' => 'IF-'.Str::upper(Str::random(10)),
                    'contract_type' => $contractType,
                    'financed_amount_minor' => $purchaseCost,
                    'purchase_cost_minor' => $purchaseCost,
                    'allowed_costs_minor' => $allowedCosts,
                    'markup_minor' => $markup,
                    'sale_price_minor' => $salePrice,
                    'supplier_name' => $this->nullableString($validated['supplier_name'] ?? null),
                    'currency' => $currency,
                    'starts_on' => $this->nullableString($validated['starts_on'] ?? null),
                    'ends_on' => $this->nullableString($validated['ends_on'] ?? null),
                    'status' => 'draft',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $publicId;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_financing' => [$exception->getMessage()]]);
        }

        $row = DB::table('islamic_financings')->where('public_id', $financingPublicId)->first();
        if (! is_object($row)) {
            return $this->respondUnprocessable(errors: ['islamic_financing' => ['Financing could not be reloaded.']]);
        }

        $this->securityAudit->record('islamic.financing.created', actor: $actor, properties: [
            'financing_public_id' => $this->rowString($row, 'public_id'),
            'contract_type' => $this->rowString($row, 'contract_type'),
        ], request: $request);

        return $this->respondCreated($this->financingPayload($row), 'Islamic financing draft created');
    }

    public function storeFinancingAsset(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'asset_type' => ['required', 'string', 'max:64'],
            'description' => ['required', 'string', 'max:2000'],
            'purchase_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($financingPublicId, $validated): object {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Assets can only be added to draft financings.');
                }

                $id = DB::table('islamic_financed_assets')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'islamic_financing_id' => $this->rowInt($financing, 'id'),
                    'asset_type' => (string) $validated['asset_type'],
                    'description' => (string) $validated['description'],
                    'purchase_amount_minor' => is_numeric($validated['purchase_amount_minor'] ?? null) ? (int) $validated['purchase_amount_minor'] : null,
                    'currency' => is_string($validated['currency'] ?? null) && $validated['currency'] !== '' ? $validated['currency'] : 'XAF',
                    'ownership_status' => 'pending',
                    'metadata' => isset($validated['metadata']) ? json_encode($validated['metadata'], JSON_THROW_ON_ERROR) : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('islamic_financed_assets')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Financed asset could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_financed_asset' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.asset.registered', actor: $actor, properties: [
            'financing_public_id' => $financingPublicId,
            'asset_public_id' => $this->rowString($row, 'public_id'),
            'ownership_status' => $this->rowString($row, 'ownership_status'),
        ], request: $request);

        return $this->respondCreated($this->assetPayload($row), 'Financed asset registered');
    }

    public function storeInstallments(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'installments' => ['required', 'array', 'min:1'],
            'installments.*.due_on' => ['required', 'date'],
            'installments.*.amount_minor' => ['required', 'integer', 'min:1'],
        ])->validate();

        try {
            $rows = DB::transaction(function () use ($financingPublicId, $validated): array {
                $financing = DB::table('islamic_financings')->where('public_id', $financingPublicId)->lockForUpdate()->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Installments can only be added to draft financings.');
                }

                $financingId = $this->rowInt($financing, 'id');
                $currency = $this->rowString($financing, 'currency');

                $existingCount = DB::table('islamic_financing_installments')
                    ->where('islamic_financing_id', $financingId)
                    ->count();
                if ($existingCount > 0) {
                    throw new InvalidArgumentException('Installments have already been generated for this financing.');
                }

                $installmentsInput = is_array($validated['installments'] ?? null) ? $validated['installments'] : [];
                $totalAmount = 0;
                $createdRows = [];

                foreach ($installmentsInput as $i => $inst) {
                    if (! is_array($inst) || ! isset($inst['amount_minor'], $inst['due_on'])) {
                        continue;
                    }
                    $number = $i + 1;
                    $amount = is_numeric($inst['amount_minor']) ? (int) $inst['amount_minor'] : 0;
                    $totalAmount += $amount;

                    $id = DB::table('islamic_financing_installments')->insertGetId([
                        'public_id' => (string) Str::ulid(),
                        'islamic_financing_id' => $financingId,
                        'installment_number' => $number,
                        'due_on' => is_scalar($inst['due_on']) ? (string) $inst['due_on'] : '',
                        'amount_minor' => $amount,
                        'paid_amount_minor' => 0,
                        'currency' => $currency,
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $createdRows[] = DB::table('islamic_financing_installments')->where('id', $id)->first();
                }

                $salePrice = $this->rowInt($financing, 'sale_price_minor');
                if ($totalAmount !== $salePrice) {
                    throw new InvalidArgumentException(
                        'Total installment amount ('.$totalAmount.') must equal the sale price ('.$salePrice.').'
                    );
                }

                return $createdRows;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_financing_installment' => [$exception->getMessage()]]);
        }

        $payload = array_values(array_filter(
            $rows,
            fn (mixed $row): bool => is_object($row),
        ));
        $payload = array_map(fn (object $row): array => $this->installmentPayload($row), $payload);

        return $this->respondCreated(
            data: $payload,
            message: 'Financing installments created',
        );
    }

    public function approveFinancing(Request $request, string $financingPublicId): JsonResponse
    {
        if (! $this->requirePlatformAdmin($request)) {
            return $this->respondForbidden();
        }

        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $row = DB::transaction(function () use ($financingPublicId, $actor): object {
                $financing = DB::table('islamic_financings')
                    ->where('public_id', $financingPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($financing)) {
                    throw new InvalidArgumentException('Islamic financing is invalid.');
                }
                if ($this->rowString($financing, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft financings can be approved.');
                }

                $financingId = $this->rowInt($financing, 'id');

                $assetCount = DB::table('islamic_financed_assets')
                    ->where('islamic_financing_id', $financingId)
                    ->count();
                if ($assetCount === 0) {
                    throw new InvalidArgumentException('Murabaha financing requires at least one financed asset.');
                }

                $installmentCount = DB::table('islamic_financing_installments')
                    ->where('islamic_financing_id', $financingId)
                    ->count();
                if ($installmentCount === 0) {
                    throw new InvalidArgumentException('Murabaha financing requires an installment schedule.');
                }

                $agencyId = $this->rowInt($financing, 'agency_id');
                $salePrice = $this->rowInt($financing, 'sale_price_minor');
                $costBasis = $this->rowInt($financing, 'purchase_cost_minor') + $this->rowInt($financing, 'allowed_costs_minor');
                $markup = $this->rowInt($financing, 'markup_minor');
                $currency = $this->rowString($financing, 'currency');

                $receivableLedger = $this->mappingDebitLedger('murabaha_receivable', $agencyId);
                $payableLedger = $this->mappingCreditLedger('murabaha_payable', $agencyId);
                $profitLedger = $this->mappingCreditLedger('murabaha_profit', $agencyId);

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'MURABAHA-'.Str::upper(Str::random(10)),
                    'business_date' => now()->toDateString(),
                    'posted_at' => null,
                    'agency_id' => $agencyId,
                    'source_module' => 'islamic_finance',
                    'source_type' => 'murabaha_financing',
                    'source_public_id' => $financingPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => 'Murabaha financing '.$this->rowString($financing, 'contract_number'),
                    'created_by_user_id' => $actor->id,
                    'idempotency_key' => 'murabaha-financing:'.$financingPublicId,
                ]);

                $journalEntry->lines()->createMany([
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $receivableLedger,
                        'debit_minor' => $salePrice,
                        'credit_minor' => 0,
                        'currency' => $currency,
                        'line_memo' => 'Murabaha receivable (sale price)',
                    ],
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $payableLedger,
                        'debit_minor' => 0,
                        'credit_minor' => $costBasis,
                        'currency' => $currency,
                        'line_memo' => 'Murabaha cost and allowed costs payable',
                    ],
                    [
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $agencyId,
                        'ledger_account_id' => $profitLedger,
                        'debit_minor' => 0,
                        'credit_minor' => $markup,
                        'currency' => $currency,
                        'line_memo' => 'Murabaha deferred profit',
                    ],
                ]);

                $this->postSystemJournal($journalEntry, $actor);

                DB::table('islamic_financed_assets')
                    ->where('islamic_financing_id', $financingId)
                    ->update(['ownership_status' => 'owned_by_institution', 'updated_at' => now()]);

                DB::table('islamic_financings')->where('id', $financingId)->update([
                    'status' => 'approved',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'journal_entry_id' => $journalEntry->id,
                    'updated_at' => now(),
                ]);

                $updated = DB::table('islamic_financings')->where('id', $financingId)->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Financing could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['islamic_financing' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('islamic.asset.ownership_transferred_to_institution', actor: $actor, properties: [
            'financing_public_id' => $this->rowString($row, 'public_id'),
            'ownership_status' => 'owned_by_institution',
        ], request: $request);
        $this->securityAudit->record('islamic.financing.approved', actor: $actor, properties: [
            'financing_public_id' => $this->rowString($row, 'public_id'),
            'journal_entry_public_id' => $this->journalEntryPublicId($this->rowNullableInt($row, 'journal_entry_id')),
        ], request: $request);

        return $this->respondSuccess($this->financingPayload($row), 'Islamic financing approved and posted');
    }

    private function postSystemJournal(JournalEntry $journalEntry, User $actor): void
    {
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actor->id,
        ])->save();
    }

    private function mappingDebitLedger(string $code, int $agencyId): int
    {
        $mapping = DB::table('operation_account_mappings as map')
            ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->join('ledger_accounts as ledger', 'ledger.id', '=', 'map.debit_ledger_account_id')
            ->where('op.code', $code)
            ->where('op.module', 'islamic_finance')
            ->where('op.status', 'active')
            ->where('map.status', 'active')
            ->where('ledger.status', LedgerAccount::STATUS_ACTIVE)
            ->where('ledger.agency_id', $agencyId)
            ->where(function ($q): void {
                $q->whereNull('map.currency')->orWhere('map.currency', 'XAF');
            })
            ->first(['map.debit_ledger_account_id']);

        if (! is_object($mapping) || ! is_numeric($mapping->debit_ledger_account_id)) {
            throw new InvalidArgumentException('Active operation mapping required for '.$code.' (debit).');
        }

        $ledgerId = (int) $mapping->debit_ledger_account_id;
        $ledger = LedgerAccount::query()->whereKey($ledgerId)->first();
        if (! $ledger instanceof LedgerAccount) {
            throw new InvalidArgumentException('Mapped ledger for '.$code.' must be active and agency-scoped.');
        }

        return $ledgerId;
    }

    private function mappingCreditLedger(string $code, int $agencyId): int
    {
        $mapping = DB::table('operation_account_mappings as map')
            ->join('operation_codes as op', 'op.id', '=', 'map.operation_code_id')
            ->join('ledger_accounts as ledger', 'ledger.id', '=', 'map.credit_ledger_account_id')
            ->where('op.code', $code)
            ->where('op.module', 'islamic_finance')
            ->where('op.status', 'active')
            ->where('map.status', 'active')
            ->where('ledger.status', LedgerAccount::STATUS_ACTIVE)
            ->where('ledger.agency_id', $agencyId)
            ->where(function ($q): void {
                $q->whereNull('map.currency')->orWhere('map.currency', 'XAF');
            })
            ->first(['map.credit_ledger_account_id']);

        if (! is_object($mapping) || ! is_numeric($mapping->credit_ledger_account_id)) {
            throw new InvalidArgumentException('Active operation mapping required for '.$code.' (credit).');
        }

        $ledgerId = (int) $mapping->credit_ledger_account_id;
        $ledger = LedgerAccount::query()->whereKey($ledgerId)->first();
        if (! $ledger instanceof LedgerAccount) {
            throw new InvalidArgumentException('Mapped ledger for '.$code.' must be active and agency-scoped.');
        }

        return $ledgerId;
    }

    private function requirePlatformAdmin(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasRole('platform-admin');
    }

    private function journalEntryPublicId(?int $journalEntryId): ?string
    {
        if ($journalEntryId === null) {
            return null;
        }

        $row = DB::table('journal_entries')->where('id', $journalEntryId)->first(['public_id']);

        return is_object($row) && is_string($row->public_id) ? $row->public_id : null;
    }

    private function idByPublicId(string $table, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);

        return is_object($row) && is_numeric($row->id) ? (int) $row->id : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'code' => $this->rowString($row, 'code'),
            'name' => $this->rowString($row, 'name'),
            'contract_type' => $this->rowString($row, 'contract_type'),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function complianceReviewPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'status' => $this->rowString($row, 'status'),
            'decision' => $this->rowString($row, 'decision'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function financingPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'contract_number' => $this->rowString($row, 'contract_number'),
            'contract_type' => $this->rowString($row, 'contract_type'),
            'purchase_cost_minor' => $this->rowInt($row, 'purchase_cost_minor'),
            'allowed_costs_minor' => $this->rowInt($row, 'allowed_costs_minor'),
            'markup_minor' => $this->rowInt($row, 'markup_minor'),
            'sale_price_minor' => $this->rowInt($row, 'sale_price_minor'),
            'status' => $this->rowString($row, 'status'),
            'currency' => $this->rowString($row, 'currency'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'asset_type' => $this->rowString($row, 'asset_type'),
            'description' => $this->rowString($row, 'description'),
            'purchase_amount_minor' => $this->rowNullableInt($row, 'purchase_amount_minor'),
            'ownership_status' => $this->rowString($row, 'ownership_status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function installmentPayload(object $row): array
    {
        return [
            'public_id' => $this->rowString($row, 'public_id'),
            'installment_number' => $this->rowInt($row, 'installment_number'),
            'due_on' => $this->rowNullableString($row, 'due_on'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'status' => $this->rowString($row, 'status'),
        ];
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
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

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
