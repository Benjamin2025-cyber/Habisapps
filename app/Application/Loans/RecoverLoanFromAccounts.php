<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\CustomerAccount;
use App\Models\Loan;
use App\Models\LoanRecoveryAccount;
use App\Models\LoanRecoveryAttempt;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\Finance\FormulaPolicyNotApproved;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final class RecoverLoanFromAccounts
{
    public function __construct(
        private readonly AccountingBalanceCalculator $balanceCalculator,
        private readonly RecordLoanRepayment $recordLoanRepayment,
    ) {}

    /**
     * @return array{loan: Loan, requested_amount_minor:int, recovered_amount_minor:int, remaining_amount_minor:int, attempts: array<int, LoanRecoveryAttempt>}
     */
    public function handle(Loan $loan, User $actor, int $requestedAmountMinor, ?string $recoveredOn = null): array
    {
        if ($requestedAmountMinor <= 0) {
            throw new InvalidArgumentException('Recovery amount must be positive.');
        }

        if (! in_array($loan->status, [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED], true)) {
            throw new InvalidArgumentException('Only disbursed, active, or rescheduled loans can be recovered from accounts.');
        }

        $remaining = $requestedAmountMinor;
        $recovered = 0;
        $attempts = [];

        foreach ($this->candidateAccounts($loan) as $candidate) {
            if ($remaining <= 0) {
                break;
            }

            $customerAccount = $candidate['customer_account'];
            $recoveryAccount = $candidate['recovery_account'];
            $available = $this->availableAmount($customerAccount, $loan->currency);
            $attemptAmount = min($remaining, max(0, $available));

            if ($attemptAmount <= 0) {
                $attempts[] = $this->recordAttempt($loan, $recoveryAccount, $customerAccount, $remaining, 0, LoanRecoveryAttempt::STATUS_FAILED, 'No available balance for recovery.', null);

                continue;
            }

            try {
                $result = $this->recordLoanRepayment->handle(
                    $loan,
                    $actor,
                    $attemptAmount,
                    $customerAccount->public_id,
                    $recoveredOn,
                    'Automated loan recovery from linked account',
                );
            } catch (FormulaPolicyNotApproved $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $attempts[] = $this->recordAttempt($loan, $recoveryAccount, $customerAccount, $attemptAmount, 0, LoanRecoveryAttempt::STATUS_FAILED, $exception->getMessage(), null);

                continue;
            }

            $journalEntry = $result['journal_entry'];
            $repayment = $result['repayment'];
            $recoveredAmount = $repayment->allocated_amount_minor;
            $attempts[] = $this->recordAttempt($loan, $recoveryAccount, $customerAccount, $attemptAmount, $recoveredAmount, LoanRecoveryAttempt::STATUS_SUCCEEDED, null, $journalEntry->id);
            $remaining -= $recoveredAmount;
            $recovered += $recoveredAmount;
        }

        if ($attempts === []) {
            throw new InvalidArgumentException('No active recovery account is configured for this loan.');
        }

        return [
            'loan' => $loan->refresh(),
            'requested_amount_minor' => $requestedAmountMinor,
            'recovered_amount_minor' => $recovered,
            'remaining_amount_minor' => max(0, $requestedAmountMinor - $recovered),
            'attempts' => $attempts,
        ];
    }

    /**
     * @return array<int, array{recovery_account: LoanRecoveryAccount|null, customer_account: CustomerAccount}>
     */
    private function candidateAccounts(Loan $loan): array
    {
        $candidates = [];
        $seenAccountIds = [];

        if ($loan->recovery_account_id !== null) {
            $account = CustomerAccount::query()->whereKey($loan->recovery_account_id)->first();
            if ($account instanceof CustomerAccount && $this->usableAccount($account, $loan, $seenAccountIds)) {
                $candidates[] = ['recovery_account' => null, 'customer_account' => $account];
                $seenAccountIds[] = $account->id;
            }
        }

        $recoveryAccountIds = DB::table('loan_recovery_accounts')
            ->select('id')
            ->where('loan_id', $loan->id)
            ->where('status', LoanRecoveryAccount::STATUS_ACTIVE)
            ->orderByDesc('is_primary')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->map(function (object $row): mixed {
                $data = (array) $row;

                return $data['id'] ?? null;
            })
            ->filter(fn (mixed $id): bool => is_int($id))
            ->values();
        $recoveryAccountModels = LoanRecoveryAccount::query()
            ->with('customerAccount')
            ->findMany($recoveryAccountIds->all())
            ->keyBy('id');

        foreach ($recoveryAccountIds as $recoveryAccountId) {
            $recoveryAccount = $recoveryAccountModels->get($recoveryAccountId);
            if (! $recoveryAccount instanceof LoanRecoveryAccount) {
                continue;
            }
            $account = $recoveryAccount->customerAccount;
            if ($account instanceof CustomerAccount && $this->usableAccount($account, $loan, $seenAccountIds)) {
                $candidates[] = ['recovery_account' => $recoveryAccount, 'customer_account' => $account];
                $seenAccountIds[] = $account->id;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int, int>  $seenAccountIds
     */
    private function usableAccount(mixed $account, Loan $loan, array $seenAccountIds): bool
    {
        return $account instanceof CustomerAccount
            && $account->status === CustomerAccount::STATUS_ACTIVE
            && $account->client_id === $loan->client_id
            && $account->agency_id === $loan->agency_id
            && $account->currency === $loan->currency
            && ! in_array($account->id, $seenAccountIds, true);
    }

    private function availableAmount(CustomerAccount $customerAccount, string $currency): int
    {
        return max(0, $this->balanceCalculator->availableForCustomerAccount($customerAccount, $currency)['available_balance_minor']);
    }

    private function recordAttempt(Loan $loan, ?LoanRecoveryAccount $recoveryAccount, CustomerAccount $customerAccount, int $requestedAmountMinor, int $recoveredAmountMinor, string $status, ?string $failureReason, ?int $journalEntryId): LoanRecoveryAttempt
    {
        return DB::transaction(function () use ($customerAccount, $failureReason, $journalEntryId, $loan, $recoveredAmountMinor, $recoveryAccount, $requestedAmountMinor, $status): LoanRecoveryAttempt {
            return LoanRecoveryAttempt::query()->create([
                'public_id' => (string) Str::ulid(),
                'loan_id' => $loan->id,
                'loan_recovery_account_id' => $recoveryAccount?->id,
                'customer_account_id' => $customerAccount->id,
                'requested_amount_minor' => $requestedAmountMinor,
                'recovered_amount_minor' => $recoveredAmountMinor,
                'currency' => $loan->currency,
                'status' => $status,
                'attempted_at' => now(),
                'failure_reason' => $failureReason,
                'journal_entry_id' => $journalEntryId,
            ])->loadMissing(['loan', 'recoveryAccount', 'customerAccount', 'journalEntry']);
        });
    }
}
