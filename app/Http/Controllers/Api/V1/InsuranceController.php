<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\Finance\PhysicalCashAmount;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
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
        private readonly AccountingBalanceCalculator $balanceCalculator,
        private readonly StaffAgencyScope $staffAgencyScope,
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

    public function storePremiumAssessment(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'premium_amount_minor' => ['required', 'integer', 'min:1'],
            'due_on' => ['required', 'date'],
            'base_amount_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'rate' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ])->validate();

        try {
            $assessment = DB::transaction(function () use ($subscriptionPublicId, $validated): object {
                $subscription = DB::table('insurance_subscriptions')
                    ->where('public_id', $subscriptionPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription is invalid.');
                }

                if ($this->rowString($subscription, 'status') !== 'active') {
                    throw new InvalidArgumentException('Premium assessments can only be created on active subscriptions.');
                }

                $subscriptionCurrency = $this->rowString($subscription, 'currency');
                $currency = $this->stringValue($validated['currency'] ?? $subscriptionCurrency, $subscriptionCurrency);
                if ($currency !== $subscriptionCurrency) {
                    throw new InvalidArgumentException('Premium assessment currency must match the subscription currency.');
                }

                $id = DB::table('insurance_premium_assessments')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
                    'loan_id' => null,
                    'base_amount_minor' => $this->nullableInt($validated['base_amount_minor'] ?? null),
                    'rate' => $this->nullableString($validated['rate'] ?? null),
                    'premium_amount_minor' => (int) $validated['premium_amount_minor'],
                    'currency' => $currency,
                    'due_on' => (string) $validated['due_on'],
                    'assessed_at' => now(),
                    'status' => 'assessed',
                    'journal_entry_id' => null,
                    'metadata' => $this->jsonOrNull($validated['metadata'] ?? null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('insurance_premium_assessments')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Insurance premium assessment could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_premium_assessment' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.premium_assessment.created', actor: $actor, properties: [
            'subscription_public_id' => $subscriptionPublicId,
            'assessment_public_id' => $this->rowString($assessment, 'public_id'),
        ], request: $request);

        return $this->respondCreated(
            $this->premiumAssessmentPayload($assessment, $subscriptionPublicId),
            'Insurance premium assessment created successfully',
        );
    }

    public function collectPremiumFromAccount(Request $request, string $assessmentPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'customer_account_public_id' => ['required', 'string', 'exists:customer_accounts,public_id'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $assessmentPublicId, $validated): array {
                $assessment = DB::table('insurance_premium_assessments')
                    ->where('public_id', $assessmentPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($assessment)) {
                    throw new InvalidArgumentException('Insurance premium assessment is invalid.');
                }

                if ($this->rowString($assessment, 'status') !== 'assessed') {
                    throw new InvalidArgumentException('Only assessed insurance premiums can be collected.');
                }

                $amountMinor = $this->rowInt($assessment, 'premium_amount_minor');
                if ($amountMinor <= 0) {
                    throw new InvalidArgumentException('Insurance premium amount must be positive before collection.');
                }

                $subscription = DB::table('insurance_subscriptions')
                    ->where('id', $this->rowInt($assessment, 'insurance_subscription_id'))
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription is invalid.');
                }

                $currency = $this->rowString($assessment, 'currency');
                $customerAccountPublicId = $this->stringValue($validated['customer_account_public_id'] ?? null, '');
                DB::table('customer_accounts')
                    ->where('public_id', $customerAccountPublicId)
                    ->lockForUpdate()
                    ->first(['id']);
                $customerAccount = CustomerAccount::query()
                    ->with(['ledgerAccount'])
                    ->where('public_id', $customerAccountPublicId)
                    ->first();
                if (! $customerAccount instanceof CustomerAccount
                    || $customerAccount->status !== CustomerAccount::STATUS_ACTIVE) {
                    throw new InvalidArgumentException('Collection account must be active.');
                }
                if ($customerAccount->client_id !== $this->rowInt($subscription, 'client_id')) {
                    throw new InvalidArgumentException('Collection account must belong to the subscription client.');
                }
                if ($customerAccount->agency_id !== $this->rowInt($subscription, 'agency_id')) {
                    throw new InvalidArgumentException('Collection account must belong to the subscription agency.');
                }
                if ($customerAccount->currency !== $currency) {
                    throw new InvalidArgumentException('Collection account currency must match the premium currency.');
                }

                $customerLedger = $customerAccount->ledgerAccount;
                if (! $customerLedger instanceof LedgerAccount
                    || $customerLedger->status !== LedgerAccount::STATUS_ACTIVE
                    || $customerLedger->agency_id !== $customerAccount->agency_id) {
                    throw new InvalidArgumentException('Collection account ledger must be active and agency-scoped.');
                }

                $creditLedgerId = $this->insurancePremiumCollectionCreditLedgerId(
                    $this->rowInt($subscription, 'agency_id'),
                    $currency,
                );

                $availableBalance = $this->balanceCalculator->availableForCustomerAccount($customerAccount, $currency)['available_balance_minor'];
                if ($amountMinor > $availableBalance) {
                    throw new InvalidArgumentException('Insurance premium collection exceeds the customer account available balance.');
                }

                $paidDate = is_string($validated['paid_on'] ?? null) && $validated['paid_on'] !== ''
                    ? $validated['paid_on']
                    : now()->toDateString();
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'insurance-premium-collection:'.$assessmentPublicId;
                $reference = 'IPC-'.Str::upper(Str::random(10));

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $reference,
                    'business_date' => $paidDate,
                    'posted_at' => null,
                    'agency_id' => $this->rowInt($subscription, 'agency_id'),
                    'source_module' => 'insurance',
                    'source_type' => 'insurance_premium_payment',
                    'source_public_id' => $assessmentPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => is_string($validated['notes'] ?? null) && $validated['notes'] !== ''
                        ? $validated['notes']
                        : 'Standalone insurance premium collection',
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $this->rowInt($subscription, 'agency_id'),
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $customerLedger->id,
                    'customer_account_id' => $customerAccount->id,
                    'loan_id' => null,
                    'debit_minor' => $amountMinor,
                    'credit_minor' => 0,
                    'currency' => $currency,
                    'line_memo' => 'Insurance premium debited from customer account',
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $this->rowInt($subscription, 'agency_id'),
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $creditLedgerId,
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => 0,
                    'credit_minor' => $amountMinor,
                    'currency' => $currency,
                    'line_memo' => 'Insurance premium collected',
                ]);

                $this->postSystemJournal($journalEntry, $actor);

                $paymentId = DB::table('insurance_premium_payments')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_premium_assessment_id' => $this->rowInt($assessment, 'id'),
                    'customer_account_id' => $customerAccount->id,
                    'teller_transaction_id' => null,
                    'journal_entry_id' => $journalEntry->id,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'payment_method' => 'customer_account',
                    'paid_at' => now(),
                    'status' => 'posted',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('insurance_premium_assessments')
                    ->where('id', $this->rowInt($assessment, 'id'))
                    ->update([
                        'status' => 'paid',
                        'journal_entry_id' => $journalEntry->id,
                        'updated_at' => now(),
                    ]);

                $reloadedAssessment = DB::table('insurance_premium_assessments')->where('id', $this->rowInt($assessment, 'id'))->first();
                $payment = DB::table('insurance_premium_payments')->where('id', $paymentId)->first();
                if (! is_object($reloadedAssessment) || ! is_object($payment)) {
                    throw new InvalidArgumentException('Collected insurance premium could not be reloaded.');
                }

                return [
                    'assessment' => $reloadedAssessment,
                    'payment' => $payment,
                    'subscription_public_id' => $this->rowString($subscription, 'public_id'),
                    'journal_entry_public_id' => $journalEntry->public_id,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_premium_collection' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.premium.collected_from_account', actor: $actor, properties: [
            'assessment_public_id' => $assessmentPublicId,
            'journal_entry_public_id' => $result['journal_entry_public_id'],
        ], request: $request);

        return $this->respondSuccess([
            'assessment' => $this->premiumAssessmentPayload($result['assessment'], $result['subscription_public_id']),
            'payment' => $this->premiumPaymentPayload($result['payment']),
            'journal_entry_public_id' => $result['journal_entry_public_id'],
        ], 'Insurance premium collected successfully');
    }

    public function collectPremiumCash(Request $request, string $assessmentPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'teller_session_public_id' => ['required', 'string', 'exists:teller_sessions,public_id'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $assessmentPublicId, $validated): array {
                $assessment = DB::table('insurance_premium_assessments')
                    ->where('public_id', $assessmentPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($assessment)) {
                    throw new InvalidArgumentException('Insurance premium assessment is invalid.');
                }

                if ($this->rowString($assessment, 'status') !== 'assessed') {
                    throw new InvalidArgumentException('Only assessed insurance premiums can be collected.');
                }

                $amountMinor = $this->rowInt($assessment, 'premium_amount_minor');
                if ($amountMinor <= 0) {
                    throw new InvalidArgumentException('Insurance premium amount must be positive before collection.');
                }

                $subscription = DB::table('insurance_subscriptions')
                    ->where('id', $this->rowInt($assessment, 'insurance_subscription_id'))
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription is invalid.');
                }

                $currency = $this->rowString($assessment, 'currency');
                if ($currency !== 'XAF') {
                    throw new InvalidArgumentException('Teller cash collection requires XAF unless explicitly supported by the teller session.');
                }

                if (! PhysicalCashAmount::validMinorAmount($amountMinor, $currency)) {
                    throw new InvalidArgumentException(PhysicalCashAmount::validationMessage($currency));
                }

                $agencyId = $this->rowInt($subscription, 'agency_id');

                $sessionPublicId = $this->stringValue($validated['teller_session_public_id'] ?? null, '');
                DB::table('teller_sessions')
                    ->where('public_id', $sessionPublicId)
                    ->lockForUpdate()
                    ->first(['id']);
                $session = TellerSession::query()
                    ->with(['till'])
                    ->where('public_id', $sessionPublicId)
                    ->first();
                if (! $session instanceof TellerSession
                    || $session->status !== TellerSession::STATUS_OPEN
                    || $session->agency_id !== $agencyId
                    || $session->currency !== $currency) {
                    throw new InvalidArgumentException('Teller session must be open and belong to the subscription agency and currency.');
                }

                $till = $session->till;
                if (! $till instanceof Till
                    || $till->status !== Till::STATUS_ACTIVE
                    || $till->daily_state !== Till::DAILY_STATE_OPEN
                    || $till->agency_id !== $agencyId
                    || $till->currency !== $currency
                    || $till->ledger_account_id === null) {
                    throw new InvalidArgumentException('Open teller till with an active cash ledger is required for cash premium collection.');
                }

                $tillLedger = LedgerAccount::query()->whereKey($till->ledger_account_id)->first();
                if (! $tillLedger instanceof LedgerAccount
                    || $tillLedger->status !== LedgerAccount::STATUS_ACTIVE
                    || $tillLedger->agency_id !== $agencyId) {
                    throw new InvalidArgumentException('Till cash ledger account must be active and belong to the subscription agency.');
                }

                $creditLedgerId = $this->insurancePremiumCollectionCreditLedgerId($agencyId, $currency);

                $paidDate = is_string($validated['paid_on'] ?? null) && $validated['paid_on'] !== ''
                    ? $validated['paid_on']
                    : now()->toDateString();
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'insurance-premium-cash:'.$assessmentPublicId;
                $reference = 'IPC-CASH-'.Str::upper(Str::random(10));

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $reference,
                    'business_date' => $paidDate,
                    'posted_at' => null,
                    'agency_id' => $agencyId,
                    'source_module' => 'insurance',
                    'source_type' => 'insurance_premium_cash_payment',
                    'source_public_id' => $assessmentPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => is_string($validated['notes'] ?? null) && $validated['notes'] !== ''
                        ? $validated['notes']
                        : 'Standalone insurance premium cash collection',
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $tillLedger->id,
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => $amountMinor,
                    'credit_minor' => 0,
                    'currency' => $currency,
                    'line_memo' => 'Insurance premium received in teller till',
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $creditLedgerId,
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => 0,
                    'credit_minor' => $amountMinor,
                    'currency' => $currency,
                    'line_memo' => 'Insurance premium collected (cash)',
                ]);

                $tellerReference = 'IPC-CASH-'.Str::upper(Str::random(8));
                $tellerTransaction = TellerTransaction::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'teller_session_id' => $session->id,
                    'agency_id' => $agencyId,
                    'transaction_date' => $paidDate,
                    'till_id' => $till->id,
                    'transaction_type' => TellerTransaction::TYPE_CASH_DEPOSIT,
                    'client_id' => $this->rowInt($subscription, 'client_id'),
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'status' => TellerTransaction::STATUS_POSTED,
                    'reference' => $tellerReference,
                    'event_number' => $tellerReference,
                    'idempotency_key' => $idempotencyKey,
                    'journal_entry_id' => $journalEntry->id,
                    'operation_code' => 'insurance_premium_collection',
                    'description' => is_string($validated['notes'] ?? null) && $validated['notes'] !== ''
                        ? $validated['notes']
                        : 'Insurance premium cash collection',
                ]);

                $this->postSystemJournal($journalEntry, $actor);

                $paymentId = DB::table('insurance_premium_payments')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_premium_assessment_id' => $this->rowInt($assessment, 'id'),
                    'customer_account_id' => null,
                    'teller_transaction_id' => $tellerTransaction->id,
                    'journal_entry_id' => $journalEntry->id,
                    'amount_minor' => $amountMinor,
                    'currency' => $currency,
                    'payment_method' => 'teller_cash',
                    'paid_at' => now(),
                    'status' => 'posted',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('insurance_premium_assessments')
                    ->where('id', $this->rowInt($assessment, 'id'))
                    ->update([
                        'status' => 'paid',
                        'journal_entry_id' => $journalEntry->id,
                        'updated_at' => now(),
                    ]);

                $reloadedAssessment = DB::table('insurance_premium_assessments')->where('id', $this->rowInt($assessment, 'id'))->first();
                $payment = DB::table('insurance_premium_payments')->where('id', $paymentId)->first();
                if (! is_object($reloadedAssessment) || ! is_object($payment)) {
                    throw new InvalidArgumentException('Collected insurance premium could not be reloaded.');
                }

                return [
                    'assessment' => $reloadedAssessment,
                    'payment' => $payment,
                    'subscription_public_id' => $this->rowString($subscription, 'public_id'),
                    'journal_entry_public_id' => $journalEntry->public_id,
                    'teller_transaction_public_id' => $tellerTransaction->public_id,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_premium_cash_collection' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.premium.collected_cash', actor: $actor, properties: [
            'assessment_public_id' => $assessmentPublicId,
            'journal_entry_public_id' => $result['journal_entry_public_id'],
            'teller_transaction_public_id' => $result['teller_transaction_public_id'],
        ], request: $request);

        return $this->respondSuccess([
            'assessment' => $this->premiumAssessmentPayload($result['assessment'], $result['subscription_public_id']),
            'payment' => $this->premiumPaymentPayload($result['payment']),
            'journal_entry_public_id' => $result['journal_entry_public_id'],
            'teller_transaction_public_id' => $result['teller_transaction_public_id'],
        ], 'Insurance premium collected in cash successfully');
    }

    public function attachClaimDocument(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'document_public_id' => ['required', 'string', 'exists:documents,public_id'],
            'document_type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        try {
            $attachment = DB::transaction(function () use ($claimPublicId, $validated): array {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                $document = DB::table('documents')
                    ->where('public_id', (string) $validated['document_public_id'])
                    ->first();
                if (! is_object($document)) {
                    throw new InvalidArgumentException('Document is invalid.');
                }

                if ($this->rowInt($document, 'agency_id') !== $this->rowInt($claim, 'agency_id')) {
                    throw new InvalidArgumentException('Document must belong to the claim agency.');
                }

                $ownerType = $this->rowNullableString($document, 'owner_type');
                if ($ownerType !== null && $ownerType !== '') {
                    $ownerId = (int) (((array) $document)['owner_id'] ?? 0);
                    if ($ownerType === Client::class) {
                        if ($ownerId !== $this->rowInt($claim, 'client_id')) {
                            throw new InvalidArgumentException('Document is owned by another client.');
                        }
                    } else {
                        throw new InvalidArgumentException('Document owner is outside the allowed scope for claim evidence.');
                    }
                }

                $claimId = $this->rowInt($claim, 'id');
                $documentId = $this->rowInt($document, 'id');

                $existing = DB::table('insurance_claim_documents')
                    ->where('insurance_claim_id', $claimId)
                    ->where('document_id', $documentId)
                    ->first();

                $documentType = $this->nullableString($validated['document_type'] ?? null);
                $created = false;
                if (! is_object($existing)) {
                    DB::table('insurance_claim_documents')->insert([
                        'insurance_claim_id' => $claimId,
                        'document_id' => $documentId,
                        'document_type' => $documentType,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $created = true;
                }

                return [
                    'document_public_id' => $this->rowString($document, 'public_id'),
                    'document_type' => is_object($existing)
                        ? $this->rowNullableString($existing, 'document_type')
                        : $documentType,
                    'created' => $created,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim_document' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.document_attached', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
            'document_public_id' => $attachment['document_public_id'],
        ], request: $request);

        $status = $attachment['created'] ? 201 : 200;

        return $this->respondSuccess(
            data: [
                'document_public_id' => $attachment['document_public_id'],
                'document_type' => $attachment['document_type'],
            ],
            message: $attachment['created']
                ? 'Document attached to claim successfully'
                : 'Document already attached to claim',
            status: $status,
        );
    }

    public function decideClaim(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $this->securityAudit->record('insurance.claim.direct_decision_blocked', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
        ], request: $request);

        return $this->respondForbidden('Direct claim decisions are disabled. Use the maker-checker decision-request workflow.');
    }

    public function postClaimSettlement(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'business_date' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $claimPublicId, $validated): array {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                if ($this->rowString($claim, 'status') !== 'settled') {
                    throw new InvalidArgumentException('Only settled claims can have settlement accounting posted.');
                }

                if ($this->rowNullableInt($claim, 'journal_entry_id') !== null) {
                    throw new InvalidArgumentException('Settlement accounting has already been posted for this claim.');
                }

                $indemnifiedMinor = $this->rowNullableInt($claim, 'indemnified_amount_minor');
                if ($indemnifiedMinor === null || $indemnifiedMinor <= 0) {
                    throw new InvalidArgumentException('Settlement requires a positive indemnified amount.');
                }

                $subscription = DB::table('insurance_subscriptions')
                    ->where('id', $this->rowInt($claim, 'insurance_subscription_id'))
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription is invalid.');
                }

                $product = DB::table('insurance_products')
                    ->where('id', $this->rowInt($subscription, 'insurance_product_id'))
                    ->first();
                if (! is_object($product)) {
                    throw new InvalidArgumentException('Insurance product is invalid.');
                }

                $rules = $this->productRules($product);
                $businessModel = $this->stringValue($rules['business_model'] ?? null, '');
                if (! in_array($businessModel, ['broker', 'collector', 'risk_carrier'], true)) {
                    throw new InvalidArgumentException('Insurance product business model must be configured before settlement posting.');
                }

                $agencyId = $this->rowInt($claim, 'agency_id');
                $currency = $this->rowString($claim, 'currency');

                [$debitLedgerId, $creditLedgerId] = $this->insuranceClaimSettlementLedgers($agencyId, $currency);

                $businessDate = is_string($validated['business_date'] ?? null) && $validated['business_date'] !== ''
                    ? $validated['business_date']
                    : ($this->rowNullableString($claim, 'settled_at') ?? now()->toDateString());
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'insurance-claim-settlement:'.$claimPublicId;
                $reference = 'ICS-'.Str::upper(Str::random(10));

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $reference,
                    'business_date' => $businessDate,
                    'posted_at' => null,
                    'agency_id' => $agencyId,
                    'source_module' => 'insurance',
                    'source_type' => 'insurance_claim_settlement',
                    'source_public_id' => $claimPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => is_string($validated['notes'] ?? null) && $validated['notes'] !== ''
                        ? $validated['notes']
                        : 'Insurance claim settlement ('.$businessModel.')',
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $debitLedgerId,
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => $indemnifiedMinor,
                    'credit_minor' => 0,
                    'currency' => $currency,
                    'line_memo' => 'Insurance claim settlement debit ('.$businessModel.')',
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $agencyId,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $creditLedgerId,
                    'customer_account_id' => null,
                    'loan_id' => null,
                    'debit_minor' => 0,
                    'credit_minor' => $indemnifiedMinor,
                    'currency' => $currency,
                    'line_memo' => 'Insurance claim settlement credit ('.$businessModel.')',
                ]);

                $this->postSystemJournal($journalEntry, $actor);

                DB::table('insurance_claims')
                    ->where('id', $this->rowInt($claim, 'id'))
                    ->update([
                        'journal_entry_id' => $journalEntry->id,
                        'updated_at' => now(),
                    ]);

                $reloadedClaim = DB::table('insurance_claims')->where('id', $this->rowInt($claim, 'id'))->first();
                if (! is_object($reloadedClaim)) {
                    throw new InvalidArgumentException('Insurance claim could not be reloaded.');
                }

                return [
                    'claim' => $reloadedClaim,
                    'journal_entry_public_id' => $journalEntry->public_id,
                    'business_model' => $businessModel,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim_settlement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.settlement_posted', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
            'journal_entry_public_id' => $result['journal_entry_public_id'],
            'business_model' => $result['business_model'],
        ], request: $request);

        return $this->respondSuccess([
            'claim' => $this->claimPayload($result['claim']),
            'journal_entry_public_id' => $result['journal_entry_public_id'],
            'business_model' => $result['business_model'],
        ], 'Insurance claim settlement posted successfully');
    }

    public function activeSubscriptionsReport(Request $request): JsonResponse
    {
        return $this->renderReport($request, 'active_subscriptions', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateReportFilters($request, allowStatus: false);
            $query = DB::table('insurance_subscriptions')
                ->leftJoin('insurance_products', 'insurance_products.id', '=', 'insurance_subscriptions.insurance_product_id')
                ->leftJoin('insurance_partners', 'insurance_partners.id', '=', 'insurance_products.insurance_partner_id')
                ->leftJoin('agencies', 'agencies.id', '=', 'insurance_subscriptions.agency_id')
                ->where('insurance_subscriptions.status', 'active');

            $this->applyAgencyFilter($query, 'insurance_subscriptions.agency_id', $scopedAgencyId, $validated['agency_id'] ?? null);
            $this->applyProductFilter($query, 'insurance_subscriptions.insurance_product_id', $validated['product_id'] ?? null);
            $this->applyPartnerFilter($query, 'insurance_products.insurance_partner_id', $validated['partner_id'] ?? null);
            $this->applyDateRangeFilter($query, 'insurance_subscriptions.starts_on', $validated['period_start'] ?? null, $validated['period_end'] ?? null);

            $rows = $query
                ->orderBy('insurance_subscriptions.starts_on')
                ->get([
                    'insurance_subscriptions.public_id',
                    'insurance_subscriptions.starts_on',
                    'insurance_subscriptions.ends_on',
                    'insurance_subscriptions.coverage_amount_minor',
                    'insurance_subscriptions.currency',
                    'insurance_products.code as product_code',
                    'insurance_products.name as product_name',
                    'insurance_partners.code as partner_code',
                    'insurance_partners.name as partner_name',
                    'agencies.code as agency_code',
                ]);

            $items = [];
            $totalCoverage = 0;
            foreach ($rows as $row) {
                $coverage = is_numeric($row->coverage_amount_minor ?? null) ? (int) $row->coverage_amount_minor : 0;
                $totalCoverage += $coverage;
                $items[] = [
                    'public_id' => (string) ($row->public_id ?? ''),
                    'starts_on' => $row->starts_on ?? null,
                    'ends_on' => $row->ends_on ?? null,
                    'coverage_amount_minor' => $coverage,
                    'currency' => (string) ($row->currency ?? ''),
                    'product_code' => $row->product_code ?? null,
                    'product_name' => $row->product_name ?? null,
                    'partner_code' => $row->partner_code ?? null,
                    'partner_name' => $row->partner_name ?? null,
                    'agency_code' => $row->agency_code ?? null,
                ];
            }

            return [
                'items' => $items,
                'totals' => [
                    'count' => count($items),
                    'coverage_amount_minor' => $totalCoverage,
                ],
            ];
        });
    }

    public function premiumsReport(Request $request): JsonResponse
    {
        return $this->renderReport($request, 'premiums', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateReportFilters($request, allowStatus: true);
            $query = DB::table('insurance_premium_assessments as ipa')
                ->join('insurance_subscriptions as isub', 'isub.id', '=', 'ipa.insurance_subscription_id')
                ->leftJoin('insurance_products as ip', 'ip.id', '=', 'isub.insurance_product_id');

            $this->applyAgencyFilter($query, 'isub.agency_id', $scopedAgencyId, $validated['agency_id'] ?? null);
            $this->applyProductFilter($query, 'isub.insurance_product_id', $validated['product_id'] ?? null);
            $this->applyPartnerFilter($query, 'ip.insurance_partner_id', $validated['partner_id'] ?? null);
            $this->applyDateRangeFilter($query, 'ipa.due_on', $validated['period_start'] ?? null, $validated['period_end'] ?? null);
            if (is_string($validated['status'] ?? null) && $validated['status'] !== '') {
                $query->where('ipa.status', $validated['status']);
            }

            $aggregates = $query
                ->selectRaw('ipa.status as status, COUNT(*) as count, COALESCE(SUM(ipa.premium_amount_minor), 0) as amount_minor')
                ->groupBy('ipa.status')
                ->get();

            $statusBuckets = [];
            $totalCount = 0;
            $totalAmount = 0;
            foreach ($aggregates as $aggregate) {
                $status = (string) ($aggregate->status ?? '');
                $count = is_numeric($aggregate->count ?? null) ? (int) $aggregate->count : 0;
                $amount = is_numeric($aggregate->amount_minor ?? null) ? (int) $aggregate->amount_minor : 0;
                $statusBuckets[$status] = [
                    'count' => $count,
                    'amount_minor' => $amount,
                ];
                $totalCount += $count;
                $totalAmount += $amount;
            }

            return [
                'by_status' => $statusBuckets,
                'totals' => [
                    'count' => $totalCount,
                    'amount_minor' => $totalAmount,
                ],
            ];
        });
    }

    public function unpaidPremiumsReport(Request $request): JsonResponse
    {
        return $this->renderReport($request, 'unpaid_premiums', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateReportFilters($request, allowStatus: false);
            $query = DB::table('insurance_premium_assessments as ipa')
                ->join('insurance_subscriptions as isub', 'isub.id', '=', 'ipa.insurance_subscription_id')
                ->leftJoin('insurance_products as ip', 'ip.id', '=', 'isub.insurance_product_id')
                ->where('ipa.status', 'assessed');

            $this->applyAgencyFilter($query, 'isub.agency_id', $scopedAgencyId, $validated['agency_id'] ?? null);
            $this->applyProductFilter($query, 'isub.insurance_product_id', $validated['product_id'] ?? null);
            $this->applyPartnerFilter($query, 'ip.insurance_partner_id', $validated['partner_id'] ?? null);
            $this->applyDateRangeFilter($query, 'ipa.due_on', $validated['period_start'] ?? null, $validated['period_end'] ?? null);

            $rows = $query
                ->orderBy('ipa.due_on')
                ->get([
                    'ipa.public_id',
                    'ipa.premium_amount_minor',
                    'ipa.currency',
                    'ipa.due_on',
                    'ipa.status',
                    'isub.public_id as subscription_public_id',
                    'ip.code as product_code',
                ]);

            $items = [];
            $totalAmount = 0;
            foreach ($rows as $row) {
                $amount = is_numeric($row->premium_amount_minor ?? null) ? (int) $row->premium_amount_minor : 0;
                $totalAmount += $amount;
                $items[] = [
                    'public_id' => (string) ($row->public_id ?? ''),
                    'subscription_public_id' => (string) ($row->subscription_public_id ?? ''),
                    'premium_amount_minor' => $amount,
                    'currency' => (string) ($row->currency ?? ''),
                    'due_on' => $row->due_on ?? null,
                    'status' => (string) ($row->status ?? ''),
                    'product_code' => $row->product_code ?? null,
                ];
            }

            return [
                'items' => $items,
                'totals' => [
                    'count' => count($items),
                    'amount_minor' => $totalAmount,
                ],
            ];
        });
    }

    public function claimsReport(Request $request): JsonResponse
    {
        return $this->renderReport($request, 'claims_by_status', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateReportFilters($request, allowStatus: true);
            $query = DB::table('insurance_claims as ic')
                ->join('insurance_subscriptions as isub', 'isub.id', '=', 'ic.insurance_subscription_id')
                ->leftJoin('insurance_products as ip', 'ip.id', '=', 'isub.insurance_product_id');

            $this->applyAgencyFilter($query, 'ic.agency_id', $scopedAgencyId, $validated['agency_id'] ?? null);
            $this->applyProductFilter($query, 'isub.insurance_product_id', $validated['product_id'] ?? null);
            $this->applyPartnerFilter($query, 'ip.insurance_partner_id', $validated['partner_id'] ?? null);
            $this->applyDateRangeFilter($query, 'ic.incident_date', $validated['period_start'] ?? null, $validated['period_end'] ?? null);
            if (is_string($validated['status'] ?? null) && $validated['status'] !== '') {
                $query->where('ic.status', $validated['status']);
            }

            $aggregates = $query
                ->selectRaw('ic.status as status, COUNT(*) as count, COALESCE(SUM(ic.claimed_amount_minor), 0) as claimed_minor, COALESCE(SUM(ic.indemnified_amount_minor), 0) as indemnified_minor')
                ->groupBy('ic.status')
                ->get();

            $statusBuckets = [];
            $totalCount = 0;
            $totalClaimed = 0;
            $totalIndemnified = 0;
            foreach ($aggregates as $aggregate) {
                $status = (string) ($aggregate->status ?? '');
                $count = is_numeric($aggregate->count ?? null) ? (int) $aggregate->count : 0;
                $claimed = is_numeric($aggregate->claimed_minor ?? null) ? (int) $aggregate->claimed_minor : 0;
                $indemnified = is_numeric($aggregate->indemnified_minor ?? null) ? (int) $aggregate->indemnified_minor : 0;
                $statusBuckets[$status] = [
                    'count' => $count,
                    'claimed_amount_minor' => $claimed,
                    'indemnified_amount_minor' => $indemnified,
                ];
                $totalCount += $count;
                $totalClaimed += $claimed;
                $totalIndemnified += $indemnified;
            }

            return [
                'by_status' => $statusBuckets,
                'totals' => [
                    'count' => $totalCount,
                    'claimed_amount_minor' => $totalClaimed,
                    'indemnified_amount_minor' => $totalIndemnified,
                ],
            ];
        });
    }

    public function expiringCoverageReport(Request $request): JsonResponse
    {
        return $this->renderReport($request, 'expiring_coverage', function (Request $request, ?int $scopedAgencyId): array {
            $validated = $this->validateReportFilters($request, allowStatus: false);
            $start = is_string($validated['period_start'] ?? null) && $validated['period_start'] !== ''
                ? $validated['period_start']
                : now()->toDateString();
            $end = is_string($validated['period_end'] ?? null) && $validated['period_end'] !== ''
                ? $validated['period_end']
                : now()->addDays(30)->toDateString();

            $query = DB::table('insurance_subscriptions as isub')
                ->leftJoin('insurance_products as ip', 'ip.id', '=', 'isub.insurance_product_id')
                ->where('isub.status', 'active')
                ->whereNotNull('isub.ends_on')
                ->whereBetween('isub.ends_on', [$start, $end]);

            $this->applyAgencyFilter($query, 'isub.agency_id', $scopedAgencyId, $validated['agency_id'] ?? null);
            $this->applyProductFilter($query, 'isub.insurance_product_id', $validated['product_id'] ?? null);
            $this->applyPartnerFilter($query, 'ip.insurance_partner_id', $validated['partner_id'] ?? null);

            $rows = $query
                ->orderBy('isub.ends_on')
                ->get([
                    'isub.public_id',
                    'isub.ends_on',
                    'isub.currency',
                    'isub.coverage_amount_minor',
                    'ip.code as product_code',
                ]);

            $items = [];
            foreach ($rows as $row) {
                $items[] = [
                    'public_id' => (string) ($row->public_id ?? ''),
                    'ends_on' => $row->ends_on ?? null,
                    'currency' => (string) ($row->currency ?? ''),
                    'coverage_amount_minor' => is_numeric($row->coverage_amount_minor ?? null) ? (int) $row->coverage_amount_minor : 0,
                    'product_code' => $row->product_code ?? null,
                ];
            }

            return [
                'window' => ['from' => $start, 'to' => $end],
                'items' => $items,
                'totals' => [
                    'count' => count($items),
                ],
            ];
        });
    }

    /**
     * @param  callable(Request, ?int):array<string, mixed>  $compute
     */
    private function renderReport(Request $request, string $reportKey, callable $compute): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $isPlatformAdmin = $actor->hasRole('platform-admin');
        $isAgencyManager = $actor->hasRole('agency-manager');
        if (! $isPlatformAdmin && ! $isAgencyManager) {
            return $this->respondForbidden();
        }

        $scopedAgencyId = null;
        if (! $isPlatformAdmin) {
            $scopedAgencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($scopedAgencyId === null) {
                return $this->respondForbidden('No active agency assignment for this user.');
            }
        }

        try {
            $payload = $compute($request, $scopedAgencyId);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_report' => [$exception->getMessage()]]);
        }

        return $this->respondSuccess(
            data: $payload,
            message: 'Insurance report generated successfully',
            meta: ['report' => $reportKey],
        );
    }

    /**
     * @return array{
     *     agency_id:?int,
     *     product_id:?int,
     *     partner_id:?int,
     *     period_start:?string,
     *     period_end:?string,
     *     status:?string,
     * }
     */
    private function validateReportFilters(Request $request, bool $allowStatus): array
    {
        $rules = [
            'agency_public_id' => ['sometimes', 'nullable', 'string', 'exists:agencies,public_id'],
            'product_public_id' => ['sometimes', 'nullable', 'string', 'exists:insurance_products,public_id'],
            'partner_public_id' => ['sometimes', 'nullable', 'string', 'exists:insurance_partners,public_id'],
            'period_start' => ['sometimes', 'nullable', 'date'],
            'period_end' => ['sometimes', 'nullable', 'date', 'after_or_equal:period_start'],
        ];
        if ($allowStatus) {
            $rules['status'] = ['sometimes', 'nullable', 'string', 'max:32'];
        }

        $validated = Validator::make($request->all(), $rules)->validate();

        return [
            'agency_id' => $this->resolveAgencyId($validated['agency_public_id'] ?? null),
            'product_id' => $this->resolveByPublicId('insurance_products', $validated['product_public_id'] ?? null),
            'partner_id' => $this->resolveByPublicId('insurance_partners', $validated['partner_public_id'] ?? null),
            'period_start' => is_string($validated['period_start'] ?? null) && $validated['period_start'] !== '' ? $validated['period_start'] : null,
            'period_end' => is_string($validated['period_end'] ?? null) && $validated['period_end'] !== '' ? $validated['period_end'] : null,
            'status' => is_string($validated['status'] ?? null) && $validated['status'] !== '' ? $validated['status'] : null,
        ];
    }

    private function resolveAgencyId(mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $agency = DB::table('agencies')->where('public_id', $publicId)->first(['id']);

        return is_object($agency) && is_numeric($agency->id) ? (int) $agency->id : null;
    }

    private function resolveByPublicId(string $table, mixed $publicId): ?int
    {
        if (! is_string($publicId) || $publicId === '') {
            return null;
        }
        $row = DB::table($table)->where('public_id', $publicId)->first(['id']);

        return is_object($row) && is_numeric($row->id) ? (int) $row->id : null;
    }

    private function applyAgencyFilter(\Illuminate\Database\Query\Builder $query, string $column, ?int $scopedAgencyId, ?int $requestedAgencyId): void
    {
        if ($scopedAgencyId !== null) {
            if ($requestedAgencyId !== null && $requestedAgencyId !== $scopedAgencyId) {
                throw new InvalidArgumentException('Agency-scoped users cannot query other agencies.');
            }
            $query->where($column, $scopedAgencyId);

            return;
        }

        if ($requestedAgencyId !== null) {
            $query->where($column, $requestedAgencyId);
        }
    }

    private function applyProductFilter(\Illuminate\Database\Query\Builder $query, string $column, ?int $productId): void
    {
        if ($productId !== null) {
            $query->where($column, $productId);
        }
    }

    private function applyPartnerFilter(\Illuminate\Database\Query\Builder $query, string $column, ?int $partnerId): void
    {
        if ($partnerId !== null) {
            $query->where($column, $partnerId);
        }
    }

    private function applyDateRangeFilter(\Illuminate\Database\Query\Builder $query, string $column, ?string $start, ?string $end): void
    {
        if ($start !== null) {
            $query->where($column, '>=', $start);
        }
        if ($end !== null) {
            $query->where($column, '<=', $end);
        }
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function insuranceClaimSettlementLedgers(int $agencyId, string $currency): array
    {
        $operationCode = 'insurance_claim_settlement';
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'insurance')
            ->where('operation_codes.status', 'active')
            ->where('operation_account_mappings.status', 'active')
            ->where(function ($query) use ($currency): void {
                $query->whereNull('operation_account_mappings.currency')
                    ->orWhere('operation_account_mappings.currency', $currency);
            })
            ->orderByRaw('operation_account_mappings.currency IS NULL')
            ->first(['operation_account_mappings.debit_ledger_account_id', 'operation_account_mappings.credit_ledger_account_id']);

        $debitId = is_object($mapping) ? $mapping->debit_ledger_account_id : null;
        $creditId = is_object($mapping) ? $mapping->credit_ledger_account_id : null;
        if (! is_int($debitId) || ! is_int($creditId)) {
            throw new InvalidArgumentException('Active debit and credit ledger mappings are required for '.$operationCode.'.');
        }

        $debit = DB::table('ledger_accounts')
            ->where('id', $debitId)
            ->where('agency_id', $agencyId)
            ->where('status', LedgerAccount::STATUS_ACTIVE)
            ->first(['id']);
        $credit = DB::table('ledger_accounts')
            ->where('id', $creditId)
            ->where('agency_id', $agencyId)
            ->where('status', LedgerAccount::STATUS_ACTIVE)
            ->first(['id']);
        if (! is_object($debit) || ! is_object($credit)) {
            throw new InvalidArgumentException('Settlement ledger accounts must be active and belong to the claim agency.');
        }

        return [$debitId, $creditId];
    }

    /**
     * @return array<string, mixed>
     */
    private function productRules(object $product): array
    {
        $rules = ((array) $product)['rules'] ?? null;
        $source = null;
        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);
            if (is_array($decoded)) {
                $source = $decoded;
            }
        } elseif (is_array($rules)) {
            $source = $rules;
        }
        if ($source === null) {
            return [];
        }

        $result = [];
        foreach ($source as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function requestClaimDecision(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approve', 'reject', 'settle'])],
            'indemnified_amount_minor' => ['required_if:decision,settle', 'nullable', 'integer', 'min:0'],
            'settled_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $decision = DB::transaction(function () use ($actor, $claimPublicId, $validated): object {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                if ($this->rowString($claim, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending claims can have new decision requests.');
                }

                $hasOpenRequest = DB::table('insurance_claim_decisions')
                    ->where('insurance_claim_id', $this->rowInt($claim, 'id'))
                    ->where('status', 'pending')
                    ->exists();
                if ($hasOpenRequest) {
                    throw new InvalidArgumentException('A pending decision request already exists for this claim.');
                }

                $decisionAction = (string) $validated['decision'];
                if ($decisionAction !== 'settle' && array_key_exists('indemnified_amount_minor', $validated)) {
                    throw new InvalidArgumentException('Only settlement decision requests can include an indemnified amount.');
                }

                $publicId = (string) Str::ulid();
                $id = DB::table('insurance_claim_decisions')->insertGetId([
                    'public_id' => $publicId,
                    'insurance_claim_id' => $this->rowInt($claim, 'id'),
                    'decision' => $decisionAction,
                    'indemnified_amount_minor' => $decisionAction !== 'settle'
                        ? null
                        : $this->nullableInt($validated['indemnified_amount_minor'] ?? null),
                    'settled_on' => $decisionAction === 'settle'
                        ? $this->nullableString($validated['settled_on'] ?? null)
                        : null,
                    'notes' => $this->nullableString($validated['notes'] ?? null),
                    'status' => 'pending',
                    'requested_by_user_id' => $actor->id,
                    'requested_at' => now(),
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                    'review_comments' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('insurance_claim_decisions')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Insurance claim decision request could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim_decision' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.decision_requested', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
            'decision_public_id' => $this->rowString($decision, 'public_id'),
            'decision' => $this->rowString($decision, 'decision'),
        ], request: $request);

        return $this->respondCreated(
            $this->claimDecisionPayload($decision, $claimPublicId),
            'Insurance claim decision request created successfully',
        );
    }

    public function reviewClaimDecision(Request $request, string $decisionPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $actor->hasRole('platform-admin')) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'review_decision' => ['required', Rule::in(['approve', 'reject'])],
            'review_comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $decisionPublicId, $validated): array {
                $decision = DB::table('insurance_claim_decisions')
                    ->where('public_id', $decisionPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($decision)) {
                    throw new InvalidArgumentException('Insurance claim decision request is invalid.');
                }

                if ($this->rowString($decision, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending decision requests can be reviewed.');
                }

                if ($this->rowInt($decision, 'requested_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The requester cannot review their own decision request.');
                }

                $claim = DB::table('insurance_claims')
                    ->where('id', $this->rowInt($decision, 'insurance_claim_id'))
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim is invalid.');
                }

                $reviewDecision = (string) $validated['review_decision'];
                $reviewComments = $this->nullableString($validated['review_comments'] ?? null);

                $newDecisionStatus = $reviewDecision === 'approve' ? 'approved' : 'rejected';

                DB::table('insurance_claim_decisions')
                    ->where('id', $this->rowInt($decision, 'id'))
                    ->update([
                        'status' => $newDecisionStatus,
                        'reviewed_by_user_id' => $actor->id,
                        'reviewed_at' => now(),
                        'review_comments' => $reviewComments,
                        'updated_at' => now(),
                    ]);

                if ($reviewDecision === 'approve') {
                    $decisionAction = $this->rowString($decision, 'decision');
                    $claimStatus = match ($decisionAction) {
                        'approve' => 'approved',
                        'reject' => 'rejected',
                        'settle' => 'settled',
                        default => throw new InvalidArgumentException('Unsupported claim decision.'),
                    };

                    DB::table('insurance_claims')
                        ->where('id', $this->rowInt($claim, 'id'))
                        ->update([
                            'status' => $claimStatus,
                            'indemnified_amount_minor' => $decisionAction === 'settle'
                                ? $this->rowNullableInt($decision, 'indemnified_amount_minor')
                                : null,
                            'settled_at' => $decisionAction === 'settle'
                                ? ($this->rowNullableString($decision, 'settled_on') ?? now()->toDateString())
                                : null,
                            'updated_at' => now(),
                        ]);
                }

                $reloadedDecision = DB::table('insurance_claim_decisions')->where('id', $this->rowInt($decision, 'id'))->first();
                $reloadedClaim = DB::table('insurance_claims')->where('id', $this->rowInt($claim, 'id'))->first();
                if (! is_object($reloadedDecision) || ! is_object($reloadedClaim)) {
                    throw new InvalidArgumentException('Insurance claim decision could not be reloaded.');
                }

                return [
                    'decision' => $reloadedDecision,
                    'claim' => $reloadedClaim,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim_decision' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.decision_reviewed', actor: $actor, properties: [
            'decision_public_id' => $decisionPublicId,
            'status' => $this->rowString($result['decision'], 'status'),
            'claim_public_id' => $this->rowString($result['claim'], 'public_id'),
            'claim_status' => $this->rowString($result['claim'], 'status'),
        ], request: $request);

        return $this->respondSuccess([
            'decision' => $this->claimDecisionPayload($result['decision'], $this->rowString($result['claim'], 'public_id')),
            'claim' => $this->claimPayload($result['claim']),
        ], 'Insurance claim decision reviewed successfully');
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

    private function insurancePremiumCollectionCreditLedgerId(int $agencyId, string $currency): int
    {
        $operationCode = 'insurance_premium_collection';
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'insurance')
            ->where('operation_codes.status', 'active')
            ->where('operation_account_mappings.status', 'active')
            ->where(function ($query) use ($currency): void {
                $query->whereNull('operation_account_mappings.currency')
                    ->orWhere('operation_account_mappings.currency', $currency);
            })
            ->where('ledger_accounts.agency_id', $agencyId)
            ->where('ledger_accounts.status', LedgerAccount::STATUS_ACTIVE)
            ->orderByRaw('operation_account_mappings.currency IS NULL')
            ->first(['operation_account_mappings.credit_ledger_account_id']);

        $ledgerAccountId = is_object($mapping) ? $mapping->credit_ledger_account_id : null;
        if (! is_int($ledgerAccountId)) {
            throw new InvalidArgumentException('Active credit ledger mapping is required for '.$operationCode.'.');
        }

        return $ledgerAccountId;
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

    /**
     * @return array<string, mixed>
     */
    private function premiumPaymentPayload(object $payment): array
    {
        return [
            'public_id' => $this->rowString($payment, 'public_id'),
            'amount_minor' => $this->rowInt($payment, 'amount_minor'),
            'currency' => $this->rowString($payment, 'currency'),
            'payment_method' => $this->rowNullableString($payment, 'payment_method'),
            'paid_at' => $this->rowNullableString($payment, 'paid_at'),
            'status' => $this->rowString($payment, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function premiumAssessmentPayload(object $assessment, string $subscriptionPublicId): array
    {
        return [
            'public_id' => $this->rowString($assessment, 'public_id'),
            'subscription_public_id' => $subscriptionPublicId,
            'premium_amount_minor' => $this->rowInt($assessment, 'premium_amount_minor'),
            'base_amount_minor' => $this->rowNullableInt($assessment, 'base_amount_minor'),
            'rate' => $this->rowNullableString($assessment, 'rate'),
            'currency' => $this->rowString($assessment, 'currency'),
            'due_on' => $this->rowNullableString($assessment, 'due_on'),
            'status' => $this->rowString($assessment, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claimDecisionPayload(object $decision, string $claimPublicId): array
    {
        return [
            'public_id' => $this->rowString($decision, 'public_id'),
            'claim_public_id' => $claimPublicId,
            'decision' => $this->rowString($decision, 'decision'),
            'status' => $this->rowString($decision, 'status'),
            'indemnified_amount_minor' => $this->rowNullableInt($decision, 'indemnified_amount_minor'),
            'settled_on' => $this->rowNullableString($decision, 'settled_on'),
            'notes' => $this->rowNullableString($decision, 'notes'),
            'requested_at' => $this->rowNullableString($decision, 'requested_at'),
            'reviewed_at' => $this->rowNullableString($decision, 'reviewed_at'),
            'review_comments' => $this->rowNullableString($decision, 'review_comments'),
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
