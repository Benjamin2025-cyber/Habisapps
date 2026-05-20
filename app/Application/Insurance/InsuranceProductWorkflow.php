<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Http\Controllers\BaseController;
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

final class InsuranceProductWorkflow extends BaseController
{
    /**
     * @var list<string>
     */
    private const PRODUCT_TYPES = [
        'borrower', 'health', 'life', 'savings', 'agricultural', 'home',
        'professional_commercial', 'automobile', 'motorcycle', 'school',
        'travel', 'funeral', 'mobile_equipment', 'loan_insurance',
    ];

    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly InsuranceProductReadinessService $productReadiness,
    ) {}

    public function storePartner(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'ledger_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:ledger_accounts,public_id'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'archived'])],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $partner = DB::transaction(function () use ($validated): object {
                $agencyId = $this->agencyId($validated['agency_public_id'] ?? null);
                $ledgerAccountId = $this->ledgerAccountId($validated['ledger_account_public_id'] ?? null, $agencyId);
                $id = DB::table('insurance_partners')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'ledger_account_id' => $ledgerAccountId,
                    'code' => (string) $validated['code'],
                    'name' => (string) $validated['name'],
                    'phone_number' => $this->nullableString($validated['phone_number'] ?? null),
                    'email' => $this->nullableString($validated['email'] ?? null),
                    'address' => $this->nullableString($validated['address'] ?? null),
                    'status' => $this->stringValue($validated['status'] ?? 'active', 'active'),
                    'metadata' => $this->jsonOrNull($validated['metadata'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->reload('insurance_partners', $id, 'Insurance partner could not be reloaded.');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_partner' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.partner.created', actor: $actor, properties: [
            'partner_public_id' => $this->rowString($partner, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->partnerPayload($partner), 'Insurance partner created successfully');
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'insurance_partner_public_id' => ['sometimes', 'nullable', 'string', 'exists:insurance_partners,public_id'],
            'code' => ['required', 'string', 'max:64', 'unique:insurance_products,code'],
            'name' => ['required', 'string', 'max:255'],
            'product_type' => ['required', 'string', 'max:64', Rule::in(self::PRODUCT_TYPES)],
            'premium_calculation_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'premium_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fixed_premium_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'payment_mode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_refundable' => ['sometimes', 'boolean'],
            'business_model' => ['sometimes', 'nullable', Rule::in(['broker', 'distributor', 'premium_collector', 'collector', 'risk_carrier'])],
            'report_category' => ['sometimes', 'nullable', 'string', 'max:64'],
            'new_business_enabled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive', 'archived'])],
            'rules' => ['sometimes', 'nullable', 'array'],
            'coverages' => ['sometimes', 'array'],
            'coverages.*.coverage_code' => ['required_with:coverages', 'string', 'max:64'],
            'coverages.*.coverage_name' => ['required_with:coverages', 'string', 'max:255'],
            'coverages.*.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $product = DB::transaction(function () use ($validated): object {
                $partnerId = $this->partnerId($validated['insurance_partner_public_id'] ?? null);
                $id = DB::table('insurance_products')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_partner_id' => $partnerId,
                    'code' => (string) $validated['code'],
                    'name' => (string) $validated['name'],
                    'product_type' => (string) $validated['product_type'],
                    'premium_calculation_type' => $this->nullableString($validated['premium_calculation_type'] ?? null),
                    'premium_rate' => $this->nullableString($validated['premium_rate'] ?? null),
                    'fixed_premium_minor' => $this->nullableInt($validated['fixed_premium_minor'] ?? null),
                    'currency' => $this->stringValue($validated['currency'] ?? 'XAF', 'XAF'),
                    'payment_mode' => $this->nullableString($validated['payment_mode'] ?? null),
                    'is_refundable' => (bool) ($validated['is_refundable'] ?? false),
                    'status' => $this->stringValue($validated['status'] ?? 'active', 'active'),
                    'approval_status' => 'draft',
                    'business_model' => $this->nullableString($validated['business_model'] ?? null),
                    'report_category' => $this->nullableString($validated['report_category'] ?? null),
                    'new_business_enabled' => (bool) ($validated['new_business_enabled'] ?? true),
                    'rules' => $this->jsonOrNull($validated['rules'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($this->coverages($validated['coverages'] ?? []) as $coverage) {
                    DB::table('insurance_product_coverages')->insert([
                        'insurance_product_id' => $id,
                        'coverage_code' => $coverage['coverage_code'],
                        'coverage_name' => $coverage['coverage_name'],
                        'description' => $coverage['description'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $this->reload('insurance_products', $id, 'Insurance product could not be reloaded.');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_product' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.product.created', actor: $actor, properties: [
            'product_public_id' => $this->rowString($product, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->productPayload($product), 'Insurance product created successfully');
    }

    public function storeRuleVersion(Request $request, string $productPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'calculation_type' => ['required', 'string', Rule::in(['percentage', 'flat_rate', 'bracketed'])],
            'base_description' => ['sometimes', 'nullable', 'string', 'max:128'],
            'rate' => ['required_without:fixed_premium_minor', 'nullable', 'numeric', 'min:0'],
            'fixed_premium_minor' => ['required_without:rate', 'nullable', 'integer', 'min:1'],
            'cap_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'floor_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'frequency' => ['sometimes', Rule::in(['one_time', 'monthly', 'quarterly', 'annual'])],
            'source_reference' => ['sometimes', 'nullable', 'string', 'max:512'],
            'effective_from' => ['required', 'date'],
            'effective_until' => ['sometimes', 'nullable', 'date', 'after:effective_from'],
            'splits' => ['sometimes', 'array'],
            'splits.*.split_type' => ['required_with:splits', 'string', Rule::in(['insurer_payable', 'commission_income', 'tax_fee', 'institution_income'])],
            'splits.*.calculation_type' => ['required_with:splits', Rule::in(['percentage', 'fixed'])],
            'splits.*.rate' => ['nullable', 'numeric', 'min:0'],
            'splits.*.fixed_minor' => ['nullable', 'integer', 'min:0'],
            'splits.*.ledger_account_public_id' => ['nullable', 'string', 'exists:ledger_accounts,public_id'],
        ])->validate();

        try {
            $version = DB::transaction(function () use ($actor, $productPublicId, $validated): object {
                $product = DB::table('insurance_products')->where('public_id', $productPublicId)->lockForUpdate()->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Insurance product not found.');
                }
                if (! in_array($this->rowString($product, 'product_type'), self::PRODUCT_TYPES, true)) {
                    throw new InvalidArgumentException('Product type must be one of the approved product families.');
                }

                $maxVersion = DB::table('insurance_product_rule_versions')
                    ->where('insurance_product_id', $this->rowInt($product, 'id'))
                    ->max('version_number');
                $id = DB::table('insurance_product_rule_versions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_product_id' => $this->rowInt($product, 'id'),
                    'version_number' => (is_numeric($maxVersion) ? (int) $maxVersion : 0) + 1,
                    'calculation_type' => (string) $validated['calculation_type'],
                    'base_description' => $this->nullableString($validated['base_description'] ?? null),
                    'rate' => $this->nullableString($validated['rate'] ?? null),
                    'fixed_premium_minor' => $this->nullableInt($validated['fixed_premium_minor'] ?? null),
                    'cap_minor' => $this->nullableInt($validated['cap_minor'] ?? null),
                    'floor_minor' => $this->nullableInt($validated['floor_minor'] ?? null),
                    'frequency' => $this->stringValue($validated['frequency'] ?? 'one_time', 'one_time'),
                    'source_reference' => $this->nullableString($validated['source_reference'] ?? null),
                    'effective_from' => (string) $validated['effective_from'],
                    'effective_until' => $this->nullableString($validated['effective_until'] ?? null),
                    'status' => 'draft',
                    'created_by_user_id' => $actor->id,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($this->ruleVersionSplits($validated['splits'] ?? []) as $split) {
                    DB::table('insurance_product_rule_version_splits')->insert([
                        'insurance_product_rule_version_id' => $id,
                        'split_type' => $split['split_type'],
                        'calculation_type' => $split['calculation_type'],
                        'rate' => $split['rate'],
                        'fixed_minor' => $split['fixed_minor'],
                        'ledger_account_id' => $this->splitLedgerId($split['ledger_account_public_id']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                return $this->reload('insurance_product_rule_versions', $id, 'Rule version could not be reloaded.');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['rule_version' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.product.rule_version.created', actor: $actor, properties: [
            'product_public_id' => $productPublicId,
            'version_public_id' => $this->rowString($version, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->ruleVersionPayload($version), 'Insurance product rule version created successfully');
    }

    public function approveRuleVersion(Request $request, string $versionPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $version = DB::transaction(function () use ($actor, $versionPublicId): object {
                $version = DB::table('insurance_product_rule_versions')->where('public_id', $versionPublicId)->lockForUpdate()->first();
                if (! is_object($version)) {
                    throw new InvalidArgumentException('Rule version not found.');
                }
                if ($this->rowString($version, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft rule versions can be approved.');
                }
                if ($this->rowInt($version, 'created_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The creator cannot approve their own rule version.');
                }

                $overlaps = DB::table('insurance_product_rule_versions')
                    ->where('insurance_product_id', $this->rowInt($version, 'insurance_product_id'))
                    ->where('status', 'approved')
                    ->where('id', '!=', $this->rowInt($version, 'id'))
                    ->where(function ($query) use ($version): void {
                        $query->whereNull('effective_until')
                            ->orWhere('effective_until', '>=', $this->rowString($version, 'effective_from'));
                    });
                $effectiveUntil = $this->rowNullableString($version, 'effective_until');
                if ($effectiveUntil !== null) {
                    $overlaps->where('effective_from', '<=', $effectiveUntil);
                }
                if ($overlaps->exists()) {
                    throw new InvalidArgumentException('Approving this version would create overlapping active rule versions.');
                }

                DB::table('insurance_product_rule_versions')->where('id', $this->rowInt($version, 'id'))->update([
                    'status' => 'approved',
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->reload('insurance_product_rule_versions', $this->rowInt($version, 'id'), 'Rule version could not be reloaded after approval.');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['rule_version' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.product.rule_version.approved', actor: $actor, properties: [
            'version_public_id' => $versionPublicId,
        ], request: $request);

        return $this->respondSuccess($this->ruleVersionPayload($version), 'Rule version approved successfully');
    }

    public function activateProduct(Request $request, string $productPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $product = DB::transaction(function () use ($productPublicId): object {
                $product = DB::table('insurance_products')->where('public_id', $productPublicId)->lockForUpdate()->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Insurance product not found.');
                }
                if ($this->rowString($product, 'approval_status') === 'approved') {
                    throw new InvalidArgumentException('Product is already approved/active.');
                }
                $failures = $this->productReadiness->activationFailures($product);
                if ($failures !== []) {
                    throw new InvalidArgumentException('Product readiness check failed: '.implode('; ', $failures).'.');
                }

                DB::table('insurance_products')->where('id', $this->rowInt($product, 'id'))->update([
                    'approval_status' => 'approved',
                    'updated_at' => now(),
                ]);

                return $this->reload('insurance_products', $this->rowInt($product, 'id'), 'Product could not be reloaded.');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_product' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.product.activated', actor: $actor, properties: [
            'product_public_id' => $productPublicId,
        ], request: $request);

        return $this->respondSuccess($this->productPayload($product), 'Insurance product activated successfully');
    }

    public function storeEvidenceConfig(Request $request, string $productPublicId): JsonResponse
    {
        $actor = $this->actor($request);
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'claim_type' => ['required', 'string', 'max:64'],
            'document_type' => ['required', 'string', 'max:64'],
            'is_required' => ['sometimes', 'boolean'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $config = DB::transaction(function () use ($productPublicId, $validated): object {
                $product = DB::table('insurance_products')->where('public_id', $productPublicId)->first(['id']);
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Insurance product not found.');
                }

                $existing = DB::table('insurance_claim_evidence_configs')
                    ->where('insurance_product_id', $this->rowInt($product, 'id'))
                    ->where('claim_type', (string) $validated['claim_type'])
                    ->where('document_type', (string) $validated['document_type'])
                    ->first(['id']);
                if (is_object($existing)) {
                    DB::table('insurance_claim_evidence_configs')->where('id', $this->rowInt($existing, 'id'))->update([
                        'is_required' => (bool) ($validated['is_required'] ?? true),
                        'description' => $this->nullableString($validated['description'] ?? null),
                        'updated_at' => now(),
                    ]);

                    return $this->reload('insurance_claim_evidence_configs', $this->rowInt($existing, 'id'), 'Evidence config could not be reloaded.');
                }

                $id = DB::table('insurance_claim_evidence_configs')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_product_id' => $this->rowInt($product, 'id'),
                    'claim_type' => (string) $validated['claim_type'],
                    'document_type' => (string) $validated['document_type'],
                    'is_required' => (bool) ($validated['is_required'] ?? true),
                    'description' => $this->nullableString($validated['description'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->reload('insurance_claim_evidence_configs', $id, 'Evidence config could not be reloaded.');
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['evidence_config' => [$exception->getMessage()]]);
        }

        return $this->respondCreated([
            'public_id' => $this->rowString($config, 'public_id'),
            'claim_type' => $this->rowString($config, 'claim_type'),
            'document_type' => $this->rowString($config, 'document_type'),
            'is_required' => (bool) (((array) $config)['is_required'] ?? true),
        ], 'Evidence requirement configured successfully');
    }

    private function actor(Request $request): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasPermissionTo('insurance.products.manage') ? $actor : null;
    }

    private function agencyId(mixed $publicId): ?int
    {
        return $this->resolveId('agencies', $publicId);
    }

    private function partnerId(mixed $publicId): ?int
    {
        return $this->resolveId('insurance_partners', $publicId);
    }

    private function ledgerAccountId(mixed $publicId, ?int $agencyId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $query = DB::table('ledger_accounts')->where('public_id', $publicId)->where('status', LedgerAccount::STATUS_ACTIVE);
        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        }
        $ledger = $query->first(['id']);
        if (! is_object($ledger)) {
            throw new InvalidArgumentException('Ledger account must be active and belong to the selected agency.');
        }

        return $this->rowInt($ledger, 'id');
    }

    private function splitLedgerId(?string $publicId): ?int
    {
        if ($publicId === null) {
            return null;
        }
        $ledger = DB::table('ledger_accounts')->where('public_id', $publicId)->where('status', LedgerAccount::STATUS_ACTIVE)->first(['id']);
        if (! is_object($ledger)) {
            throw new InvalidArgumentException('Split ledger account must be active.');
        }

        return $this->rowInt($ledger, 'id');
    }

    private function resolveId(string $table, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);

        return is_object($row) ? $this->rowInt($row, 'id') : null;
    }

    private function reload(string $table, int $id, string $message): object
    {
        $row = DB::table($table)->where('id', $id)->first();
        if (! is_object($row)) {
            throw new InvalidArgumentException($message);
        }

        return $row;
    }

    /**
     * @return list<array{coverage_code:string, coverage_name:string, description:?string}>
     */
    private function coverages(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $coverages = [];
        foreach ($value as $coverage) {
            if (! is_array($coverage)) {
                continue;
            }
            $coverages[] = [
                'coverage_code' => $this->stringValue($coverage['coverage_code'] ?? '', ''),
                'coverage_name' => $this->stringValue($coverage['coverage_name'] ?? '', ''),
                'description' => $this->nullableString($coverage['description'] ?? null),
            ];
        }

        return $coverages;
    }

    /**
     * @return list<array{split_type:string, calculation_type:string, rate:?string, fixed_minor:?int, ledger_account_public_id:?string}>
     */
    private function ruleVersionSplits(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $splits = [];
        foreach ($value as $split) {
            if (! is_array($split)) {
                continue;
            }
            $splits[] = [
                'split_type' => $this->stringValue($split['split_type'] ?? '', ''),
                'calculation_type' => $this->stringValue($split['calculation_type'] ?? '', ''),
                'rate' => $this->nullableString($split['rate'] ?? null),
                'fixed_minor' => $this->nullableInt($split['fixed_minor'] ?? null),
                'ledger_account_public_id' => $this->nullableString($split['ledger_account_public_id'] ?? null),
            ];
        }

        return $splits;
    }

    /**
     * @return array<string, mixed>
     */
    private function partnerPayload(object $partner): array
    {
        return [
            'public_id' => $this->rowString($partner, 'public_id'),
            'code' => $this->rowString($partner, 'code'),
            'name' => $this->rowString($partner, 'name'),
            'status' => $this->rowString($partner, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productPayload(object $product): array
    {
        return [
            'public_id' => $this->rowString($product, 'public_id'),
            'code' => $this->rowString($product, 'code'),
            'name' => $this->rowString($product, 'name'),
            'product_type' => $this->rowString($product, 'product_type'),
            'business_model' => $this->rowNullableString($product, 'business_model'),
            'report_category' => $this->rowNullableString($product, 'report_category'),
            'new_business_enabled' => (bool) (((array) $product)['new_business_enabled'] ?? true),
            'currency' => $this->rowString($product, 'currency'),
            'premium_calculation_type' => $this->rowNullableString($product, 'premium_calculation_type'),
            'premium_rate' => $this->rowNullableString($product, 'premium_rate'),
            'fixed_premium_minor' => $this->rowNullableInt($product, 'fixed_premium_minor'),
            'payment_mode' => $this->rowNullableString($product, 'payment_mode'),
            'is_refundable' => (bool) (((array) $product)['is_refundable'] ?? false),
            'status' => $this->rowString($product, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleVersionPayload(object $version): array
    {
        return [
            'public_id' => $this->rowString($version, 'public_id'),
            'version_number' => $this->rowInt($version, 'version_number'),
            'calculation_type' => $this->rowString($version, 'calculation_type'),
            'base_description' => $this->rowNullableString($version, 'base_description'),
            'rate' => $this->rowNullableString($version, 'rate'),
            'fixed_premium_minor' => $this->rowNullableInt($version, 'fixed_premium_minor'),
            'frequency' => $this->rowString($version, 'frequency'),
            'effective_from' => $this->rowString($version, 'effective_from'),
            'effective_until' => $this->rowNullableString($version, 'effective_until'),
            'status' => $this->rowString($version, 'status'),
        ];
    }

    private function jsonOrNull(mixed $value): ?string
    {
        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_scalar($value) ? (string) $value : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function rowString(object $row, string $key): string
    {
        return (string) (((array) $row)[$key] ?? '');
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        return (int) (((array) $row)[$key] ?? 0);
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
