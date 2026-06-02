<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Http\Controllers\BaseController;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Finance\PhysicalCashAmount;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InsurancePremiumWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly AccountingBalanceCalculator $balanceCalculator,
        private readonly InsuranceAccountingService $insuranceAccounting,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function storeAssessment(Request $request, string $subscriptionPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.premiums.manage');
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

    public function collectFromAccount(Request $request, string $assessmentPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.premiums.collect');
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

                $accountingDay = $this->accountingDayGuard->assertCanRegister(
                    $actor,
                    'insurance.premium',
                    $this->rowInt($subscription, 'agency_id'),
                );
                $businessDate = $accountingDay->business_date->toDateString();
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'insurance-premium-collection:'.$assessmentPublicId;
                $reference = 'IPC-'.Str::upper(Str::random(10));

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $reference,
                    'business_date' => $businessDate,
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
                    'accounting_day_id' => $accountingDay->id,
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

    public function collectCash(Request $request, string $assessmentPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.premiums.collect');
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

                $accountingDay = $this->accountingDayGuard->assertCanRegister(
                    $actor,
                    'insurance.premium',
                    $agencyId,
                );
                $businessDate = $accountingDay->business_date->toDateString();
                $paidDate = is_string($validated['paid_on'] ?? null) && $validated['paid_on'] !== ''
                    ? $validated['paid_on']
                    : $businessDate;
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'insurance-premium-cash:'.$assessmentPublicId;
                $reference = 'IPC-CASH-'.Str::upper(Str::random(10));

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $reference,
                    'business_date' => $businessDate,
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
                    'accounting_day_id' => $accountingDay->id,
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

    public function reversePayment(Request $request, string $paymentPublicId): JsonResponse
    {
        $actor = $this->actor($request, 'insurance.reversals.manage');
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

                $accountingDay = $this->accountingDayGuard->assertCanRegister(
                    $actor,
                    'insurance.premium',
                    $originalJe->agency_id,
                );

                $reversalJe = JournalEntry::create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => 'REV-'.Str::upper(Str::random(10)),
                    'business_date' => $accountingDay->business_date->toDateString(),
                    'agency_id' => $originalJe->agency_id,
                    'source_module' => 'insurance',
                    'source_type' => 'insurance_premium_payment_reversal',
                    'source_public_id' => $paymentPublicId,
                    'description' => 'Reversal of premium payment '.$paymentPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'created_by_user_id' => $actor->id,
                    'accounting_day_id' => $accountingDay->id,
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

                DB::table('insurance_premium_payments')
                    ->where('id', $this->rowInt($payment, 'id'))
                    ->update([
                        'status' => 'reversed',
                        'reversed_at' => now(),
                        'reversal_journal_entry_id' => $reversalJe->id,
                        'updated_at' => now(),
                    ]);

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

    private function actor(Request $request, string $permission): ?User
    {
        $actor = $request->user();

        return $actor instanceof User && $actor->hasPermissionTo($permission) ? $actor : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function premiumPaymentPayload(object $payment): array
    {
        return [
            'public_id' => $this->rowString($payment, 'public_id'),
            'amount_minor' => $this->rowInt($payment, 'amount_minor'),
            'currency' => $this->rowString($payment, 'currency'),
            'payment_method' => $this->rowString($payment, 'payment_method'),
            'paid_at' => $this->rowNullableString($payment, 'paid_at'),
            'status' => $this->rowString($payment, 'status'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function premiumAssessmentPayload(object $assessment, string $subscriptionPublicId): array
    {
        return [
            'public_id' => $this->rowString($assessment, 'public_id'),
            'subscription_public_id' => $subscriptionPublicId,
            'due_on' => $this->rowString($assessment, 'due_on'),
            'premium_amount_minor' => $this->rowInt($assessment, 'premium_amount_minor'),
            'currency' => $this->rowString($assessment, 'currency'),
            'status' => $this->rowString($assessment, 'status'),
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
