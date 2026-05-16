<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Loans\AdvanceLoanApproval;
use App\Application\Loans\AssessLoanArrearsAndPenalties;
use App\Application\Loans\AssessLoanSetupCharges;
use App\Application\Loans\DisburseLoan;
use App\Application\Loans\EarlyRepayLoan;
use App\Application\Loans\GenerateLoanSchedule;
use App\Application\Loans\RecordLoanRepayment;
use App\Application\Loans\RescheduleLoan;
use App\Application\Loans\TransitionLoanStatus;
use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreLoanRequest;
use App\Http\Requests\UpdateLoanRequest;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\LoanResource;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanApproval;
use App\Models\LoanDisbursement;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanScheduleSnapshot;
use App\Models\LoanStatusTransition;
use App\Models\Sector;
use App\Models\SubSector;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\Finance\FormulaPolicyNotApproved;
use App\Support\Finance\LoanProductFormulaPolicySnapshotter;
use App\Support\Finance\PhysicalCashAmount;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class LoanController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly LoanProductFormulaPolicySnapshotter $formulaPolicySnapshotter,
        private readonly AssessLoanSetupCharges $assessLoanSetupCharges,
        private readonly AssessLoanArrearsAndPenalties $assessLoanArrearsAndPenalties,
        private readonly AdvanceLoanApproval $advanceLoanApproval,
        private readonly TransitionLoanStatus $transitionLoanStatus,
        private readonly GenerateLoanSchedule $generateLoanSchedule,
        private readonly RescheduleLoan $rescheduleLoan,
        private readonly DisburseLoan $disburseLoan,
        private readonly RecordLoanRepayment $recordLoanRepayment,
        private readonly EarlyRepayLoan $earlyRepayLoan,
        private readonly AccountingBalanceCalculator $balanceCalculator,
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

        return $this->respondSuccess(LoanResource::make($loan->loadMissing($this->relations())));
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
                    'posted_at' => now(),
                    'agency_id' => $loan->agency_id,
                    'source_module' => 'credit_loans',
                    'source_type' => 'loan_setup_charge_collection',
                    'source_public_id' => $chargePublicId,
                    'status' => JournalEntry::STATUS_POSTED,
                    'description' => is_string($validated['notes'] ?? null) ? $validated['notes'] : 'Loan setup charge collection '.$loan->loan_number,
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => $actor->id,
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
                    'posted_at' => now(),
                    'agency_id' => $loan->agency_id,
                    'source_module' => 'credit_loans',
                    'source_type' => 'loan_insurance_premium_payment',
                    'source_public_id' => $premiumPublicId,
                    'status' => JournalEntry::STATUS_POSTED,
                    'description' => is_string($validated['notes'] ?? null) ? $validated['notes'] : 'Loan insurance premium collection '.$loan->loan_number,
                    'created_by_user_id' => $actor->id,
                    'posted_by_user_id' => $actor->id,
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

    public function decideApproval(Request $request, Loan $loan, string $step): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.approvals.'.$step)) {
            return $this->respondForbidden('Loan approval step is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'decision' => ['required', Rule::in([LoanApproval::DECISION_APPROVED, LoanApproval::DECISION_REJECTED, LoanApproval::DECISION_RETURNED])],
            'comments' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = $this->advanceLoanApproval->handle(
                $loan,
                $actor,
                $step,
                (string) $validated['decision'],
                is_string($validated['comments'] ?? null) ? $validated['comments'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['approval' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.approval.decided', actor: $actor, subject: $loan, properties: [
            'step' => $step,
            'decision' => $validated['decision'],
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing($this->relations())),
            'approval' => $this->approvalPayload($result['approval']->loadMissing('actedBy')),
        ], 'Loan approval decision recorded successfully');
    }

    public function transitionStatus(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.status.transition')) {
            return $this->respondForbidden('Loan status transition is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'to_status' => ['required', Rule::in(array_keys(TransitionLoanStatus::allowedTransitions()))],
            'reason' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $transition = $this->transitionLoanStatus->handle(
                $loan,
                $actor,
                (string) $validated['to_status'],
                is_string($validated['reason'] ?? null) ? $validated['reason'] : null,
                is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['status' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.status.transitioned', actor: $actor, subject: $loan, properties: [
            'from_status' => $transition->from_status,
            'to_status' => $transition->to_status,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($loan->refresh()->loadMissing($this->relations())),
            'transition' => $this->transitionPayload($transition),
        ], 'Loan status transitioned successfully');
    }

    public function generateSchedule(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.schedules.generate')) {
            return $this->respondForbidden('Loan schedule generation is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        try {
            $snapshot = $this->generateLoanSchedule->handle($loan, $actor);
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['formula_policy' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['schedule' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.schedule.generated', actor: $actor, subject: $loan, properties: [
            'schedule_snapshot_public_id' => $snapshot->public_id,
            'policy_snapshot_hash' => $snapshot->policy_snapshot_hash,
        ], request: $request);

        return $this->respondSuccess([
            'snapshot' => $this->schedulePayload($snapshot),
        ], 'Loan schedule generated successfully');
    }

    public function reschedule(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.schedules.reschedule')) {
            return $this->respondForbidden('Loan rescheduling is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'first_installment_date' => ['required', 'date'],
            'number_of_installments' => ['required', 'integer', 'min:1'],
            'grace_period_duration' => ['nullable', 'integer', 'min:0'],
            'total_loan_duration' => ['nullable', 'integer', 'min:1'],
            'capitalized_interest_minor' => ['nullable', 'integer', 'min:0'],
            'capitalized_penalties_minor' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $snapshot = $this->rescheduleLoan->handle($loan, $actor, $validated);
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['formula_policy' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['reschedule' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.schedule.rescheduled', actor: $actor, subject: $loan, properties: [
            'schedule_snapshot_public_id' => $snapshot->public_id,
            'policy_snapshot_hash' => $snapshot->policy_snapshot_hash,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($loan->refresh()->loadMissing($this->relations())),
            'snapshot' => $this->schedulePayload($snapshot),
        ], 'Loan schedule rescheduled successfully');
    }

    public function disburse(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.disburse')) {
            return $this->respondForbidden('Loan disbursement is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'disbursement_channel' => ['sometimes', Rule::in([LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT, LoanDisbursement::CHANNEL_CASH])],
            'transfer_account_public_id' => ['sometimes', 'nullable', 'string', 'exists:customer_accounts,public_id'],
            'teller_session_public_id' => ['sometimes', 'nullable', 'string', 'exists:teller_sessions,public_id'],
            'business_date' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = $this->disburseLoan->handle(
                $loan,
                $actor,
                $this->stringValue($validated['disbursement_channel'] ?? LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT, LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT),
                is_string($validated['transfer_account_public_id'] ?? null) ? $validated['transfer_account_public_id'] : null,
                is_string($validated['business_date'] ?? null) ? $validated['business_date'] : null,
                is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
                is_string($validated['teller_session_public_id'] ?? null) ? $validated['teller_session_public_id'] : null,
            );
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['disbursement' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.disbursement.posted', actor: $actor, subject: $loan, properties: [
            'disbursement_public_id' => $result['disbursement']->public_id,
            'journal_entry_public_id' => $result['journal_entry']->public_id,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing($this->relations())),
            'disbursement' => $this->disbursementPayload($result['disbursement']->loadMissing(['transferAccount', 'postedBy'])),
            'journal_entry' => JournalEntryResource::make($result['journal_entry']->loadMissing(['agency', 'lines.ledgerAccount', 'lines.customerAccount'])),
        ], 'Loan disbursement posted successfully');
    }

    public function repay(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.repayments.create')) {
            return $this->respondForbidden('Loan repayment is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'customer_account_public_id' => ['required', 'string', 'exists:customer_accounts,public_id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        try {
            $result = $this->recordLoanRepayment->handle(
                $loan,
                $actor,
                $this->intValue($validated['amount_minor'] ?? 0),
                $this->stringValue($validated['customer_account_public_id'] ?? null, ''),
                is_string($validated['paid_on'] ?? null) ? $validated['paid_on'] : null,
                is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
            );
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['repayment_allocation_order' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['repayment' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.repayment.posted', actor: $actor, subject: $loan, properties: [
            'repayment_public_id' => $result['repayment']->public_id,
            'journal_entry_public_id' => $result['journal_entry']->public_id,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing($this->relations())),
            'repayment' => $this->repaymentPayload($result['repayment']),
            'journal_entry' => JournalEntryResource::make($result['journal_entry']->loadMissing(['agency', 'lines.ledgerAccount', 'lines.customerAccount'])),
        ], 'Loan repayment posted successfully');
    }

    public function assessArrears(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.arrears.assess')) {
            return $this->respondForbidden('Loan arrears assessment is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'as_of_date' => ['sometimes', 'nullable', 'date'],
        ])->validate();

        try {
            $result = $this->assessLoanArrearsAndPenalties->handle(
                $loan,
                is_string($validated['as_of_date'] ?? null) ? $validated['as_of_date'] : now()->toDateString(),
            );
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['penalties_and_arrears' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['arrears' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.arrears.assessed', actor: $actor, subject: $loan, properties: [
            'assessed_penalty_minor' => $result['assessed_penalty_minor'],
            'arrears_count' => count($result['arrears']),
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($result['loan']->loadMissing($this->relations())),
            'assessed_penalty_minor' => $result['assessed_penalty_minor'],
            'arrears' => $result['arrears'],
        ], 'Loan arrears assessed successfully');
    }

    public function earlyRepay(Request $request, Loan $loan): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('update', $loan)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin') && ! $actor->can('loans.early-repayments.create')) {
            return $this->respondForbidden('Loan early repayment is outside your permission set.');
        }

        if (! $this->canAccessLoanAgency($actor, $loan)) {
            return $this->respondForbidden('Loan is outside your agency scope.');
        }

        $validated = Validator::make($request->all(), [
            'customer_account_public_id' => ['required', 'string', 'exists:customer_accounts,public_id'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'paid_on' => ['sometimes', 'nullable', 'date'],
            'direction_interest_waiver' => ['sometimes', 'boolean'],
            'direction_negotiated_total_interest_minor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ])->validate();

        $directionInterestWaiver = ($validated['direction_interest_waiver'] ?? false) === true;
        $directionNegotiatedTotalInterest = array_key_exists('direction_negotiated_total_interest_minor', $validated)
            ? $this->intValue($validated['direction_negotiated_total_interest_minor'])
            : null;
        if ($directionInterestWaiver && $directionNegotiatedTotalInterest !== null) {
            return $this->respondUnprocessable(errors: ['early_repayment' => ['Use either a full future-interest waiver or a negotiated total interest amount, not both.']]);
        }
        if (($directionInterestWaiver || $directionNegotiatedTotalInterest !== null) && ! $actor->hasRole('platform-admin') && ! $actor->can('loans.approvals.direction')) {
            return $this->respondForbidden('Direction approval is required to waive future interest.');
        }

        try {
            $result = $this->earlyRepayLoan->handle(
                $loan,
                $actor,
                $this->stringValue($validated['customer_account_public_id'] ?? null, ''),
                $this->intValue($validated['amount_minor'] ?? 0),
                is_string($validated['paid_on'] ?? null) ? $validated['paid_on'] : null,
                $directionInterestWaiver,
                $directionNegotiatedTotalInterest,
                is_string($validated['notes'] ?? null) ? $validated['notes'] : null,
            );
        } catch (FormulaPolicyNotApproved $exception) {
            return $this->respondUnprocessable(errors: ['repayment_allocation_order' => [$exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            return $this->respondUnprocessable(errors: ['early_repayment' => [$exception->getMessage()]]);
        }

        $this->securityAudit->record('loan.early_repayment.posted', actor: $actor, subject: $loan, properties: [
            'repayment_public_id' => $this->repaymentResult($result)->public_id,
            'journal_entry_public_id' => $this->journalEntryResult($result)->public_id,
            'direction_interest_waiver' => $directionInterestWaiver,
        ], request: $request);

        return $this->respondSuccess([
            'loan' => LoanResource::make($this->loanResult($result)->loadMissing($this->relations())),
            'repayment' => $this->repaymentPayload($this->repaymentResult($result)),
            'journal_entry' => JournalEntryResource::make($this->journalEntryResult($result)->loadMissing(['agency', 'lines.ledgerAccount', 'lines.customerAccount'])),
            'payoff_amount_minor' => $result['payoff_amount_minor'],
            'direction_interest_waiver' => $result['direction_interest_waiver'],
            'direction_negotiated_total_interest_minor' => $result['direction_negotiated_total_interest_minor'],
            'interest_concession_minor' => $result['interest_concession_minor'],
            'early_repayment_fee_minor' => $result['early_repayment_fee_minor'],
            'insurance_refunded_minor' => $result['insurance_refunded_minor'],
            'released_guarantee_obligations_count' => $result['released_guarantee_obligations_count'],
        ], 'Loan early repayment posted successfully');
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

        return [
            'ledger' => $tillLedger,
            'customer_account' => null,
            'teller_session' => $session,
            'till' => $till,
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function approvalPayload(LoanApproval $approval): array
    {
        return [
            'public_id' => $approval->public_id,
            'step' => $approval->step,
            'decision' => $approval->decision,
            'acted_by_user_public_id' => $approval->actedBy?->public_id,
            'acted_at' => $this->formatDate($approval->acted_at),
            'comments' => $approval->comments,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transitionPayload(LoanStatusTransition $transition): array
    {
        return [
            'public_id' => $transition->public_id,
            'from_status' => $transition->from_status,
            'to_status' => $transition->to_status,
            'decision' => $transition->decision,
            'reason' => $transition->reason,
            'notes' => $transition->notes,
            'transitioned_at' => $this->formatDate($transition->transitioned_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function disbursementPayload(LoanDisbursement $disbursement): array
    {
        return [
            'public_id' => $disbursement->public_id,
            'disbursement_channel' => $disbursement->disbursement_channel,
            'principal_amount_minor' => $disbursement->principal_amount_minor,
            'currency' => $disbursement->currency,
            'status' => $disbursement->status,
            'posted_at' => $this->formatDate($disbursement->posted_at),
            'posted_by_user_public_id' => $disbursement->postedBy?->public_id,
            'transfer_account_public_id' => $disbursement->transferAccount?->public_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function repaymentPayload(LoanRepayment $repayment): array
    {
        $repayment->loadMissing(['allocations.scheduleLine', 'customerAccount', 'postedBy']);

        return [
            'public_id' => $repayment->public_id,
            'customer_account_public_id' => $repayment->customerAccount?->public_id,
            'received_amount_minor' => $repayment->received_amount_minor,
            'allocated_amount_minor' => $repayment->allocated_amount_minor,
            'overpayment_retained_minor' => $repayment->overpayment_retained_minor,
            'currency' => $repayment->currency,
            'paid_on' => $this->formatDateOnly($repayment->paid_on),
            'status' => $repayment->status,
            'posted_at' => $this->formatDate($repayment->posted_at),
            'posted_by_user_public_id' => $repayment->postedBy?->public_id,
            'allocations' => $repayment->allocations->map(fn ($allocation): array => [
                'installment_number' => $allocation->scheduleLine?->installment_number,
                'component' => $allocation->component,
                'amount_minor' => $allocation->amount_minor,
                'currency' => $allocation->currency,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePayload(LoanScheduleSnapshot $snapshot): array
    {
        $snapshot->loadMissing(['loan', 'lines']);

        $lines = [];
        foreach ($snapshot->lines->sortBy('installment_number')->values() as $line) {
            $lines[] = [
                'installment_number' => $line->installment_number,
                'due_date' => $this->formatDateOnly($line->due_date),
                'principal_minor' => $line->principal_minor,
                'interest_minor' => $line->interest_minor,
                'fees_minor' => $line->fees_minor,
                'insurance_minor' => $line->insurance_minor,
                'tax_minor' => $line->tax_minor,
                'penalty_minor' => $line->penalty_minor,
                'capitalized_interest_minor' => $line->capitalized_interest_minor,
                'remaining_principal_minor' => $line->remaining_principal_minor,
                'total_installment_minor' => $line->total_installment_minor,
                'currency' => $line->currency,
                'status' => $line->status,
            ];
        }

        return [
            'public_id' => $snapshot->public_id,
            'loan_public_id' => $snapshot->loan?->public_id,
            'formula_engine_key' => $snapshot->formula_engine_key,
            'formula_engine_version' => $snapshot->formula_engine_version,
            'policy_snapshot_hash' => $snapshot->policy_snapshot_hash,
            'generated_at' => $this->formatDate($snapshot->generated_at),
            'status' => $snapshot->status,
            'lines' => $lines,
        ];
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
     * @param  array<string, mixed>  $result
     */
    private function repaymentResult(array $result): LoanRepayment
    {
        $repayment = $result['repayment'] ?? null;
        if (! $repayment instanceof LoanRepayment) {
            throw new InvalidArgumentException('Repayment result is missing.');
        }

        return $repayment;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function journalEntryResult(array $result): JournalEntry
    {
        $journalEntry = $result['journal_entry'] ?? null;
        if (! $journalEntry instanceof JournalEntry) {
            throw new InvalidArgumentException('Journal entry result is missing.');
        }

        return $journalEntry;
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

    private function stringValue(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function formatDateOnly(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
