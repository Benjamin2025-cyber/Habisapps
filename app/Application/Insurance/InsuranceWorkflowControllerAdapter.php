<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Application\Notifications\ClientAlertProducer;
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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class InsuranceWorkflowControllerAdapter extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly AccountingBalanceCalculator $balanceCalculator,
        private readonly InsuranceAccountingService $insuranceAccounting,
        private readonly InsuranceProductWorkflow $insuranceProductWorkflow,
        private readonly InsuranceSubscriptionWorkflow $insuranceSubscriptionWorkflow,
        private readonly InsuranceReportWorkflow $insuranceReportWorkflow,
        private readonly InsuranceExportWorkflow $insuranceExportWorkflow,
        private readonly ClientAlertProducer $clientAlerts,
    ) {}

    private function hasInsurancePermission(?User $actor, string $permission): bool
    {
        return $actor instanceof User && $actor->hasPermissionTo($permission);
    }

    private function insuranceActor(Request $request, string $permission): ?User
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return null;
        }

        return $this->hasInsurancePermission($actor, $permission)
            ? $actor
            : null;
    }

    public function storePartner(Request $request): JsonResponse
    {
        return $this->insuranceProductWorkflow->storePartner($request);
    }

    public function storeProduct(Request $request): JsonResponse
    {
        return $this->insuranceProductWorkflow->storeProduct($request);
    }

    public function storeClaim(Request $request): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.claims.intake');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'insurance_subscription_public_id' => ['required', 'string', 'exists:insurance_subscriptions,public_id'],
            'claim_type' => ['required', 'string', 'max:64'],
            'incident_date' => ['required', 'date'],
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
                if ($this->rowString($subscription, 'status') !== 'active') {
                    throw new InvalidArgumentException('Claims can only be opened on active insurance subscriptions.');
                }

                $incidentDate = (string) $validated['incident_date'];
                $startsOn = $this->rowNullableString($subscription, 'starts_on');
                $endsOn = $this->rowNullableString($subscription, 'ends_on');
                if ($startsOn !== null && $incidentDate < $startsOn) {
                    throw new InvalidArgumentException('Claim incident date is before coverage starts.');
                }
                if ($endsOn !== null && $incidentDate > $endsOn) {
                    throw new InvalidArgumentException('Claim incident date is after coverage ends.');
                }
                $cancellationBlocksClaim = DB::table('insurance_cancellations')
                    ->where('insurance_subscription_id', $this->rowInt($subscription, 'id'))
                    ->where('status', 'approved')
                    ->whereDate('effective_on', '<=', $incidentDate)
                    ->exists();
                if ($cancellationBlocksClaim) {
                    throw new InvalidArgumentException('Claim incident date is on or after the approved cancellation effective date.');
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
                    'incident_date' => $incidentDate,
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
        return $this->insuranceSubscriptionWorkflow->store($request);
    }

    public function storePremiumAssessment(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.premiums.manage');
        if (! $actor instanceof User) {
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
                    'rule_version_id' => $this->rowNullableInt($subscription, 'rule_version_id'),
                    'period_key' => null,
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
        $actor = $this->insuranceActor($request, 'insurance.premiums.collect');
        if (! $actor instanceof User) {
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
                $product = $this->insuranceAccounting->productForPremiumCollection($subscription);

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

                $premiumSplits = $this->insuranceAccounting->createPremiumSplitCreditLines(
                    $journalEntry,
                    $assessment,
                    $subscription,
                    $product,
                    $amountMinor,
                    $currency,
                );

                $this->insuranceAccounting->postSystemJournal($journalEntry, $actor);

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
                $this->insuranceAccounting->storePremiumPaymentSplits($paymentId, $premiumSplits);

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
        $actor = $this->insuranceActor($request, 'insurance.premiums.collect');
        if (! $actor instanceof User) {
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
                $product = $this->insuranceAccounting->productForPremiumCollection($subscription);

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

                $premiumSplits = $this->insuranceAccounting->createPremiumSplitCreditLines(
                    $journalEntry,
                    $assessment,
                    $subscription,
                    $product,
                    $amountMinor,
                    $currency,
                );

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

                $this->insuranceAccounting->postSystemJournal($journalEntry, $actor);

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
                $this->insuranceAccounting->storePremiumPaymentSplits($paymentId, $premiumSplits);

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
        $actor = $this->insuranceActor($request, 'insurance.claims.intake');
        if (! $actor instanceof User) {
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
        $actor = $this->insuranceActor($request, 'insurance.claims.settle');
        if (! $actor instanceof User) {
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
                $businessModel = $this->rowNullableString($product, 'business_model')
                    ?? $this->stringValue($rules['business_model'] ?? null, '');
                if (! in_array($businessModel, ['broker', 'collector', 'premium_collector', 'distributor', 'risk_carrier'], true)) {
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

                $this->insuranceAccounting->postSystemJournal($journalEntry, $actor);

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
        return $this->insuranceReportWorkflow->activeSubscriptions($request);
    }

    public function premiumsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->premiums($request);
    }

    public function unpaidPremiumsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->unpaidPremiums($request);
    }

    public function claimsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->claims($request);
    }

    public function expiringCoverageReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->expiringCoverage($request);
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function insuranceClaimSettlementLedgers(int $agencyId, string $currency): array
    {
        $operationCode = 'insurance_claim_settlement';
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts as debit_ledgers', 'debit_ledgers.id', '=', 'operation_account_mappings.debit_ledger_account_id')
            ->join('ledger_accounts as credit_ledgers', 'credit_ledgers.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'insurance')
            ->where('operation_codes.status', 'active')
            ->where('operation_account_mappings.status', 'active')
            ->where('debit_ledgers.agency_id', $agencyId)
            ->where('debit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
            ->where('credit_ledgers.agency_id', $agencyId)
            ->where('credit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
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
        $actor = $this->insuranceActor($request, 'insurance.claims.review');
        if (! $actor instanceof User) {
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
        $actor = $this->insuranceActor($request, 'insurance.claims.review');
        if (! $actor instanceof User) {
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
                    if (in_array($decisionAction, ['approve', 'settle'], true)) {
                        $this->assertClaimEvidenceComplete($claim);
                    }
                    if ($decisionAction === 'settle') {
                        $this->assertClaimSettlementAmountAllowed($claim, $decision);
                    }

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
        $notificationRows = $this->clientAlerts->produceInsuranceClaimDecisionAlerts(now());

        return $this->respondSuccess([
            'decision' => $this->claimDecisionPayload($result['decision'], $this->rowString($result['claim'], 'public_id')),
            'claim' => $this->claimPayload($result['claim']),
            'notification_outbox_rows' => $notificationRows,
        ], 'Insurance claim decision reviewed successfully');
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

    private function journalEntryPublicId(?int $journalEntryId): ?string
    {
        if ($journalEntryId === null) {
            return null;
        }

        $publicId = DB::table('journal_entries')->where('id', $journalEntryId)->value('public_id');

        return is_string($publicId) ? $publicId : null;
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

    private function assertClaimEvidenceComplete(object $claim): void
    {
        $subscription = DB::table('insurance_subscriptions')
            ->where('id', $this->rowInt($claim, 'insurance_subscription_id'))
            ->first();
        if (! is_object($subscription)) {
            throw new InvalidArgumentException('Insurance subscription is invalid.');
        }

        $requiredTypes = DB::table('insurance_claim_evidence_configs')
            ->where('insurance_product_id', $this->rowInt($subscription, 'insurance_product_id'))
            ->where('claim_type', $this->rowString($claim, 'claim_type'))
            ->where('is_required', true)
            ->get(['document_type'])
            ->map(fn (object $row): string => $this->rowString($row, 'document_type'))
            ->all();

        if ($requiredTypes === []) {
            return;
        }

        $attachedTypes = DB::table('insurance_claim_documents')
            ->where('insurance_claim_id', $this->rowInt($claim, 'id'))
            ->whereIn('document_type', $requiredTypes)
            ->get(['document_type'])
            ->map(fn (object $row): string => $this->rowString($row, 'document_type'))
            ->all();

        $missingTypes = array_values(array_diff($requiredTypes, $attachedTypes));
        if ($missingTypes !== []) {
            throw new InvalidArgumentException('Required claim evidence is missing: '.implode(', ', $missingTypes).'.');
        }

        DB::table('insurance_claims')
            ->where('id', $this->rowInt($claim, 'id'))
            ->update(['evidence_complete_at' => now(), 'updated_at' => now()]);
    }

    private function assertClaimSettlementAmountAllowed(object $claim, object $decision): void
    {
        $indemnifiedMinor = $this->rowNullableInt($decision, 'indemnified_amount_minor');
        if ($indemnifiedMinor === null || $indemnifiedMinor <= 0) {
            throw new InvalidArgumentException('Settlement decision requires a positive indemnified amount.');
        }

        $claimedMinor = $this->rowNullableInt($claim, 'claimed_amount_minor');
        if ($claimedMinor !== null && $indemnifiedMinor > $claimedMinor) {
            throw new InvalidArgumentException('Settlement amount cannot exceed the claimed amount.');
        }

        $subscription = DB::table('insurance_subscriptions')
            ->where('id', $this->rowInt($claim, 'insurance_subscription_id'))
            ->first();
        if (! is_object($subscription)) {
            throw new InvalidArgumentException('Insurance subscription is invalid.');
        }

        $coverageMinor = $this->rowNullableInt($subscription, 'coverage_amount_minor');
        if ($coverageMinor !== null && $indemnifiedMinor > $coverageMinor) {
            throw new InvalidArgumentException('Settlement amount cannot exceed configured coverage.');
        }
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

    // -------------------------------------------------------------------------
    // A8: Insurance Product Rule Versioning
    // -------------------------------------------------------------------------

    public function storeProductRuleVersion(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insuranceProductWorkflow->storeRuleVersion($request, $productPublicId);
    }

    public function approveProductRuleVersion(Request $request, string $versionPublicId): JsonResponse
    {
        return $this->insuranceProductWorkflow->approveRuleVersion($request, $versionPublicId);
    }

    public function activateProduct(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insuranceProductWorkflow->activateProduct($request, $productPublicId);
    }

    public function storeClaimEvidenceConfig(Request $request, string $productPublicId): JsonResponse
    {
        return $this->insuranceProductWorkflow->storeEvidenceConfig($request, $productPublicId);
    }

    // -------------------------------------------------------------------------
    // A9: Recurring Premium Schedules & Renewal Lifecycle
    // -------------------------------------------------------------------------

    public function activateSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insuranceSubscriptionWorkflow->activate($request, $subscriptionPublicId);
    }

    public function generatePremiumBatch(Request $request): JsonResponse
    {
        return $this->insuranceSubscriptionWorkflow->generatePremiumBatch($request);
    }

    public function renewSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        return $this->insuranceSubscriptionWorkflow->renew($request, $subscriptionPublicId);
    }

    // -------------------------------------------------------------------------
    // A10: Endorsements, Cancellations, Refunds, Reversals
    // -------------------------------------------------------------------------

    public function storeEndorsement(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'endorsement_type' => ['required', 'string', Rule::in(['coverage_amount', 'beneficiary', 'dates', 'other'])],
            'before_values' => ['required', 'array'],
            'after_values' => ['required', 'array'],
            'effective_on' => ['required', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ])->validate();

        try {
            $endorsement = DB::transaction(function () use ($actor, $subscriptionPublicId, $validated): object {
                $subscription = DB::table('insurance_subscriptions')
                    ->where('public_id', $subscriptionPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription not found.');
                }
                if ($this->rowString($subscription, 'status') !== 'active') {
                    throw new InvalidArgumentException('Only active subscriptions can be endorsed.');
                }

                $id = DB::table('insurance_endorsements')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
                    'endorsement_type' => (string) $validated['endorsement_type'],
                    'before_values' => json_encode($validated['before_values'], JSON_THROW_ON_ERROR),
                    'after_values' => json_encode($validated['after_values'], JSON_THROW_ON_ERROR),
                    'effective_on' => (string) $validated['effective_on'],
                    'reason' => $this->nullableString($validated['reason'] ?? null),
                    'status' => 'pending',
                    'requested_by_user_id' => $actor->id,
                    'reviewed_by_user_id' => null,
                    'reviewed_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('insurance_endorsements')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Endorsement could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['endorsement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.endorsement.created', actor: $actor, properties: [
            'subscription_public_id' => $subscriptionPublicId,
            'endorsement_public_id' => $this->rowString($endorsement, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->endorsementPayload($endorsement), 'Endorsement request created successfully');
    }

    public function approveEndorsement(Request $request, string $endorsementPublicId): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'review_decision' => ['required', Rule::in(['approve', 'reject'])],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $endorsementPublicId, $validated): array {
                $endorsement = DB::table('insurance_endorsements')
                    ->where('public_id', $endorsementPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($endorsement)) {
                    throw new InvalidArgumentException('Endorsement not found.');
                }
                if ($this->rowString($endorsement, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending endorsements can be reviewed.');
                }
                if ($this->rowInt($endorsement, 'requested_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The requester cannot review their own endorsement.');
                }

                $newStatus = (string) $validated['review_decision'] === 'approve' ? 'approved' : 'rejected';
                DB::table('insurance_endorsements')
                    ->where('id', $this->rowInt($endorsement, 'id'))
                    ->update([
                        'status' => $newStatus,
                        'reviewed_by_user_id' => $actor->id,
                        'reviewed_at' => now(),
                        'updated_at' => now(),
                    ]);

                // Apply the after_values to the subscription when approved
                if ($newStatus === 'approved') {
                    $afterValues = json_decode($this->rowString($endorsement, 'after_values'), true);
                    if (is_array($afterValues)) {
                        $allowedCols = ['coverage_amount_minor', 'ends_on'];
                        $updates = array_intersect_key($afterValues, array_flip($allowedCols));
                        if ($updates !== []) {
                            $updates['updated_at'] = now()->toDateTimeString();
                            DB::table('insurance_subscriptions')
                                ->where('id', $this->rowInt($endorsement, 'insurance_subscription_id'))
                                ->update($updates);
                        }
                    }
                }

                $updated = DB::table('insurance_endorsements')
                    ->where('id', $this->rowInt($endorsement, 'id'))
                    ->first();

                return ['endorsement' => $updated ?? $endorsement];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['endorsement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.endorsement.reviewed', actor: $actor, properties: [
            'endorsement_public_id' => $endorsementPublicId,
            'decision' => (string) $validated['review_decision'],
        ], request: $request);

        return $this->respondSuccess($this->endorsementPayload($result['endorsement']), 'Endorsement reviewed successfully');
    }

    public function cancelSubscription(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'effective_on' => ['required', 'date'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'refund_treatment' => ['sometimes', Rule::in(['none', 'pro_rata', 'full'])],
            'refund_amount_minor' => ['required_unless:refund_treatment,none', 'nullable', 'integer', 'min:1'],
            'refund_customer_account_public_id' => ['required_with:refund_amount_minor', 'nullable', 'string', 'exists:customer_accounts,public_id'],
        ])->validate();

        try {
            $cancellation = DB::transaction(function () use ($actor, $subscriptionPublicId, $validated): object {
                $subscription = DB::table('insurance_subscriptions')
                    ->where('public_id', $subscriptionPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription not found.');
                }
                if ($this->rowString($subscription, 'status') !== 'active') {
                    throw new InvalidArgumentException('Only active subscriptions can be cancelled.');
                }
                $refundTreatment = $this->stringValue($validated['refund_treatment'] ?? 'none', 'none');
                $refundAmountMinor = $this->nullableInt($validated['refund_amount_minor'] ?? null);
                $refundCustomerAccountId = null;
                if ($refundTreatment !== 'none') {
                    if ($refundAmountMinor === null || $refundAmountMinor <= 0) {
                        throw new InvalidArgumentException('Cancellation refund requires a manually approved positive refund amount.');
                    }
                    $refundAccount = DB::table('customer_accounts')
                        ->where('public_id', (string) $validated['refund_customer_account_public_id'])
                        ->where('status', CustomerAccount::STATUS_ACTIVE)
                        ->first(['id', 'client_id', 'agency_id', 'currency']);
                    if (! is_object($refundAccount)) {
                        throw new InvalidArgumentException('Refund customer account must be active.');
                    }
                    if ($this->rowInt($refundAccount, 'client_id') !== $this->rowInt($subscription, 'client_id')
                        || $this->rowInt($refundAccount, 'agency_id') !== $this->rowInt($subscription, 'agency_id')
                        || $this->rowString($refundAccount, 'currency') !== $this->rowString($subscription, 'currency')) {
                        throw new InvalidArgumentException('Refund account must belong to the subscription client, agency, and currency.');
                    }
                    $refundCustomerAccountId = $this->rowInt($refundAccount, 'id');
                }

                $id = DB::table('insurance_cancellations')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_subscription_id' => $this->rowInt($subscription, 'id'),
                    'effective_on' => (string) $validated['effective_on'],
                    'reason' => $this->nullableString($validated['reason'] ?? null),
                    'refund_treatment' => $refundTreatment,
                    'refund_amount_minor' => $refundAmountMinor,
                    'refund_customer_account_id' => $refundCustomerAccountId,
                    'refund_journal_entry_id' => null,
                    'status' => 'pending',
                    'requested_by_user_id' => $actor->id,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $row = DB::table('insurance_cancellations')->where('id', $id)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Cancellation request could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['cancellation' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.subscription.cancellation_requested', actor: $actor, properties: [
            'subscription_public_id' => $subscriptionPublicId,
            'cancellation_public_id' => $this->rowString($cancellation, 'public_id'),
        ], request: $request);

        return $this->respondCreated([
            'public_id' => $this->rowString($cancellation, 'public_id'),
            'subscription_public_id' => $subscriptionPublicId,
            'effective_on' => $this->rowString($cancellation, 'effective_on'),
            'refund_treatment' => $this->rowString($cancellation, 'refund_treatment'),
            'status' => $this->rowString($cancellation, 'status'),
        ], 'Cancellation request created; awaiting approval');
    }

    public function reviewCancellation(Request $request, string $cancellationPublicId): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.subscriptions.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'review_decision' => ['required', Rule::in(['approve', 'reject'])],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $cancellationPublicId, $validated): array {
                $cancellation = DB::table('insurance_cancellations')
                    ->where('public_id', $cancellationPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($cancellation)) {
                    throw new InvalidArgumentException('Cancellation request not found.');
                }
                if ($this->rowString($cancellation, 'status') !== 'pending') {
                    throw new InvalidArgumentException('Only pending cancellation requests can be reviewed.');
                }
                if ($this->rowInt($cancellation, 'requested_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The requester cannot review their own cancellation request.');
                }

                $subscription = DB::table('insurance_subscriptions')
                    ->where('id', $this->rowInt($cancellation, 'insurance_subscription_id'))
                    ->lockForUpdate()
                    ->first();
                if (! is_object($subscription)) {
                    throw new InvalidArgumentException('Insurance subscription not found.');
                }

                $newStatus = ((string) $validated['review_decision']) === 'approve' ? 'approved' : 'rejected';
                DB::table('insurance_cancellations')
                    ->where('id', $this->rowInt($cancellation, 'id'))
                    ->update([
                        'status' => $newStatus,
                        'approved_by_user_id' => $actor->id,
                        'approved_at' => now(),
                        'updated_at' => now(),
                    ]);

                if ($newStatus === 'approved') {
                    $effectiveOn = $this->rowString($cancellation, 'effective_on');
                    $effectiveNow = $effectiveOn <= now()->toDateString();
                    DB::table('insurance_subscriptions')
                        ->where('id', $this->rowInt($subscription, 'id'))
                        ->update([
                            'status' => $effectiveNow ? 'cancelled' : 'active',
                            'lifecycle_status' => $effectiveNow ? 'cancelled' : 'cancellation_approved',
                            'cancelled_at' => $effectiveNow ? now() : null,
                            'updated_at' => now(),
                        ]);

                    DB::table('insurance_premium_schedules')
                        ->where('insurance_subscription_id', $this->rowInt($subscription, 'id'))
                        ->where('status', 'scheduled')
                        ->whereDate('due_on', '>=', $effectiveOn)
                        ->update(['status' => 'cancelled', 'updated_at' => now()]);

                    $refundJournalEntry = $this->insuranceAccounting->postCancellationRefundIfRequired($cancellation, $subscription, $actor);
                    if ($refundJournalEntry instanceof JournalEntry) {
                        DB::table('insurance_cancellations')
                            ->where('id', $this->rowInt($cancellation, 'id'))
                            ->update([
                                'refund_journal_entry_id' => $refundJournalEntry->id,
                                'updated_at' => now(),
                            ]);
                    }
                }

                $updatedCancellation = DB::table('insurance_cancellations')
                    ->where('id', $this->rowInt($cancellation, 'id'))
                    ->first();
                $updatedSubscription = DB::table('insurance_subscriptions')
                    ->where('id', $this->rowInt($subscription, 'id'))
                    ->first();

                if (! is_object($updatedCancellation) || ! is_object($updatedSubscription)) {
                    throw new InvalidArgumentException('Cancellation review could not be reloaded.');
                }

                return [
                    'cancellation' => $updatedCancellation,
                    'subscription' => $updatedSubscription,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['cancellation' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.subscription.cancellation_reviewed', actor: $actor, properties: [
            'cancellation_public_id' => $cancellationPublicId,
            'status' => $this->rowString($result['cancellation'], 'status'),
        ], request: $request);

        return $this->respondSuccess([
            'cancellation' => [
                'public_id' => $this->rowString($result['cancellation'], 'public_id'),
                'effective_on' => $this->rowString($result['cancellation'], 'effective_on'),
                'refund_treatment' => $this->rowString($result['cancellation'], 'refund_treatment'),
                'refund_amount_minor' => $this->rowNullableInt($result['cancellation'], 'refund_amount_minor'),
                'refund_journal_entry_public_id' => $this->journalEntryPublicId($this->rowNullableInt($result['cancellation'], 'refund_journal_entry_id')),
                'status' => $this->rowString($result['cancellation'], 'status'),
            ],
            'subscription' => $this->subscriptionPayload($result['subscription']),
        ], 'Cancellation request reviewed successfully');
    }

    public function reversePremiumPayment(Request $request, string $paymentPublicId): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.reversals.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($actor, $paymentPublicId): array {
                $payment = DB::table('insurance_premium_payments')
                    ->where('public_id', $paymentPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($payment)) {
                    throw new InvalidArgumentException('Premium payment not found.');
                }
                if ($this->rowString($payment, 'status') !== 'posted') {
                    throw new InvalidArgumentException('Only posted premium payments can be reversed.');
                }
                if ($this->rowNullableString($payment, 'reversed_at') !== null) {
                    throw new InvalidArgumentException('This premium payment has already been reversed.');
                }

                $originalJe = JournalEntry::find($this->rowNullableInt($payment, 'journal_entry_id'));
                if (! $originalJe instanceof JournalEntry) {
                    throw new InvalidArgumentException('Original journal entry not found; cannot reverse.');
                }

                $assessment = DB::table('insurance_premium_assessments')
                    ->where('id', $this->rowInt($payment, 'insurance_premium_assessment_id'))
                    ->lockForUpdate()
                    ->first();

                // Create reversing journal entry
                $reversalJe = JournalEntry::create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'REV-'.Str::upper(Str::random(10)),
                    'business_date' => now()->toDateString(),
                    'agency_id' => $originalJe->agency_id,
                    'source_module' => 'insurance',
                    'source_type' => 'insurance_premium_payment_reversal',
                    'source_public_id' => $paymentPublicId,
                    'description' => 'Reversal of premium payment '.$paymentPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'created_by_user_id' => $actor->id,
                ]);

                // Mirror the original lines with swapped debit/credit
                foreach ($originalJe->lines as $line) {
                    JournalLine::create([
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $originalJe->agency_id,
                        'journal_entry_id' => $reversalJe->id,
                        'ledger_account_id' => $line->ledger_account_id,
                        'customer_account_id' => null,
                        'loan_id' => null,
                        'debit_minor' => $line->credit_minor,
                        'credit_minor' => $line->debit_minor,
                        'currency' => $line->currency,
                        'line_memo' => 'Reversal: '.($line->line_memo ?? ''),
                    ]);
                }

                $this->insuranceAccounting->postSystemJournal($reversalJe, $actor);

                DB::table('insurance_premium_payments')
                    ->where('id', $this->rowInt($payment, 'id'))
                    ->update([
                        'status' => 'reversed',
                        'reversed_at' => now(),
                        'reversal_journal_entry_id' => $reversalJe->id,
                        'updated_at' => now(),
                    ]);

                // Reopen assessment
                if (is_object($assessment)) {
                    DB::table('insurance_premium_assessments')
                        ->where('id', $this->rowInt($assessment, 'id'))
                        ->update([
                            'status' => 'assessed',
                            'journal_entry_id' => null,
                            'updated_at' => now(),
                        ]);
                }

                return [
                    'payment' => DB::table('insurance_premium_payments')->where('id', $this->rowInt($payment, 'id'))->first() ?? $payment,
                    'reversal_journal_entry_public_id' => $reversalJe->public_id,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['premium_payment' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.premium_payment.reversed', actor: $actor, properties: [
            'payment_public_id' => $paymentPublicId,
            'reversal_journal_entry_public_id' => $result['reversal_journal_entry_public_id'],
        ], request: $request);

        return $this->respondSuccess([
            'payment_public_id' => $paymentPublicId,
            'status' => 'reversed',
            'reversal_journal_entry_public_id' => $result['reversal_journal_entry_public_id'],
        ], 'Premium payment reversed successfully');
    }

    public function reverseClaimSettlement(Request $request, string $claimPublicId): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.reversals.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $result = DB::transaction(function () use ($actor, $claimPublicId): array {
                $claim = DB::table('insurance_claims')
                    ->where('public_id', $claimPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($claim)) {
                    throw new InvalidArgumentException('Insurance claim not found.');
                }
                if ($this->rowString($claim, 'status') !== 'settled') {
                    throw new InvalidArgumentException('Only settled claims can have their settlement reversed.');
                }
                if ($this->rowNullableString($claim, 'reversal_at') !== null) {
                    throw new InvalidArgumentException('Settlement has already been reversed.');
                }

                $originalJeId = $this->rowNullableInt($claim, 'journal_entry_id');
                if ($originalJeId === null) {
                    throw new InvalidArgumentException('No journal entry found for this settlement.');
                }
                $originalJe = JournalEntry::find($originalJeId);
                if (! $originalJe instanceof JournalEntry) {
                    throw new InvalidArgumentException('Settlement journal entry not found.');
                }

                $reversalJe = JournalEntry::create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'REV-'.Str::upper(Str::random(10)),
                    'business_date' => now()->toDateString(),
                    'agency_id' => $originalJe->agency_id,
                    'source_module' => 'insurance',
                    'source_type' => 'insurance_claim_settlement_reversal',
                    'source_public_id' => $claimPublicId,
                    'description' => 'Reversal of claim settlement '.$claimPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'created_by_user_id' => $actor->id,
                ]);

                foreach ($originalJe->lines as $line) {
                    JournalLine::create([
                        'public_id' => (string) Str::ulid(),
                        'agency_id' => $originalJe->agency_id,
                        'journal_entry_id' => $reversalJe->id,
                        'ledger_account_id' => $line->ledger_account_id,
                        'customer_account_id' => null,
                        'loan_id' => null,
                        'debit_minor' => $line->credit_minor,
                        'credit_minor' => $line->debit_minor,
                        'currency' => $line->currency,
                        'line_memo' => 'Reversal: '.($line->line_memo ?? ''),
                    ]);
                }

                $this->insuranceAccounting->postSystemJournal($reversalJe, $actor);

                DB::table('insurance_claims')
                    ->where('id', $this->rowInt($claim, 'id'))
                    ->update([
                        'status' => 'settlement_reversed',
                        'reversal_at' => now(),
                        'reversal_journal_entry_id' => $reversalJe->id,
                        'updated_at' => now(),
                    ]);

                return [
                    'claim' => DB::table('insurance_claims')->where('id', $this->rowInt($claim, 'id'))->first() ?? $claim,
                    'reversal_journal_entry_public_id' => $reversalJe->public_id,
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_claim' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.claim.settlement_reversed', actor: $actor, properties: [
            'claim_public_id' => $claimPublicId,
        ], request: $request);

        return $this->respondSuccess([
            'claim' => $this->claimPayload($result['claim']),
            'reversal_journal_entry_public_id' => $result['reversal_journal_entry_public_id'],
        ], 'Claim settlement reversed successfully');
    }

    // -------------------------------------------------------------------------
    // A11: Insurer Remittance & Commission Accounting
    // -------------------------------------------------------------------------

    public function storeRemittanceBatch(Request $request): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.remittances.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        $validated = Validator::make($request->all(), [
            'insurance_partner_public_id' => ['required', 'string', 'exists:insurance_partners,public_id'],
            'agency_public_id' => ['required', 'string', 'exists:agencies,public_id'],
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ])->validate();

        try {
            $batch = DB::transaction(function () use ($actor, $validated): object {
                $partner = DB::table('insurance_partners')
                    ->where('public_id', (string) $validated['insurance_partner_public_id'])
                    ->first(['id']);
                if (! is_object($partner)) {
                    throw new InvalidArgumentException('Insurance partner not found.');
                }
                $agency = DB::table('agencies')
                    ->where('public_id', (string) $validated['agency_public_id'])
                    ->first(['id']);
                if (! is_object($agency)) {
                    throw new InvalidArgumentException('Agency not found.');
                }

                $currency = $this->stringValue($validated['currency'] ?? 'XAF', 'XAF');
                $periodFrom = (string) $validated['period_from'];
                $periodTo = (string) $validated['period_to'];
                $partnerId = $this->rowInt($partner, 'id');
                $agencyId = $this->rowInt($agency, 'id');

                // Gather premium payments linked to this partner/agency/period with posted split snapshots.
                $payments = DB::table('insurance_premium_payments')
                    ->join('insurance_premium_assessments', 'insurance_premium_assessments.id', '=', 'insurance_premium_payments.insurance_premium_assessment_id')
                    ->join('insurance_subscriptions', 'insurance_subscriptions.id', '=', 'insurance_premium_assessments.insurance_subscription_id')
                    ->join('insurance_products', 'insurance_products.id', '=', 'insurance_subscriptions.insurance_product_id')
                    ->join('insurance_premium_payment_splits', 'insurance_premium_payment_splits.insurance_premium_payment_id', '=', 'insurance_premium_payments.id')
                    ->where('insurance_products.insurance_partner_id', $partnerId)
                    ->where('insurance_subscriptions.agency_id', $agencyId)
                    ->where('insurance_premium_payments.currency', $currency)
                    ->where('insurance_premium_payments.status', 'posted')
                    ->whereNull('insurance_premium_payments.remitted_at')
                    ->whereBetween('insurance_premium_payments.paid_at', [$periodFrom.' 00:00:00', $periodTo.' 23:59:59'])
                    ->select([
                        'insurance_premium_payments.id',
                        'insurance_premium_payments.public_id',
                        'insurance_products.id as product_id',
                        'insurance_premium_payment_splits.split_type',
                        'insurance_premium_payment_splits.amount_minor',
                        'insurance_premium_payment_splits.ledger_account_id',
                    ])
                    ->get();

                if ($payments->isEmpty()) {
                    throw new InvalidArgumentException('No eligible unremitted premium payments found for this partner/agency/period.');
                }

                $batchId = DB::table('insurance_remittance_batches')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_partner_id' => $partnerId,
                    'agency_id' => $agencyId,
                    'period_from' => $periodFrom,
                    'period_to' => $periodTo,
                    'currency' => $currency,
                    'total_minor' => 0,
                    'status' => 'draft',
                    'created_by_user_id' => $actor->id,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'journal_entry_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $total = 0;
                foreach ($payments as $payment) {
                    $splitType = (string) $payment->split_type;
                    $splitAmount = (int) $payment->amount_minor;
                    if ($splitAmount <= 0) {
                        continue;
                    }

                    DB::table('insurance_remittance_items')->insert([
                        'public_id' => (string) Str::ulid(),
                        'insurance_remittance_batch_id' => $batchId,
                        'insurance_premium_payment_id' => (int) $payment->id,
                        'insurance_product_id' => (int) $payment->product_id,
                        'split_type' => $splitType,
                        'amount_minor' => $splitAmount,
                        'ledger_account_id' => (int) $payment->ledger_account_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    if ($splitType === 'insurer_payable') {
                        $total += $splitAmount;
                    }
                }

                DB::table('insurance_remittance_batches')
                    ->where('id', $batchId)
                    ->update(['total_minor' => $total, 'updated_at' => now()]);

                $row = DB::table('insurance_remittance_batches')->where('id', $batchId)->first();
                if (! is_object($row)) {
                    throw new InvalidArgumentException('Remittance batch could not be reloaded.');
                }

                return $row;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['remittance_batch' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.remittance_batch.created', actor: $actor, properties: [
            'batch_public_id' => $this->rowString($batch, 'public_id'),
        ], request: $request);

        return $this->respondCreated($this->remittanceBatchPayload($batch), 'Remittance batch created successfully');
    }

    public function approveRemittanceBatch(Request $request, string $batchPublicId): JsonResponse
    {
        $actor = $this->insuranceActor($request, 'insurance.remittances.manage');
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        try {
            $batch = DB::transaction(function () use ($actor, $batchPublicId): object {
                $batch = DB::table('insurance_remittance_batches')
                    ->where('public_id', $batchPublicId)
                    ->lockForUpdate()
                    ->first();
                if (! is_object($batch)) {
                    throw new InvalidArgumentException('Remittance batch not found.');
                }
                if ($this->rowString($batch, 'status') !== 'draft') {
                    throw new InvalidArgumentException('Only draft remittance batches can be approved.');
                }
                if ($this->rowInt($batch, 'created_by_user_id') === $actor->id) {
                    throw new InvalidArgumentException('The creator cannot approve their own remittance batch.');
                }

                $items = DB::table('insurance_remittance_items')
                    ->where('insurance_remittance_batch_id', $this->rowInt($batch, 'id'))
                    ->get();

                foreach ($items as $item) {
                    $alreadyRemitted = DB::table('insurance_premium_payments')
                        ->where('id', $this->rowInt($item, 'insurance_premium_payment_id'))
                        ->whereNotNull('remitted_at')
                        ->exists();
                    if ($alreadyRemitted) {
                        throw new InvalidArgumentException('Batch contains already-remitted payments. Recreate the batch.');
                    }
                }

                $journalEntry = $this->insuranceAccounting->postRemittanceBatchJournal($batch, $items, $actor);

                // Mark payments remitted against the insurer payable item; commission/tax split rows remain reportable in the batch.
                foreach ($items as $item) {
                    if ($this->rowString($item, 'split_type') !== 'insurer_payable') {
                        continue;
                    }
                    DB::table('insurance_premium_payments')
                        ->where('id', $this->rowInt($item, 'insurance_premium_payment_id'))
                        ->update([
                            'remitted_at' => now(),
                            'remittance_batch_item_id' => $this->rowInt($item, 'id'),
                            'updated_at' => now(),
                        ]);
                }

                DB::table('insurance_remittance_batches')
                    ->where('id', $this->rowInt($batch, 'id'))
                    ->update([
                        'status' => 'posted',
                        'approved_by_user_id' => $actor->id,
                        'approved_at' => now(),
                        'journal_entry_id' => $journalEntry->id,
                        'updated_at' => now(),
                    ]);

                return DB::table('insurance_remittance_batches')->where('id', $this->rowInt($batch, 'id'))->first() ?? $batch;
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['remittance_batch' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('insurance.remittance_batch.approved', actor: $actor, properties: [
            'batch_public_id' => $batchPublicId,
        ], request: $request);

        return $this->respondSuccess($this->remittanceBatchPayload($batch), 'Remittance batch approved and posted');
    }

    public function commissionsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->commissions($request);
    }

    public function remittancesReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->remittances($request);
    }

    public function lossRatioReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->lossRatio($request);
    }

    public function cancellationsRefundsReport(Request $request): JsonResponse
    {
        return $this->insuranceReportWorkflow->cancellationsRefunds($request);
    }

    // -------------------------------------------------------------------------
    // A13: Insurance Exports
    // -------------------------------------------------------------------------

    public function exportSubscriptions(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->subscriptions($request);
    }

    public function exportPremiums(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->premiums($request);
    }

    public function exportClaims(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->claims($request);
    }

    public function exportCommissions(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->commissions($request);
    }

    public function exportRemittances(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->remittances($request);
    }

    public function exportCancellationsRefunds(Request $request): JsonResponse
    {
        return $this->insuranceExportWorkflow->cancellationsRefunds($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function endorsementPayload(object $endorsement): array
    {
        return [
            'public_id' => $this->rowString($endorsement, 'public_id'),
            'endorsement_type' => $this->rowString($endorsement, 'endorsement_type'),
            'effective_on' => $this->rowString($endorsement, 'effective_on'),
            'status' => $this->rowString($endorsement, 'status'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remittanceBatchPayload(object $batch): array
    {
        return [
            'public_id' => $this->rowString($batch, 'public_id'),
            'period_from' => $this->rowString($batch, 'period_from'),
            'period_to' => $this->rowString($batch, 'period_to'),
            'currency' => $this->rowString($batch, 'currency'),
            'total_minor' => $this->rowInt($batch, 'total_minor'),
            'status' => $this->rowString($batch, 'status'),
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
