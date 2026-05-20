<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Http\Controllers\BaseController;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\LoanResource;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\Finance\FormulaPolicyNotApproved;
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

final class LoanSetupChargeWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly AssessLoanSetupCharges $assessLoanSetupCharges,
        private readonly AccountingBalanceCalculator $balanceCalculator,
    ) {}

    public function assessSetupCharges(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        try {
            $result = $this->assessLoanSetupCharges->handle($loan);
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['fees_taxes_insurance' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['setup_charges' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.setup_charges.assessed', actor: $actor, subject: $loan, request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($this->loanResult($result)->loadMissing($this->relations())),
            'charges' => $result['charges'],
            'insurance_premium_assessment' => $result['insurance_premium_assessment'],
        ], 'Loan setup charges assessed successfully');
    }

    public function decideSetupChargeException(Request $request, Loan $loan, string $chargePublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || (! $actor->hasRole('platform-admin') && ! $actor->can('loans.approvals.direction'))) {
            return $this->respondForbidden('Direction approval is required for setup charge exceptions.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['collect_as_assessed', 'waive'])],
            'comments' => ['required', 'string', 'max:1000'],
        ])->validate();

        $charge = DB::transaction(function () use ($actor, $chargePublicId, $loan, $validated): ?object {
            $charge = DB::table('loan_charge_assessments')
                ->where('loan_id', $loan->id)
                ->where('public_id', $chargePublicId)
                ->whereIn('charge_type', ['dossier_fee', 'dossier_fee_tax'])
                ->lockForUpdate()
                ->first();

            if (! is_object($charge)) {
                return null;
            }

            $metadata = $this->chargeMetadata($charge);
            $metadata['direction_exception_decision'] = [
                'decision' => (string) $validated['decision'],
                'comments' => (string) $validated['comments'],
                'decided_by_user_public_id' => $actor->public_id,
                'decided_at' => now()->toISOString(),
                'manual_decision_only' => true,
            ];

            DB::table('loan_charge_assessments')
                ->where('id', $this->chargeInt($charge, 'id'))
                ->update([
                    'status' => $validated['decision'] === 'waive' ? 'waived_by_direction' : 'assessed',
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'updated_at' => now(),
                ]);

            return DB::table('loan_charge_assessments')->where('id', $this->chargeInt($charge, 'id'))->first();
        });

        if (! is_object($charge)) {
            return $this->respondUnprocessable(errors: ['setup_charge' => ['Direction setup charge decisions apply only to assessed dossier fee or dossier fee tax charges on this loan.']]);
        }

        $this->securityAudit->record('loan.setup_charge_exception.decided', actor: $actor, subject: $loan, properties: [
            'charge_public_id' => $chargePublicId,
            'decision' => $validated['decision'],
        ], request: $request);

        return $this->respondSuccess([
            'charge' => $this->chargePayload($charge),
        ], 'Setup charge exception decision recorded successfully');
    }

    public function collectSetupCharge(Request $request, Loan $loan, string $chargePublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'payment_source' => ['sometimes', 'string', Rule::in(['customer_account', 'teller_cash'])],
            'customer_account_public_id' => ['required_if:payment_source,customer_account', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'teller_session_public_id' => ['required_if:payment_source,teller_cash', 'nullable', 'string', 'exists:teller_sessions,public_id'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $chargePublicId, $loan, $validated): array {
                $paymentSource = $this->stringValue($validated['payment_source'] ?? 'customer_account', 'customer_account');
                $charge = DB::table('loan_charge_assessments')
                    ->where('loan_id', $loan->id)
                    ->where('public_id', $chargePublicId)
                    ->whereIn('charge_type', ['dossier_fee', 'dossier_fee_tax', 'guarantee_deposit'])
                    ->lockForUpdate()
                    ->first();

                if (! is_object($charge)) {
                    throw new InvalidArgumentException('The selected setup charge is invalid for this loan.');
                }

                $existingJournalId = $this->chargeNullableInt($charge, 'journal_entry_id');
                if ($this->chargeString($charge, 'status') === 'paid' && $existingJournalId !== null) {
                    $existingJournal = JournalEntry::query()->with(['lines.ledgerAccount', 'lines.customerAccount'])->whereKey($existingJournalId)->first();
                    if (! $existingJournal instanceof JournalEntry) {
                        throw new InvalidArgumentException('Collected setup charge is missing its journal entry.');
                    }

                    return [
                        'charge' => $charge,
                        'journal_entry' => $existingJournal,
                    ];
                }

                if ($this->chargeString($charge, 'status') !== 'assessed') {
                    throw new InvalidArgumentException('Only assessed setup charges can be collected.');
                }

                $amountMinor = $this->chargeInt($charge, 'assessed_amount_minor');
                if ($amountMinor <= 0) {
                    throw new InvalidArgumentException('Setup charge amount must be positive before collection.');
                }

                $currency = $this->chargeString($charge, 'currency');
                $creditLedgerId = $this->setupChargeCreditLedgerId($this->chargeString($charge, 'charge_type'), $loan->agency_id, $currency);
                $debitContext = $paymentSource === 'teller_cash'
                    ? $this->setupChargeTellerCashDebitContext($loan, $validated, $amountMinor, $currency)
                    : $this->setupChargeCustomerAccountDebitContext($loan, $validated, $amountMinor, $currency);
                $paidDate = is_string($validated['paid_on'] ?? null) ? $validated['paid_on'] : now()->toDateString();
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'loan-setup-charge:'.$chargePublicId;
                $reference = 'LSC-'.$loan->loan_number.'-'.Str::upper(Str::random(8));

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $reference,
                    'business_date' => $paidDate,
                    'posted_at' => null,
                    'agency_id' => $loan->agency_id,
                    'source_module' => 'credit_loans',
                    'source_type' => 'loan_setup_charge_collection',
                    'source_public_id' => $chargePublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => is_string($validated['notes'] ?? null) ? $validated['notes'] : 'Loan setup charge collection '.$loan->loan_number,
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                $customerAccount = $debitContext['customer_account'];
                $tellerSession = $debitContext['teller_session'];
                $till = $debitContext['till'];
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $loan->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $debitContext['ledger']->id,
                    'customer_account_id' => $customerAccount instanceof CustomerAccount ? $customerAccount->id : null,
                    'loan_id' => $loan->id,
                    'debit_minor' => $amountMinor,
                    'credit_minor' => 0,
                    'currency' => $currency,
                    'line_memo' => $paymentSource === 'teller_cash'
                        ? 'Loan setup charge received in teller till'
                        : 'Loan setup charge debited from customer account',
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $loan->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $creditLedgerId,
                    'customer_account_id' => null,
                    'loan_id' => $loan->id,
                    'debit_minor' => 0,
                    'credit_minor' => $amountMinor,
                    'currency' => $currency,
                    'line_memo' => 'Loan setup charge collected: '.$this->chargeString($charge, 'charge_type'),
                ]);

                $tellerTransaction = null;
                if ($tellerSession instanceof TellerSession && $till instanceof Till) {
                    $tellerReference = 'LSC-CASH-'.Str::upper(Str::random(8));
                    $tellerTransaction = TellerTransaction::query()->create([
                        'public_id' => (string) Str::ulid(),
                        'teller_session_id' => $tellerSession->id,
                        'agency_id' => $loan->agency_id,
                        'transaction_date' => $paidDate,
                        'till_id' => $till->id,
                        'transaction_type' => TellerTransaction::TYPE_CASH_DEPOSIT,
                        'client_id' => $loan->client_id,
                        'customer_account_id' => null,
                        'loan_id' => $loan->id,
                        'amount_minor' => $amountMinor,
                        'currency' => $currency,
                        'status' => TellerTransaction::STATUS_POSTED,
                        'reference' => $tellerReference,
                        'event_number' => $tellerReference,
                        'idempotency_key' => $idempotencyKey,
                        'journal_entry_id' => $journalEntry->id,
                        'operation_code' => 'loan_setup_charge_collection',
                        'description' => is_string($validated['notes'] ?? null) ? $validated['notes'] : 'Loan setup charge cash collection '.$loan->loan_number,
                    ]);
                }

                $this->postSystemJournal($journalEntry, $actor);

                $metadata = $this->chargeMetadata($charge);
                $metadata['collection'] = [
                    'method' => $paymentSource,
                    'customer_account_public_id' => $customerAccount instanceof CustomerAccount ? $customerAccount->public_id : null,
                    'teller_session_public_id' => $tellerSession instanceof TellerSession ? $tellerSession->public_id : null,
                    'teller_transaction_public_id' => $tellerTransaction instanceof TellerTransaction ? $tellerTransaction->public_id : null,
                    'collected_by_user_public_id' => $actor->public_id,
                    'collected_at' => now()->toISOString(),
                ];

                DB::table('loan_charge_assessments')
                    ->where('id', $this->chargeInt($charge, 'id'))
                    ->update([
                        'status' => 'paid',
                        'paid_at' => now(),
                        'journal_entry_id' => $journalEntry->id,
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);

                $updatedCharge = DB::table('loan_charge_assessments')->where('id', $this->chargeInt($charge, 'id'))->first();
                if (! is_object($updatedCharge)) {
                    throw new InvalidArgumentException('Collected setup charge could not be reloaded.');
                }

                return [
                    'charge' => $updatedCharge,
                    'journal_entry' => $journalEntry->refresh()->loadMissing(['lines.ledgerAccount', 'lines.customerAccount']),
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['setup_charge_collection' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.setup_charge.collected', actor: $actor, subject: $loan, properties: [
            'charge_public_id' => $chargePublicId,
            'journal_entry_public_id' => $result['journal_entry']->public_id,
        ], request: $request);

        return $this->respondSuccess([
            'charge' => $this->chargePayload($result['charge']),
            'journal_entry' => JournalEntryResource::make($result['journal_entry']),
        ], 'Setup charge collected successfully');
    }

    public function collectInsurancePremium(Request $request, Loan $loan, string $premiumPublicId): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'customer_account_public_id' => ['required', 'string', 'exists:customer_accounts,public_id'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = DB::transaction(function () use ($actor, $loan, $premiumPublicId, $validated): array {
                $assessment = DB::table('insurance_premium_assessments')
                    ->where('loan_id', $loan->id)
                    ->where('public_id', $premiumPublicId)
                    ->lockForUpdate()
                    ->first();

                if (! is_object($assessment)) {
                    throw new InvalidArgumentException('The selected insurance premium is invalid for this loan.');
                }

                $existingPayment = DB::table('insurance_premium_payments')
                    ->where('insurance_premium_assessment_id', $this->chargeInt($assessment, 'id'))
                    ->whereIn('status', ['posted', 'paid', 'collected'])
                    ->orderByDesc('id')
                    ->first();
                $existingJournalId = $this->chargeNullableInt($assessment, 'journal_entry_id');
                if (is_object($existingPayment) && $existingJournalId !== null) {
                    $existingJournal = JournalEntry::query()->with(['lines.ledgerAccount', 'lines.customerAccount'])->whereKey($existingJournalId)->first();
                    if (! $existingJournal instanceof JournalEntry) {
                        throw new InvalidArgumentException('Collected insurance premium is missing its journal entry.');
                    }

                    return [
                        'assessment' => $assessment,
                        'payment' => $existingPayment,
                        'journal_entry' => $existingJournal,
                    ];
                }

                if ($this->chargeString($assessment, 'status') !== 'assessed') {
                    throw new InvalidArgumentException('Only assessed insurance premiums can be collected.');
                }

                $amountMinor = $this->chargeInt($assessment, 'premium_amount_minor');
                if ($amountMinor <= 0) {
                    throw new InvalidArgumentException('Insurance premium amount must be positive before collection.');
                }

                $currency = $this->chargeString($assessment, 'currency');
                $customerAccount = CustomerAccount::query()
                    ->with(['ledgerAccount'])
                    ->where('public_id', $this->stringValue($validated['customer_account_public_id'] ?? null, ''))
                    ->first();
                if (! $customerAccount instanceof CustomerAccount
                    || $customerAccount->status !== CustomerAccount::STATUS_ACTIVE
                    || $customerAccount->client_id !== $loan->client_id
                    || $customerAccount->agency_id !== $loan->agency_id
                    || $customerAccount->currency !== $currency
                    || $customerAccount->ledger_account_id === null) {
                    throw new InvalidArgumentException('Collection account must be active and belong to the loan client, agency, and currency.');
                }

                $customerLedger = $customerAccount->ledgerAccount;
                if (! $customerLedger instanceof LedgerAccount || $customerLedger->status !== LedgerAccount::STATUS_ACTIVE || $customerLedger->agency_id !== $loan->agency_id) {
                    throw new InvalidArgumentException('Collection account ledger must be active and belong to the loan agency.');
                }

                $availableBalance = $this->balanceCalculator->availableForCustomerAccount($customerAccount, $currency)['available_balance_minor'];
                if ($amountMinor > $availableBalance) {
                    throw new InvalidArgumentException('Insurance premium collection exceeds the customer account available balance.');
                }

                $paidDate = is_string($validated['paid_on'] ?? null) ? $validated['paid_on'] : now()->toDateString();
                $idempotencyKey = is_string($validated['idempotency_key'] ?? null) && $validated['idempotency_key'] !== ''
                    ? $validated['idempotency_key']
                    : 'loan-insurance-premium:'.$premiumPublicId;
                $reference = 'LIP-'.$loan->loan_number.'-'.Str::upper(Str::random(8));
                $creditLedgerId = $this->insurancePremiumCreditLedgerId($loan->agency_id, $currency);

                $journalEntry = JournalEntry::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'reference' => $reference,
                    'business_date' => $paidDate,
                    'posted_at' => null,
                    'agency_id' => $loan->agency_id,
                    'source_module' => 'credit_loans',
                    'source_type' => 'loan_insurance_premium_payment',
                    'source_public_id' => $premiumPublicId,
                    'status' => JournalEntry::STATUS_DRAFT,
                    'description' => is_string($validated['notes'] ?? null) ? $validated['notes'] : 'Loan insurance premium collection '.$loan->loan_number,
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => null,
                    'idempotency_key' => $idempotencyKey,
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $loan->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $customerLedger->id,
                    'customer_account_id' => $customerAccount->id,
                    'loan_id' => $loan->id,
                    'debit_minor' => $amountMinor,
                    'credit_minor' => 0,
                    'currency' => $currency,
                    'line_memo' => 'Loan insurance premium debited from customer account',
                ]);

                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $loan->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $creditLedgerId,
                    'customer_account_id' => null,
                    'loan_id' => $loan->id,
                    'debit_minor' => 0,
                    'credit_minor' => $amountMinor,
                    'currency' => $currency,
                    'line_memo' => 'Loan insurance premium collected',
                ]);

                $this->postSystemJournal($journalEntry, $actor);

                $paymentId = DB::table('insurance_premium_payments')->insertGetId([
                    'public_id' => (string) Str::ulid(),
                    'insurance_premium_assessment_id' => $this->chargeInt($assessment, 'id'),
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

                $metadata = $this->chargeMetadata($assessment);
                $metadata['collection'] = [
                    'method' => 'customer_account',
                    'customer_account_public_id' => $customerAccount->public_id,
                    'collected_by_user_public_id' => $actor->public_id,
                    'collected_at' => now()->toISOString(),
                    'journal_entry_public_id' => $journalEntry->public_id,
                ];

                DB::table('insurance_premium_assessments')
                    ->where('id', $this->chargeInt($assessment, 'id'))
                    ->update([
                        'status' => 'paid',
                        'journal_entry_id' => $journalEntry->id,
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);

                $updatedAssessment = DB::table('insurance_premium_assessments')->where('id', $this->chargeInt($assessment, 'id'))->first();
                $payment = DB::table('insurance_premium_payments')->where('id', $paymentId)->first();
                if (! is_object($updatedAssessment) || ! is_object($payment)) {
                    throw new InvalidArgumentException('Collected insurance premium could not be reloaded.');
                }

                return [
                    'assessment' => $updatedAssessment,
                    'payment' => $payment,
                    'journal_entry' => $journalEntry->refresh()->loadMissing(['lines.ledgerAccount', 'lines.customerAccount']),
                ];
            });
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['insurance_premium_collection' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.insurance_premium.collected', actor: $actor, subject: $loan, properties: [
            'premium_public_id' => $premiumPublicId,
            'journal_entry_public_id' => $result['journal_entry']->public_id,
        ], request: $request);

        return $this->respondSuccess([
            'insurance_premium_assessment' => $this->insurancePremiumPayload($result['assessment']),
            'insurance_premium_payment' => $this->insurancePremiumPaymentPayload($result['payment']),
            'journal_entry' => JournalEntryResource::make($result['journal_entry']),
        ], 'Insurance premium collected successfully');
    }

    private function canAccessLoanAgency(User $actor, Loan $loan): bool
    {
        return $actor->hasRole('platform-admin')
            || $actor->can('crm.scope.institution.read')
            || $this->staffAgencyScope->currentAgencyId($actor) === $loan->agency_id;
    }

    /**
     * @return array<string, mixed>
     */
    private function chargePayload(object $charge): array
    {
        return [
            'public_id' => $this->chargeString($charge, 'public_id'),
            'charge_type' => $this->chargeString($charge, 'charge_type'),
            'base_amount_minor' => $this->chargeNullableInt($charge, 'base_amount_minor'),
            'rate' => $this->chargeNullableString($charge, 'rate'),
            'assessed_amount_minor' => $this->chargeInt($charge, 'assessed_amount_minor'),
            'currency' => $this->chargeString($charge, 'currency'),
            'status' => $this->chargeString($charge, 'status'),
            'metadata' => $this->chargeMetadata($charge),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function chargeMetadata(object $charge): array
    {
        $metadata = $this->chargeNullableString($charge, 'metadata');
        if ($metadata === null || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{ledger:LedgerAccount, customer_account:CustomerAccount, teller_session:null, till:null}
     */
    private function setupChargeCustomerAccountDebitContext(Loan $loan, array $validated, int $amountMinor, string $currency): array
    {
        $customerAccount = CustomerAccount::query()
            ->with(['ledgerAccount'])
            ->where('public_id', $this->stringValue($validated['customer_account_public_id'] ?? null, ''))
            ->first();
        if (! $customerAccount instanceof CustomerAccount
            || $customerAccount->status !== CustomerAccount::STATUS_ACTIVE
            || $customerAccount->client_id !== $loan->client_id
            || $customerAccount->agency_id !== $loan->agency_id
            || $customerAccount->currency !== $currency
            || $customerAccount->ledger_account_id === null) {
            throw new InvalidArgumentException('Collection account must be active and belong to the loan client, agency, and currency.');
        }

        $customerLedger = $customerAccount->ledgerAccount;
        if (! $customerLedger instanceof LedgerAccount || $customerLedger->status !== LedgerAccount::STATUS_ACTIVE || $customerLedger->agency_id !== $loan->agency_id) {
            throw new InvalidArgumentException('Collection account ledger must be active and belong to the loan agency.');
        }

        $availableBalance = $this->balanceCalculator->availableForCustomerAccount($customerAccount, $currency)['available_balance_minor'];
        if ($amountMinor > $availableBalance) {
            throw new InvalidArgumentException('Setup charge collection exceeds the customer account available balance.');
        }

        return [
            'ledger' => $customerLedger,
            'customer_account' => $customerAccount,
            'teller_session' => null,
            'till' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{ledger:LedgerAccount, customer_account:null, teller_session:TellerSession, till:Till}
     */
    private function setupChargeTellerCashDebitContext(Loan $loan, array $validated, int $amountMinor, string $currency): array
    {
        if (! PhysicalCashAmount::validMinorAmount($amountMinor, $currency)) {
            throw new InvalidArgumentException(PhysicalCashAmount::validationMessage($currency));
        }

        $session = TellerSession::query()
            ->with(['till'])
            ->where('public_id', $this->stringValue($validated['teller_session_public_id'] ?? null, ''))
            ->first();
        if (! $session instanceof TellerSession
            || $session->status !== TellerSession::STATUS_OPEN
            || $session->agency_id !== $loan->agency_id
            || $session->currency !== $currency) {
            throw new InvalidArgumentException('Teller session must be open and belong to the loan agency and currency.');
        }

        $till = $session->till;
        if (! $till instanceof Till
            || $till->status !== Till::STATUS_ACTIVE
            || $till->daily_state !== Till::DAILY_STATE_OPEN
            || $till->agency_id !== $loan->agency_id
            || $till->currency !== $currency
            || $till->ledger_account_id === null) {
            throw new InvalidArgumentException('Open teller till with an active cash ledger is required for cash setup-charge collection.');
        }

        $tillLedger = LedgerAccount::query()->whereKey($till->ledger_account_id)->first();
        if (! $tillLedger instanceof LedgerAccount || $tillLedger->status !== LedgerAccount::STATUS_ACTIVE || $tillLedger->agency_id !== $loan->agency_id) {
            throw new InvalidArgumentException('Till cash ledger account must be active and belong to the loan agency.');
        }

        if ($till->max_balance_limit_minor !== null
            && $this->postedTillBalanceMinor($session) + $amountMinor > $till->max_balance_limit_minor) {
            throw new InvalidArgumentException('Cash setup-charge collection would push the till above its maximum balance limit.');
        }

        return [
            'ledger' => $tillLedger,
            'customer_account' => null,
            'teller_session' => $session,
            'till' => $till,
        ];
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

    private function postedTillBalanceMinor(TellerSession $session): int
    {
        $opening = $session->opening_declaration_minor ?? 0;
        $transactions = DB::table('teller_transactions')
            ->where('teller_session_id', $session->id)
            ->where('status', TellerTransaction::STATUS_POSTED)
            ->get(['transaction_type', 'amount_minor']);

        $movement = 0;
        foreach ($transactions as $transaction) {
            $type = is_string($transaction->transaction_type) ? $transaction->transaction_type : '';
            $amount = is_numeric($transaction->amount_minor) ? (int) $transaction->amount_minor : 0;

            if ($type === TellerTransaction::TYPE_CASH_DEPOSIT) {
                $movement += $amount;
            }

            if ($type === TellerTransaction::TYPE_CASH_WITHDRAWAL) {
                $movement -= $amount;
            }
        }

        return $opening + $movement;
    }

    /**
     * @return array<string, mixed>
     */
    private function insurancePremiumPayload(object $assessment): array
    {
        return [
            'public_id' => $this->chargeString($assessment, 'public_id'),
            'base_amount_minor' => $this->chargeNullableInt($assessment, 'base_amount_minor'),
            'rate' => $this->chargeNullableString($assessment, 'rate'),
            'premium_amount_minor' => $this->chargeInt($assessment, 'premium_amount_minor'),
            'currency' => $this->chargeString($assessment, 'currency'),
            'due_on' => $this->chargeNullableString($assessment, 'due_on'),
            'status' => $this->chargeString($assessment, 'status'),
            'metadata' => $this->chargeMetadata($assessment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function insurancePremiumPaymentPayload(object $payment): array
    {
        return [
            'public_id' => $this->chargeString($payment, 'public_id'),
            'amount_minor' => $this->chargeInt($payment, 'amount_minor'),
            'currency' => $this->chargeString($payment, 'currency'),
            'payment_method' => $this->chargeNullableString($payment, 'payment_method'),
            'paid_at' => $this->chargeNullableString($payment, 'paid_at'),
            'status' => $this->chargeString($payment, 'status'),
        ];
    }

    private function setupChargeCreditLedgerId(string $chargeType, int $agencyId, string $currency): int
    {
        $operationCode = match ($chargeType) {
            'dossier_fee' => 'loan_setup_dossier_fee',
            'dossier_fee_tax' => 'loan_setup_tax',
            'guarantee_deposit' => 'loan_setup_guarantee_deposit',
            default => throw new InvalidArgumentException('Unsupported setup charge type: '.$chargeType.'.'),
        };

        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'loan')
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

    private function insurancePremiumCreditLedgerId(int $agencyId, string $currency): int
    {
        $operationCode = 'loan_insurance_premium';
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'loan')
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

    /**
     * @param  array<string, mixed>  $result
     */
    private function loanResult(array $result): Loan
    {
        $loan = $result['loan'] ?? null;
        if (! $loan instanceof Loan) {
            throw new InvalidArgumentException('Loan result is missing.');
        }

        return $loan;
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

    private function chargeString(object $charge, string $key): string
    {
        $value = ((array) $charge)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function chargeNullableString(object $charge, string $key): ?string
    {
        $value = ((array) $charge)[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function chargeInt(object $charge, string $key): int
    {
        return (int) (((array) $charge)[$key] ?? 0);
    }

    private function chargeNullableInt(object $charge, string $key): ?int
    {
        $value = ((array) $charge)[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }
}
