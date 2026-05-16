<?php

declare(strict_types=1);

namespace App\Support\Accounting;

use App\Models\AccountHold;
use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class AccountingBalanceCalculator
{
    /**
     * @return array{scope:string, public_id:string, currency:string, from:string|null, to:string|null, debit_total_minor:int, credit_total_minor:int, balance_minor:int, normal_balance_side:string|null}
     */
    public function forLedgerAccount(LedgerAccount $ledgerAccount, string $currency, ?string $from = null, ?string $to = null): array
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.ledger_account_id', $ledgerAccount->id)
            ->where('journal_lines.currency', $currency);

        $this->applyDateRange($query, $from, $to);

        $totals = $query
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total_minor')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total_minor')
            ->first();

        $debitTotal = (int) ($totals->debit_total_minor ?? 0);
        $creditTotal = (int) ($totals->credit_total_minor ?? 0);

        return [
            'scope' => 'ledger_account',
            'public_id' => $ledgerAccount->public_id,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'debit_total_minor' => $debitTotal,
            'credit_total_minor' => $creditTotal,
            'balance_minor' => $this->normalBalance($ledgerAccount->normal_balance_side, $debitTotal, $creditTotal),
            'normal_balance_side' => $ledgerAccount->normal_balance_side,
        ];
    }

    /**
     * @return array{scope:string, public_id:string, currency:string, from:string|null, to:string|null, debit_total_minor:int, credit_total_minor:int, balance_minor:int, normal_balance_side:string|null}
     */
    public function forCustomerAccount(CustomerAccount $customerAccount, string $currency, ?string $from = null, ?string $to = null): array
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'journal_lines.ledger_account_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.customer_account_id', $customerAccount->id)
            ->where('journal_lines.currency', $currency);

        $this->applyDateRange($query, $from, $to);

        $totals = $query
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total_minor')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total_minor')
            ->selectRaw("COALESCE(SUM(CASE WHEN ledger_accounts.normal_balance_side = 'debit' THEN journal_lines.debit_minor - journal_lines.credit_minor ELSE journal_lines.credit_minor - journal_lines.debit_minor END), 0) AS balance_minor")
            ->first();

        return [
            'scope' => 'customer_account',
            'public_id' => $customerAccount->public_id,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'debit_total_minor' => (int) ($totals->debit_total_minor ?? 0),
            'credit_total_minor' => (int) ($totals->credit_total_minor ?? 0),
            'balance_minor' => (int) ($totals->balance_minor ?? 0),
            'normal_balance_side' => $customerAccount->ledgerAccount?->normal_balance_side,
        ];
    }

    /**
     * @return array{scope:string, public_id:string, currency:string, accounting_balance_minor:int, minimum_balance_minor:int, unavailable_amount_minor:int, active_hold_amount_minor:int, available_balance_minor:int}
     */
    public function availableForCustomerAccount(CustomerAccount $customerAccount, string $currency): array
    {
        $customerAccount->loadMissing(['ledgerAccount', 'accountProduct']);

        $accounting = $this->forCustomerAccount($customerAccount, $currency);
        $minimumBalance = $customerAccount->accountProduct?->currency === $currency
            ? $customerAccount->accountProduct->minimum_balance_minor
            : 0;
        $unavailableAmount = $customerAccount->currency === $currency
            ? $customerAccount->unavailable_amount_minor
            : 0;
        $activeHoldAmount = (int) DB::table('account_holds')
            ->where('customer_account_id', $customerAccount->id)
            ->where('status', AccountHold::STATUS_ACTIVE)
            ->where('currency', $currency)
            ->sum('amount_minor');

        return [
            'scope' => 'customer_account_available',
            'public_id' => $customerAccount->public_id,
            'currency' => $currency,
            'accounting_balance_minor' => $accounting['balance_minor'],
            'minimum_balance_minor' => $minimumBalance,
            'unavailable_amount_minor' => $unavailableAmount,
            'active_hold_amount_minor' => $activeHoldAmount,
            'available_balance_minor' => $accounting['balance_minor'] - $minimumBalance - $unavailableAmount - $activeHoldAmount,
        ];
    }

    private function normalBalance(string $normalBalanceSide, int $debitTotal, int $creditTotal): int
    {
        if ($normalBalanceSide === LedgerAccount::NORMAL_BALANCE_CREDIT) {
            return $creditTotal - $debitTotal;
        }

        return $debitTotal - $creditTotal;
    }

    /**
     * @param  Builder  $query
     */
    private function applyDateRange($query, ?string $from, ?string $to): void
    {
        if ($from !== null) {
            $query->whereDate('journal_entries.business_date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('journal_entries.business_date', '<=', $to);
        }
    }
}
