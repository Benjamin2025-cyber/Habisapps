<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class InsuranceController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
    ) {}

    public function storePartner(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
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

                $partner = DB::table('insurance_partners')->where('id', $id)->first();
                if (! is_object($partner)) {
                    throw new InvalidArgumentException('Insurance partner could not be reloaded.');
                }

                return $partner;
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
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'insurance_partner_public_id' => ['sometimes', 'nullable', 'string', 'exists:insurance_partners,public_id'],
            'code' => ['required', 'string', 'max:64', 'unique:insurance_products,code'],
            'name' => ['required', 'string', 'max:255'],
            'product_type' => ['required', 'string', 'max:64'],
            'premium_calculation_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'premium_rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fixed_premium_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'payment_mode' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_refundable' => ['sometimes', 'boolean'],
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

                $product = DB::table('insurance_products')->where('id', $id)->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Insurance product could not be reloaded.');
                }

                return $product;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_product' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.product.created', actor: $actor, properties: [
            'product_public_id' => $this->rowString($product, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->productPayload($product), 'Insurance product created successfully');
    }

    public function storeClaim(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'insurance_subscription_public_id' => ['required', 'string', 'exists:insurance_subscriptions,public_id'],
            'claim_type' => ['required', 'string', 'max:64'],
            'incident_date' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'claimed_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ])->validate();

        try {
            $claim = DB::transaction(function () use ($validated): object {
                $subscription = DB::table('insurance_subscriptions')
                    ->where('public_id', (string) $validated['insurance_subscription_public_id'])
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription is invalid.');
                }

                $currency = $this->stringValue($validated['currency'] ?? $this->rowString($subscription, 'currency'), 'XAF');
                if ($currency !== $this->rowString($subscription, 'currency')) {
                    throw new InvalidArgumentException('Claim currency must match the insurance subscription currency.');
                }

                $id = DB::table('insurance_claims')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'client_id' => $this->rowInt($subscription, 'client_id'),
                    'agency_id' => $this->rowInt($subscription, 'agency_id'),
                    'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
                    'claim_number' => 'CLM-'.Str::ulid(),
                    'claim_type' => (string) $validated['claim_type'],
                    'incident_date' => $this->nullableString($validated['incident_date'] ?? null),
                    'description' => $this->nullableString($validated['description'] ?? null),
                    'status' => 'pending',
                    'claimed_amount_minor' => $this->nullableInt($validated['claimed_amount_minor'] ?? null),
                    'indemnified_amount_minor' => null,
                    'currency' => $currency,
                    'settled_at' => null,
                    'journal_entry_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $claim = DB::table('insurance_claims')->where('id', $id)->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim could not be reloaded.');
                }

                return $claim;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.created', actor: $actor, properties: [
            'claim_public_id' => $this->rowString($claim, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->claimPayload($claim), 'Insurance claim created successfully');
    }

    public function storeSubscription(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'client_public_id' => ['required', 'string', 'exists:clients,public_id'],
            'agency_public_id' => ['required', 'string', 'exists:agencies,public_id'],
            'insurance_product_public_id' => ['required', 'string', 'exists:insurance_products,public_id'],
            'subscription_number' => ['sometimes', 'nullable', 'string', 'max:64', 'unique:insurance_subscriptions,subscription_number'],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
            'coverage_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'suspended', 'cancelled', 'expired'])],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $subscription = DB::transaction(function () use ($validated): object {
                $agency = DB::table('agencies')->where('public_id', (string) $validated['agency_public_id'])->first(['id']);
                $client = DB::table('clients')->where('public_id', (string) $validated['client_public_id'])->first(['id', 'agency_id']);
                $product = DB::table('insurance_products')->where('public_id', (string) $validated['insurance_product_public_id'])->where('status', 'active')->first(['id', 'currency']);
                if (! is_object($agency) || ! is_object($client) || ! is_object($product)) {
                    throw new InvalidArgumentException('Client, agency, and active insurance product are required.');
                }
                if ($this->rowInt($client, 'agency_id') !== $this->rowInt($agency, 'id')) {
                    throw new InvalidArgumentException('Insurance subscription client must belong to the selected agency.');
                }

                $currency = $this->stringValue($validated['currency'] ?? $this->rowString($product, 'currency'), 'XAF');
                if ($currency !== $this->rowString($product, 'currency')) {
                    throw new InvalidArgumentException('Insurance subscription currency must match the product currency.');
                }

                $id = DB::table('insurance_subscriptions')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'client_id' => $this->rowInt($client, 'id'),
                    'agency_id' => $this->rowInt($agency, 'id'),
                    'loan_id' => null,
                    'insurance_product_id' => $this->rowInt($product, 'id'),
                    'subscription_number' => $this->stringValue($validated['subscription_number'] ?? null, 'INS-SUB-'.Str::ulid()),
                    'starts_on' => $this->nullableString($validated['starts_on'] ?? null),
                    'ends_on' => $this->nullableString($validated['ends_on'] ?? null),
                    'coverage_amount_minor' => $this->nullableInt($validated['coverage_amount_minor'] ?? null),
                    'currency' => $currency,
                    'status' => $this->stringValue($validated['status'] ?? 'active', 'active'),
                    'metadata' => $this->jsonOrNull($validated['metadata'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $subscription = DB::table('insurance_subscriptions')->where('id', $id)->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription could not be reloaded.');
                }

                return $subscription;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_subscription' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.subscription.created', actor: $actor, properties: [
            'subscription_public_id' => $this->rowString($subscription, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->subscriptionPayload($subscription), 'Insurance subscription created successfully');
    }

    public function decideClaim(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approve', 'reject', 'settle'])],
            'indemnified_amount_minor' => ['required_if:decision,approve,settle', 'nullable', 'integer', 'min:0'],
            'settled_on' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        try {
            $claim = DB::transaction(function () use ($claimPublicId, $validated): object {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                $decision = (string) $validated['decision'];
                $status = match ($decision) {
                    'approve' => 'approved',
                    'reject' => 'rejected',
                    'settle' => 'settled',
                    default => throw new InvalidArgumentException('Unsupported claim decision.'),
                };

                DB::table('insurance_claims')
                    ->where('id', $this->rowInt($claim, 'id'))
                    ->update([
                        'status' => $status,
                        'indemnified_amount_minor' => $decision === 'reject' ? null : $this->nullableInt($validated['indemnified_amount_minor'] ?? null),
                        'settled_at' => $decision === 'settle' ? $this->stringValue($validated['settled_on'] ?? now()->toDateString(), now()->toDateString()) : null,
                        'updated_at' => now(),
                    ]);

                $updated = DB::table('insurance_claims')->where('id', $this->rowInt($claim, 'id'))->first();
                if (! is_object($updated)) {
                    throw new InvalidArgumentException('Insurance claim could not be reloaded.');
                }

                return $updated;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.decided', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
            'status' => $this->rowString($claim, 'status'),
        ], request: $request);

        return $this->respondSuccess($this->claimPayload($claim), 'Insurance claim decision recorded successfully');
    }

    private function agencyId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $agency = DB::table('agencies')->where('public_id', $publicId)->first(['id']);

        return is_object($agency) ? $this->rowInt($agency, 'id') : null;
    }

    private function ledgerAccountId(mixed $publicId, ?int $agencyId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $query = DB::table('ledger_accounts')
            ->where('public_id', $publicId)
            ->where('status', 'active');
        if ($agencyId !== null) {
            $query->where('agency_id', $agencyId);
        }

        $ledger = $query->first(['id']);
        if (! is_object($ledger)) {
            throw new InvalidArgumentException('Insurance partner ledger account must be active and agency-scoped.');
        }

        return $this->rowInt($ledger, 'id');
    }

    private function partnerId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }

        $partner = DB::table('insurance_partners')->where('public_id', $publicId)->where('status', 'active')->first(['id']);
        if (! is_object($partner)) {
            throw new InvalidArgumentException('Insurance partner must be active.');
        }

        return $this->rowInt($partner, 'id');
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
            'premium_calculation_type' => $this->rowNullableString($product, 'premium_calculation_type'),
            'premium_rate' => $this->rowNullableString($product, 'premium_rate'),
            'fixed_premium_minor' => $this->rowNullableInt($product, 'fixed_premium_minor'),
            'currency' => $this->rowString($product, 'currency'),
            'payment_mode' => $this->rowNullableString($product, 'payment_mode'),
            'is_refundable' => (bool) (((array) $product)['is_refundable'] ?? false),
            'status' => $this->rowString($product, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriptionPayload(object $subscription): array
    {
        return [
            'public_id' => $this->rowString($subscription, 'public_id'),
            'subscription_number' => $this->rowString($subscription, 'subscription_number'),
            'starts_on' => $this->rowNullableString($subscription, 'starts_on'),
            'ends_on' => $this->rowNullableString($subscription, 'ends_on'),
            'coverage_amount_minor' => $this->rowNullableInt($subscription, 'coverage_amount_minor'),
            'currency' => $this->rowString($subscription, 'currency'),
            'status' => $this->rowString($subscription, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claimPayload(object $claim): array
    {
        return [
            'public_id' => $this->rowString($claim, 'public_id'),
            'claim_number' => $this->rowString($claim, 'claim_number'),
            'claim_type' => $this->rowString($claim, 'claim_type'),
            'incident_date' => $this->rowNullableString($claim, 'incident_date'),
            'description' => $this->rowNullableString($claim, 'description'),
            'status' => $this->rowString($claim, 'status'),
            'claimed_amount_minor' => $this->rowNullableInt($claim, 'claimed_amount_minor'),
            'indemnified_amount_minor' => $this->rowNullableInt($claim, 'indemnified_amount_minor'),
            'currency' => $this->rowString($claim, 'currency'),
            'settled_at' => $this->rowNullableString($claim, 'settled_at'),
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
