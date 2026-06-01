<?php

declare(strict_types=1);

namespace App\Application\CashOperations;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreCashManualJournalRequest;
use App\Http\Resources\JournalEntryResource;
use App\Http\Resources\TellerTransactionResource;
use App\Models\AccountingDay;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\OperationCode;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Finance\PhysicalCashAmount;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TellerManualJournalWorkflow extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function storeManualJournal(StoreCashManualJournalRequest $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $this->respondForbidden();
        }

        if (! $this->canUseSession($actor, $tellerSession)) {
            return $this->respondForbidden('Manual cash journal can only be created in your open teller session scope.');
        }

        $tellerSession->loadMissing(['till']);
        $till = $tellerSession->till;
        if (! $till instanceof Till || $till->ledger_account_id === null) {
            return $this->respondUnprocessable(errors: ['till' => ['The teller session till must have a cash ledger account before creating manual cash journals.']]);
        }

        if ($tellerSession->status !== TellerSession::STATUS_OPEN || $till->daily_state !== Till::DAILY_STATE_OPEN) {
            return $this->respondUnprocessable(errors: ['teller_session' => ['Manual cash journals require an open teller session and open till.']]);
        }

        $accountingDay = $this->resolveSessionAccountingDay($tellerSession, $actor, 'cash.manual_journal', $request);

        $currency = $this->normalizedCurrency($request->input('currency', $tellerSession->currency ?? $till->currency));
        if ($currency !== $tellerSession->currency) {
            return $this->respondUnprocessable(errors: ['currency' => ['Manual cash journal currency must match the teller session currency.']]);
        }

        $operationCode = $this->resolveCashOperationCode($request->input('operation_code_public_id'));
        if ($operationCode === false) {
            return $this->respondUnprocessable(errors: ['operation_code_public_id' => ['The selected operation code must be active and belong to the cash module.']]);
        }

        $prepared = $this->prepareManualJournalLines($request->input('lines'), $tellerSession->agency_id, $currency, $till->ledger_account_id);
        if ($prepared['errors'] !== []) {
            return $this->respondUnprocessable(errors: $prepared['errors']);
        }

        $idempotencyKey = $this->idempotencyKey($request->header('Idempotency-Key'), $request->input('idempotency_key'));
        if ($idempotencyKey !== null) {
            $existing = TellerTransaction::query()
                ->with(['tellerSession', 'till', 'customerAccount', 'journalEntry.lines'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof TellerTransaction) {
                return $this->transactionResponse($existing, 'Manual cash journal already created successfully');
            }
        }

        $result = DB::transaction(function () use ($request, $actor, $tellerSession, $till, $currency, $operationCode, $prepared, $idempotencyKey, $accountingDay): TellerTransaction {
            $reference = is_string($request->input('reference')) && $request->input('reference') !== ''
                ? $request->string('reference')->toString()
                : 'OD-'.Str::upper(Str::random(10));

            $journalEntry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => 'JE-'.$reference,
                'business_date' => $tellerSession->business_date,
                'accounting_day_id' => $accountingDay->id,
                'posted_at' => null,
                'agency_id' => $tellerSession->agency_id,
                'source_module' => 'cash_operations',
                'source_type' => TellerTransaction::TYPE_MANUAL_JOURNAL,
                'source_public_id' => $tellerSession->public_id,
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => $request->input('description', 'Manual cash journal '.$reference),
                'created_by_user_id' => $actor->id,
                'submitted_by_user_id' => null,
                'submitted_at' => null,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($prepared['lines'] as $line) {
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $tellerSession->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $line['ledger_account_id'],
                    'customer_account_id' => $line['customer_account_id'],
                    'debit_minor' => $line['debit_minor'],
                    'credit_minor' => $line['credit_minor'],
                    'currency' => $currency,
                    'line_memo' => $line['memo'],
                ]);
            }

            $transaction = TellerTransaction::query()->create([
                'public_id' => (string) Str::ulid(),
                'teller_session_id' => $tellerSession->id,
                'accounting_day_id' => $accountingDay->id,
                'agency_id' => $tellerSession->agency_id,
                'transaction_date' => $tellerSession->business_date,
                'till_id' => $till->id,
                'transaction_type' => TellerTransaction::TYPE_MANUAL_JOURNAL,
                'amount_minor' => $prepared['debit_total_minor'],
                'currency' => $currency,
                'status' => TellerTransaction::STATUS_PENDING_REVIEW,
                'reference' => $reference,
                'event_number' => $reference,
                'idempotency_key' => $idempotencyKey,
                'journal_entry_id' => $journalEntry->id,
                'operation_code_id' => $operationCode instanceof OperationCode ? $operationCode->id : null,
                'operation_code' => $operationCode instanceof OperationCode ? $operationCode->code : null,
                'description' => $request->input('description'),
            ]);

            $journalEntry->forceFill([
                'source_public_id' => $transaction->public_id,
                'status' => JournalEntry::STATUS_SUBMITTED,
                'submitted_by_user_id' => $actor->id,
                'submitted_at' => now(),
            ])->save();

            return $transaction->refresh();
        });

        $this->securityAudit->record('cash.manual_journal.submitted', actor: $actor, subject: $result, properties: [
            'teller_session_public_id' => $tellerSession->public_id,
            'amount_minor' => $prepared['debit_total_minor'],
            'currency' => $currency,
        ], request: $request);

        return $this->transactionResponse($result, 'Manual cash journal submitted for approval successfully');
    }

    private function resolveSessionAccountingDay(TellerSession $session, User $actor, string $operation, Request $request): AccountingDay
    {
        if ($session->accounting_day_id !== null) {
            $session->loadMissing('accountingDay');
            $day = $session->accountingDay;
            if ($day instanceof AccountingDay) {
                return $this->accountingDayGuard->assertDayAllowsRegistration($day, $actor, $operation, $request);
            }
        }

        return $this->accountingDayGuard->assertCanRegister($actor, $operation, $session->agency_id, $request);
    }

    private function canUseSession(User $actor, TellerSession $session): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        if ($this->staffAgencyScope->currentAgencyId($actor) !== $session->agency_id) {
            return false;
        }

        return ! $actor->hasRole('teller') || $actor->id === $session->teller_user_id;
    }

    private function resolveCashOperationCode(mixed $publicId): OperationCode|false|null
    {
        if ($publicId === null || $publicId === '') {
            return null;
        }

        if (! is_string($publicId)) {
            return false;
        }

        $operationCode = OperationCode::query()->where('public_id', $publicId)->first();
        if (! $operationCode instanceof OperationCode
            || $operationCode->status !== OperationCode::STATUS_ACTIVE
            || $operationCode->module !== 'cash') {
            return false;
        }

        return $operationCode;
    }

    /**
     * @return array{errors: array<string, array<int, string>>, lines: array<int, array{ledger_account_id:int, customer_account_id:int|null, debit_minor:int, credit_minor:int, memo:string|null}>, debit_total_minor:int}
     */
    private function prepareManualJournalLines(mixed $rawLines, int $agencyId, string $currency, int $tillLedgerAccountId): array
    {
        if (! is_array($rawLines)) {
            return ['errors' => ['lines' => ['Manual journal lines are required.']], 'lines' => [], 'debit_total_minor' => 0];
        }

        $lines = [];
        $debitTotal = 0;
        $creditTotal = 0;
        $hasTillLine = false;
        foreach ($rawLines as $index => $rawLine) {
            if (! is_array($rawLine)) {
                return ['errors' => ['lines.'.$index => ['Each manual journal line must be an object.']], 'lines' => [], 'debit_total_minor' => 0];
            }

            $ledgerPublicId = $rawLine['ledger_account_public_id'] ?? null;
            $direction = $rawLine['direction'] ?? null;
            $amount = $rawLine['amount_minor'] ?? null;
            if (! is_string($ledgerPublicId) || ! is_string($direction) || ! is_int($amount)) {
                return ['errors' => ['lines.'.$index => ['Each manual journal line must include a ledger account, direction, and integer amount.']], 'lines' => [], 'debit_total_minor' => 0];
            }

            $ledgerAccount = LedgerAccount::query()->where('public_id', $ledgerPublicId)->first();
            if (! $ledgerAccount instanceof LedgerAccount || ! $this->ledgerIsActiveInAgency($ledgerAccount, $agencyId)) {
                return ['errors' => ['lines.'.$index.'.ledger_account_public_id' => ['The selected ledger account must be active and match the teller session agency.']], 'lines' => [], 'debit_total_minor' => 0];
            }

            $customerAccountId = null;
            $customerPublicId = $rawLine['customer_account_public_id'] ?? null;
            if ($customerPublicId !== null && $customerPublicId !== '') {
                if (! is_string($customerPublicId)) {
                    return ['errors' => ['lines.'.$index.'.customer_account_public_id' => ['The selected customer account is invalid.']], 'lines' => [], 'debit_total_minor' => 0];
                }

                $customerAccount = CustomerAccount::query()->where('public_id', $customerPublicId)->first();
                if (! $customerAccount instanceof CustomerAccount
                    || $customerAccount->agency_id !== $agencyId
                    || $customerAccount->currency !== $currency
                    || $customerAccount->ledger_account_id !== $ledgerAccount->id) {
                    return ['errors' => ['lines.'.$index.'.customer_account_public_id' => ['The selected customer account must match the agency, currency, and ledger account.']], 'lines' => [], 'debit_total_minor' => 0];
                }

                $customerAccountId = $customerAccount->id;
            }

            $debit = $direction === 'debit' ? $amount : 0;
            $credit = $direction === 'credit' ? $amount : 0;
            $debitTotal += $debit;
            $creditTotal += $credit;
            if ($ledgerAccount->id === $tillLedgerAccountId) {
                if (! PhysicalCashAmount::validMinorAmount($amount, $currency)) {
                    return ['errors' => ['lines.'.$index.'.amount_minor' => [PhysicalCashAmount::validationMessage($currency)]], 'lines' => [], 'debit_total_minor' => 0];
                }

                $hasTillLine = true;
            }
            $memo = $rawLine['memo'] ?? null;

            $lines[] = [
                'ledger_account_id' => $ledgerAccount->id,
                'customer_account_id' => $customerAccountId,
                'debit_minor' => $debit,
                'credit_minor' => $credit,
                'memo' => is_string($memo) ? $memo : null,
            ];
        }

        if ($debitTotal !== $creditTotal) {
            return ['errors' => ['lines' => ['Manual cash journal lines must balance: total debit must equal total credit.']], 'lines' => [], 'debit_total_minor' => 0];
        }

        if (! $hasTillLine) {
            return ['errors' => ['lines' => ['Manual cash journal must include the teller session till ledger account.']], 'lines' => [], 'debit_total_minor' => 0];
        }

        return ['errors' => [], 'lines' => $lines, 'debit_total_minor' => $debitTotal];
    }

    private function ledgerIsActiveInAgency(LedgerAccount $ledgerAccount, int $agencyId): bool
    {
        return $ledgerAccount->status === LedgerAccount::STATUS_ACTIVE && $ledgerAccount->agency_id === $agencyId;
    }

    private function normalizedCurrency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'XAF';
    }

    private function idempotencyKey(mixed $headerValue, mixed $bodyValue): ?string
    {
        if (is_string($headerValue) && $headerValue !== '') {
            return $headerValue;
        }

        return is_string($bodyValue) && $bodyValue !== '' ? $bodyValue : null;
    }

    private function transactionResponse(TellerTransaction $transaction, string $message): JsonResponse
    {
        $transaction->loadMissing(['tellerSession', 'till', 'customerAccount', 'initiatorProxy', 'customerAccountSignature', 'signatureCheckedBy', 'journalEntry.lines.ledgerAccount', 'journalEntry.lines.customerAccount']);
        $journalEntry = $transaction->journalEntry;

        return $this->respondCreated([
            'teller_transaction' => TellerTransactionResource::make($transaction),
            'journal_entry' => $journalEntry instanceof JournalEntry ? JournalEntryResource::make($journalEntry) : null,
        ], $message);
    }
}
