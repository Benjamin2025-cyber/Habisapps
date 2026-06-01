<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanProduct;
use App\Models\LoanStatusTransition;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Finance\PhysicalCashAmount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DisburseLoan
{
    public function __construct(
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    /**
     * @return array{loan: Loan, disbursement: LoanDisbursement, journal_entry: JournalEntry}
     */
    public function handle(Loan $loan, User $actor, string $channel, ?string $transferAccountPublicId = null, ?string $businessDate = null, ?string $notes = null, ?string $tellerSessionPublicId = null): array
    {
        if (! in_array($channel, [LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT, LoanDisbursement::CHANNEL_CASH], true)) {
            throw new InvalidArgumentException('Unsupported disbursement channel.');
        }

        return DB::transaction(function () use ($actor, $businessDate, $channel, $loan, $notes, $tellerSessionPublicId, $transferAccountPublicId): array {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();

            $lockedLoan = Loan::query()
                ->with(['loanProduct', 'transferAccount'])
                ->whereKey($loan->id)
                ->firstOrFail();

            $existing = LoanDisbursement::query()
                ->with(['journalEntry.lines.ledgerAccount', 'journalEntry.lines.customerAccount', 'transferAccount'])
                ->where('loan_id', $lockedLoan->id)
                ->first();
            if ($existing instanceof LoanDisbursement) {
                $journalEntry = $existing->journalEntry;
                if (! $journalEntry instanceof JournalEntry) {
                    throw new InvalidArgumentException('Existing disbursement is missing its journal entry.');
                }

                $this->ensureReplayMatches($existing, $lockedLoan, $channel, $transferAccountPublicId, $tellerSessionPublicId, $businessDate);

                return [
                    'loan' => $lockedLoan->refresh(),
                    'disbursement' => $existing,
                    'journal_entry' => $journalEntry,
                ];
            }

            if ($lockedLoan->status !== Loan::STATUS_APPROVED) {
                throw new InvalidArgumentException('Only approved loans can be disbursed.');
            }

            $product = $lockedLoan->loanProduct;
            if (! $product instanceof LoanProduct || $product->ledger_account_id === null) {
                throw new InvalidArgumentException('Loan product ledger mapping is required before disbursement.');
            }

            $loanLedger = LedgerAccount::query()->whereKey($product->ledger_account_id)->first();
            if (! $loanLedger instanceof LedgerAccount || $loanLedger->status !== LedgerAccount::STATUS_ACTIVE || $loanLedger->agency_id !== $lockedLoan->agency_id) {
                throw new InvalidArgumentException('Loan product ledger account must be active and belong to the loan agency.');
            }

            $this->ensureSetupSatisfied($lockedLoan, $product);

            $principal = $lockedLoan->approved_principal_minor ?? $lockedLoan->requested_amount_minor;
            if ($principal <= 0) {
                throw new InvalidArgumentException('Disbursement principal must be positive.');
            }

            $transferAccount = null;
            $creditLedger = null;
            $cashContext = null;
            if ($channel === LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT) {
                $transferAccount = $this->resolveTransferAccount($lockedLoan, $transferAccountPublicId);
                if ($transferAccount->ledger_account_id === null) {
                    throw new InvalidArgumentException('Transfer account ledger mapping is required before disbursement.');
                }

                $creditLedger = LedgerAccount::query()->whereKey($transferAccount->ledger_account_id)->first();
                if (! $creditLedger instanceof LedgerAccount || $creditLedger->status !== LedgerAccount::STATUS_ACTIVE || $creditLedger->agency_id !== $lockedLoan->agency_id) {
                    throw new InvalidArgumentException('Transfer account ledger account must be active and belong to the loan agency.');
                }

                if ($transferAccount->currency !== $lockedLoan->currency) {
                    throw new InvalidArgumentException('Transfer account currency must match the loan currency.');
                }
            } else {
                $cashContext = $this->resolveCashContext($lockedLoan, $tellerSessionPublicId, $principal);
                $creditLedger = $cashContext['till_ledger'];
            }

            $reference = 'LD-'.$lockedLoan->loan_number;
            $idempotencyKey = 'loan-disbursement:'.$lockedLoan->public_id;
            $postedAt = now();
            $accountingDay = $this->accountingDayGuard->resolveAccountingDay($actor, 'loan.disburse', $lockedLoan->agency_id, $businessDate);
            $effectiveBusinessDate = (string) $accountingDay->business_date?->toDateString();
            $journalEntry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => $reference,
                'business_date' => $effectiveBusinessDate,
                'accounting_day_id' => $accountingDay->id,
                'posted_at' => null,
                'agency_id' => $lockedLoan->agency_id,
                'source_module' => 'credit_loans',
                'source_type' => 'loan_disbursement',
                'source_public_id' => $lockedLoan->public_id,
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => $notes ?? 'Loan disbursement '.$lockedLoan->loan_number,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => null,
                'idempotency_key' => $idempotencyKey,
            ]);

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $lockedLoan->agency_id,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $loanLedger->id,
                'customer_account_id' => null,
                'loan_id' => $lockedLoan->id,
                'debit_minor' => $principal,
                'credit_minor' => 0,
                'currency' => $lockedLoan->currency,
                'line_memo' => 'Loan principal disbursed',
            ]);

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $lockedLoan->agency_id,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $creditLedger->id,
                'customer_account_id' => $transferAccount?->id,
                'loan_id' => $lockedLoan->id,
                'debit_minor' => 0,
                'credit_minor' => $principal,
                'currency' => $lockedLoan->currency,
                'line_memo' => $channel === LoanDisbursement::CHANNEL_CASH ? 'Loan proceeds paid out in cash' : 'Loan proceeds credited to transfer account',
            ]);

            $disbursement = LoanDisbursement::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $lockedLoan->agency_id,
                'loan_id' => $lockedLoan->id,
                'journal_entry_id' => $journalEntry->id,
                'transfer_account_id' => $transferAccount?->id,
                'disbursement_channel' => $channel,
                'principal_amount_minor' => $principal,
                'currency' => $lockedLoan->currency,
                'status' => LoanDisbursement::STATUS_POSTED,
                'posted_at' => $postedAt,
                'posted_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'notes' => $notes,
                    'loan_product_public_id' => $product->public_id,
                    'transfer_account_public_id' => $transferAccount?->public_id,
                    'teller_session_public_id' => $cashContext['session']->public_id ?? null,
                    'till_public_id' => $cashContext['till']->public_id ?? null,
                ],
            ]);

            if ($cashContext !== null) {
                $tellerTransaction = TellerTransaction::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'teller_session_id' => $cashContext['session']->id,
                    'agency_id' => $lockedLoan->agency_id,
                    'transaction_date' => $effectiveBusinessDate,
                    'till_id' => $cashContext['till']->id,
                    'transaction_type' => TellerTransaction::TYPE_CASH_WITHDRAWAL,
                    'client_id' => $lockedLoan->client_id,
                    'customer_account_id' => null,
                    'loan_id' => $lockedLoan->id,
                    'amount_minor' => $principal,
                    'currency' => $lockedLoan->currency,
                    'status' => TellerTransaction::STATUS_POSTED,
                    'reference' => 'TT-LD-'.$lockedLoan->loan_number,
                    'event_number' => 'EVT-LD-'.$lockedLoan->loan_number,
                    'idempotency_key' => 'teller-loan-disbursement:'.$lockedLoan->public_id,
                    'journal_entry_id' => $journalEntry->id,
                    'offset_ledger_account_id' => $loanLedger->id,
                    'operation_code' => 'loan_cash_disbursement',
                    'description' => $notes ?? 'Cash loan disbursement '.$lockedLoan->loan_number,
                ]);

                $metadata = $disbursement->getAttribute('metadata');
                if (! is_array($metadata)) {
                    $metadata = [];
                }

                $disbursement->forceFill([
                    'metadata' => array_merge($metadata, [
                        'teller_transaction_public_id' => $tellerTransaction->public_id,
                        'teller_session_public_id' => $cashContext['session']->public_id,
                    ]),
                ])->save();
            }

            $this->postSystemJournal($journalEntry, $actor);

            $fromStatus = $lockedLoan->status;
            $lockedLoan->forceFill([
                'status' => Loan::STATUS_DISBURSED,
                'approved_principal_minor' => $principal,
                'disbursed_on' => $effectiveBusinessDate,
            ])->save();

            LoanStatusTransition::query()->create([
                'public_id' => (string) Str::ulid(),
                'loan_id' => $lockedLoan->id,
                'agency_id' => $lockedLoan->agency_id,
                'from_status' => $fromStatus,
                'to_status' => Loan::STATUS_DISBURSED,
                'actor_user_id' => $actor->id,
                'decision' => 'posted',
                'reason' => 'loan_disbursement_posted',
                'notes' => $notes,
                'transitioned_at' => $postedAt,
            ]);

            return [
                'loan' => $lockedLoan->refresh(),
                'disbursement' => $disbursement->refresh(),
                'journal_entry' => $journalEntry->refresh()->loadMissing(['agency', 'lines.ledgerAccount', 'lines.customerAccount']),
            ];
        });
    }

    private function ensureReplayMatches(LoanDisbursement $existing, Loan $loan, string $channel, ?string $transferAccountPublicId, ?string $tellerSessionPublicId, ?string $businessDate): void
    {
        if ($existing->disbursement_channel !== $channel) {
            throw new InvalidArgumentException('Disbursement already posted with a different channel.');
        }

        if ($channel === LoanDisbursement::CHANNEL_TRANSFER_ACCOUNT) {
            $expectedTransferPublicId = is_string($transferAccountPublicId) && $transferAccountPublicId !== ''
                ? $transferAccountPublicId
                : $loan->transferAccount?->public_id;
            $existingTransferPublicId = $existing->transferAccount?->public_id;
            if ($existingTransferPublicId !== $expectedTransferPublicId) {
                throw new InvalidArgumentException('Disbursement already posted to a different transfer account.');
            }
        }

        if ($channel === LoanDisbursement::CHANNEL_CASH) {
            $metadata = $existing->getAttribute('metadata');
            $existingTellerPublicId = is_array($metadata) && isset($metadata['teller_session_public_id']) && is_string($metadata['teller_session_public_id'])
                ? $metadata['teller_session_public_id']
                : null;
            if (! is_string($tellerSessionPublicId) || $tellerSessionPublicId === '' || $existingTellerPublicId !== $tellerSessionPublicId) {
                throw new InvalidArgumentException('Disbursement already posted via a different teller session.');
            }
        }

        if (is_string($businessDate) && $businessDate !== '') {
            $existingBusinessDate = $existing->journalEntry?->getAttribute('business_date');
            $existingBusinessDateString = $existingBusinessDate instanceof \DateTimeInterface
                ? $existingBusinessDate->format('Y-m-d')
                : (is_string($existingBusinessDate) ? substr($existingBusinessDate, 0, 10) : null);
            if ($existingBusinessDateString !== null && $existingBusinessDateString !== $businessDate) {
                throw new InvalidArgumentException('Disbursement already posted with a different business date.');
            }
        }
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

    private function resolveTransferAccount(Loan $loan, ?string $publicId): CustomerAccount
    {
        $account = is_string($publicId) && $publicId !== ''
            ? CustomerAccount::query()->where('public_id', $publicId)->first()
            : $loan->transferAccount;

        if (! $account instanceof CustomerAccount
            || $account->status !== CustomerAccount::STATUS_ACTIVE
            || $account->client_id !== $loan->client_id
            || $account->agency_id !== $loan->agency_id) {
            throw new InvalidArgumentException('Transfer account must be active and belong to the loan client and agency.');
        }

        return $account;
    }

    /**
     * @return array{session:TellerSession, till:Till, till_ledger:LedgerAccount}
     */
    private function resolveCashContext(Loan $loan, ?string $sessionPublicId, int $principal): array
    {
        if (! is_string($sessionPublicId) || $sessionPublicId === '') {
            throw new InvalidArgumentException('Teller session is required for cash disbursement.');
        }

        $session = TellerSession::query()
            ->with('till')
            ->where('public_id', $sessionPublicId)
            ->first();
        if (! $session instanceof TellerSession
            || $session->status !== TellerSession::STATUS_OPEN
            || $session->agency_id !== $loan->agency_id
            || $session->currency !== $loan->currency) {
            throw new InvalidArgumentException('Teller session must be open and belong to the loan agency and currency.');
        }

        $till = $session->till;
        if (! $till instanceof Till
            || $till->status !== Till::STATUS_ACTIVE
            || $till->daily_state !== Till::DAILY_STATE_OPEN
            || $till->agency_id !== $loan->agency_id
            || $till->currency !== $loan->currency
            || $till->ledger_account_id === null) {
            throw new InvalidArgumentException('Open teller till with an active cash ledger is required for cash disbursement.');
        }

        $tillLedger = LedgerAccount::query()->whereKey($till->ledger_account_id)->first();
        if (! $tillLedger instanceof LedgerAccount || $tillLedger->status !== LedgerAccount::STATUS_ACTIVE || $tillLedger->agency_id !== $loan->agency_id) {
            throw new InvalidArgumentException('Till cash ledger account must be active and belong to the loan agency.');
        }

        if ($this->postedLedgerBalance($tillLedger, $loan->currency) < $principal) {
            throw new InvalidArgumentException('Till cash balance is insufficient for cash disbursement.');
        }

        if (! PhysicalCashAmount::validMinorAmount($principal, $loan->currency)) {
            throw new InvalidArgumentException(PhysicalCashAmount::validationMessage($loan->currency));
        }

        return [
            'session' => $session,
            'till' => $till,
            'till_ledger' => $tillLedger,
        ];
    }

    private function postedLedgerBalance(LedgerAccount $ledgerAccount, string $currency): int
    {
        $debits = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $ledgerAccount->id)
            ->where('journal_lines.currency', $currency)
            ->sum('journal_lines.debit_minor');
        $credits = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $ledgerAccount->id)
            ->where('journal_lines.currency', $currency)
            ->sum('journal_lines.credit_minor');

        return (int) $debits - (int) $credits;
    }

    private function ensureSetupSatisfied(Loan $loan, LoanProduct $product): void
    {
        $rules = is_array($product->getAttribute('rules')) ? $product->getAttribute('rules') : [];
        $requiresCharges = $product->fee_amount_minor !== null
            || $product->tax_rate !== null
            || $product->insurance_rate !== null
            || $product->guarantee_deposit_value !== null
            || is_array($rules['setup_charges'] ?? null);

        if (! $requiresCharges) {
            return;
        }

        $chargeAssessments = DB::table('loan_charge_assessments')
            ->where('loan_id', $loan->id)
            ->whereIn('charge_type', ['dossier_fee', 'dossier_fee_tax', 'guarantee_deposit'])
            ->where('assessed_amount_minor', '>', 0)
            ->get(['id', 'charge_type', 'status', 'paid_at']);
        $insuranceAssessments = DB::table('insurance_premium_assessments')
            ->where('loan_id', $loan->id)
            ->where('premium_amount_minor', '>', 0)
            ->get(['id', 'status']);

        if ($chargeAssessments->isEmpty() && $insuranceAssessments->isEmpty()) {
            throw new InvalidArgumentException('Setup charges must be assessed before disbursement.');
        }

        $unpaidCharges = $chargeAssessments
            ->filter(fn (object $assessment): bool => ! $this->loanChargeIsCollected($assessment))
            ->map(fn (object $assessment): string => $this->chargeType($assessment))
            ->values()
            ->all();
        if ($unpaidCharges !== []) {
            throw new InvalidArgumentException('Setup charges must be collected before disbursement: '.implode(', ', $unpaidCharges).'.');
        }

        $unpaidInsuranceCount = $insuranceAssessments
            ->filter(fn (object $assessment): bool => ! $this->insurancePremiumIsCollected($assessment))
            ->count();
        if ($unpaidInsuranceCount > 0) {
            throw new InvalidArgumentException('Loan insurance premium must be collected before disbursement.');
        }
    }

    private function loanChargeIsCollected(object $assessment): bool
    {
        $data = (array) $assessment;
        $status = $data['status'] ?? null;
        $paidAt = $data['paid_at'] ?? null;

        return $status === 'waived_by_direction'
            || (in_array($status, ['paid', 'collected', 'posted'], true) && $paidAt !== null);
    }

    private function chargeType(object $assessment): string
    {
        $data = (array) $assessment;
        $type = $data['charge_type'] ?? 'unknown';

        return is_string($type) && $type !== '' ? $type : 'unknown';
    }

    private function insurancePremiumIsCollected(object $assessment): bool
    {
        $data = (array) $assessment;
        $status = $data['status'] ?? null;
        $assessmentId = $data['id'] ?? null;

        if (in_array($status, ['paid', 'collected', 'posted'], true)) {
            return true;
        }

        if (! is_int($assessmentId)) {
            return false;
        }

        return DB::table('insurance_premium_payments')
            ->where('insurance_premium_assessment_id', $assessmentId)
            ->whereIn('status', ['posted', 'paid', 'collected'])
            ->exists();
    }
}
