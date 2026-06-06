<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanDisbursement;
use App\Models\LoanProduct;
use App\Support\Accounting\AgencyLedgerMappingResolver;
use Illuminate\Support\Facades\DB;

/**
 * Shared readiness checks for loans awaiting principal disbursement.
 */
final class LoanDisbursementReadiness
{
    public function __construct(
        private readonly LoanSetupState $setupState,
        private readonly AgencyLedgerMappingResolver $mappingResolver,
    ) {}

    public function isAwaitingDisbursement(Loan $loan): bool
    {
        if ($loan->status !== Loan::STATUS_APPROVED) {
            return false;
        }

        if ($this->hasPostedDisbursement($loan->id)) {
            return false;
        }

        $loan->loadMissing('loanProduct');
        $product = $loan->loanProduct;
        if (! $product instanceof LoanProduct) {
            return false;
        }

        $setup = $this->setupState->forLoan($loan);
        if (($setup['ready_for_disbursement'] ?? false) !== true) {
            return false;
        }

        return $this->hasUsablePrincipalLedgerMapping($loan, $product);
    }

    /**
     * @param  list<int>  $loanIds
     * @return list<int>
     */
    public function awaitingDisbursementIdsWithin(array $loanIds): array
    {
        if ($loanIds === []) {
            return [];
        }

        $alreadyDisbursed = DB::table('loan_disbursements')
            ->whereIn('loan_id', $loanIds)
            ->where('status', LoanDisbursement::STATUS_POSTED)
            ->pluck('loan_id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $candidates = array_values(array_diff($loanIds, $alreadyDisbursed));
        if ($candidates === []) {
            return [];
        }

        $ready = [];
        foreach (Loan::query()->with('loanProduct')->whereKey($candidates)->get() as $loan) {
            if ($this->isAwaitingDisbursement($loan)) {
                $ready[] = $loan->id;
            }
        }

        return $ready;
    }

    private function hasPostedDisbursement(int $loanId): bool
    {
        return DB::table('loan_disbursements')
            ->where('loan_id', $loanId)
            ->where('status', LoanDisbursement::STATUS_POSTED)
            ->exists();
    }

    private function hasUsablePrincipalLedgerMapping(Loan $loan, LoanProduct $product): bool
    {
        $currency = $loan->currency !== '' ? $loan->currency : 'XAF';
        $resolution = $this->mappingResolver->resolve(
            'loan_principal_disbursement',
            'loan',
            $loan->agency_id,
            $currency,
            AgencyLedgerMappingResolver::LEG_DEBIT,
        );
        $status = $resolution['status'];
        $debitLedgerId = $resolution['debit_ledger_account_id'];

        if (($status === AgencyLedgerMappingResolver::READY || $status === AgencyLedgerMappingResolver::OVERLAPPING)
            && is_int($debitLedgerId)) {
            $ledger = LedgerAccount::query()->whereKey($debitLedgerId)->first();

            return $ledger instanceof LedgerAccount;
        }

        if ($status === AgencyLedgerMappingResolver::MISSING) {
            return $this->agencyValidProductLedger($loan, $product) instanceof LedgerAccount;
        }

        return false;
    }

    private function agencyValidProductLedger(Loan $loan, LoanProduct $product): ?LedgerAccount
    {
        if ($product->ledger_account_id === null) {
            return null;
        }

        $ledger = LedgerAccount::query()->whereKey($product->ledger_account_id)->first();
        if ($ledger instanceof LedgerAccount
            && $ledger->status === LedgerAccount::STATUS_ACTIVE
            && $ledger->agency_id === $loan->agency_id) {
            return $ledger;
        }

        return null;
    }
}
