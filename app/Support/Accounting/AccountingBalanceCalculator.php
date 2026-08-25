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
    public function __construct(
        private readonly LedgerAccountHierarchy $hierarchy,
    ) {}

    /**
     * Whether a balance for this account means its subtree. Shared so callers
     * reporting the scope alongside a balance cannot describe it differently
     * from how it was computed.
     */
    public function consolidatesByDefault(LedgerAccount $ledgerAccount): bool
    {
        return ! $ledgerAccount->is_postable || $this->hierarchy->hasChildren($ledgerAccount->id);
    }

    /**
     * Balance of a ledger account.
     *
     * An account consolidates its subtree by default whenever anything hangs
     * beneath it: an institution-level 571000 reports the sum of the agency
     * 571001/571002/571003. Pass $consolidated explicitly to override, which a
     * caller wanting strictly own-movement totals must do.
     *
     * Having children is the test, not `is_postable`. The two used to be
     * treated as the same thing, which held while only grouping accounts had
     * children. Per-dossier divisionaries broke that: a control account that
     * operations still post to directly — and must stay postable, or
     * OperationAccountMappingController rejects the mappings that resolve it —
     * now also carries a child per loan and per client. Keyed on postability it
     * reported only its own legacy movements while every franc opened after the
     * change sat in a child, silently under-reporting the whole control.
     *
     * @return array{scope:string, public_id:string, currency:string, from:string|null, to:string|null, debit_total_minor:int, credit_total_minor:int, balance_minor:int, normal_balance_side:string|null, balance_side:string|null}
     */
    public function forLedgerAccount(LedgerAccount $ledgerAccount, string $currency, ?string $from = null, ?string $to = null, ?bool $consolidated = null): array
    {
        $consolidated ??= $this->consolidatesByDefault($ledgerAccount);

        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.currency', $currency);

        if ($consolidated) {
            $query->whereIn('journal_lines.ledger_account_id', $this->hierarchy->subtreeIds($ledgerAccount->id));
        } else {
            $query->where('journal_lines.ledger_account_id', $ledgerAccount->id);
        }

        $this->applyDateRange($query, $from, $to);

        $totals = $query
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor), 0) AS debit_total_minor')
            ->selectRaw('COALESCE(SUM(journal_lines.credit_minor), 0) AS credit_total_minor')
            ->first();

        $debitTotal = (int) ($totals->debit_total_minor ?? 0);
        $creditTotal = (int) ($totals->credit_total_minor ?? 0);

        return [
            'scope' => $consolidated ? 'ledger_account_consolidated' : 'ledger_account',
            'public_id' => $ledgerAccount->public_id,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'debit_total_minor' => $debitTotal,
            'credit_total_minor' => $creditTotal,
            'balance_minor' => $this->normalBalance($ledgerAccount->normal_balance_side, $debitTotal, $creditTotal),
            'normal_balance_side' => $ledgerAccount->normal_balance_side,
            // Which side the account actually sits on for this period, as
            // opposed to the side it is expected to sit on. For a bivalent
            // account (no imposed side) this is the only meaningful answer.
            'balance_side' => $this->positionSide($debitTotal, $creditTotal),
        ];
    }

    /**
     * @return array{scope:string, public_id:string, currency:string, from:string|null, to:string|null, debit_total_minor:int, credit_total_minor:int, balance_minor:int, normal_balance_side:string|null, balance_side:string|null}
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
            // Mirrors normalBalance(): only an explicit 'credit' inverts the
            // subtraction. Written this way round because `= 'debit'` is false
            // for NULL, which would have quietly reported every bivalent
            // account's balance the wrong way up.
            ->selectRaw("COALESCE(SUM(CASE WHEN ledger_accounts.normal_balance_side = 'credit' THEN journal_lines.credit_minor - journal_lines.debit_minor ELSE journal_lines.debit_minor - journal_lines.credit_minor END), 0) AS balance_minor")
            ->first();

        $debitTotal = (int) ($totals->debit_total_minor ?? 0);
        $creditTotal = (int) ($totals->credit_total_minor ?? 0);

        return [
            'scope' => 'customer_account',
            'public_id' => $customerAccount->public_id,
            'currency' => $currency,
            'from' => $from,
            'to' => $to,
            'debit_total_minor' => $debitTotal,
            'credit_total_minor' => $creditTotal,
            'balance_minor' => (int) ($totals->balance_minor ?? 0),
            'normal_balance_side' => $customerAccount->ledgerAccount?->normal_balance_side,
            'balance_side' => $this->positionSide($debitTotal, $creditTotal),
        ];
    }

    /**
     * @return array{scope:string, public_id:string, currency:string, accounting_balance_minor:int, minimum_balance_minor:int, unavailable_amount_minor:int, active_hold_amount_minor:int, overdraft_limit_minor:int, available_balance_minor:int}
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

        /*
         * An authorised overdraft is spending power the account genuinely has, so
         * it belongs in what is available. The product carried `allows_overdraft`
         * and a limit, both settable from the product screen, and nothing read
         * them: an account on a current-account product was refused at zero
         * exactly like one with no overdraft at all.
         *
         * Guarded on the product's own currency, like the minimum balance above:
         * a limit expressed in one currency says nothing about another.
         */
        $overdraftLimit = 0;
        $product = $customerAccount->accountProduct;
        if ($product !== null
            && $product->allows_overdraft
            && $product->currency === $currency) {
            $overdraftLimit = max(0, $product->overdraft_limit_minor);
        }

        return [
            'scope' => 'customer_account_available',
            'public_id' => $customerAccount->public_id,
            'currency' => $currency,
            'accounting_balance_minor' => $accounting['balance_minor'],
            'minimum_balance_minor' => $minimumBalance,
            'unavailable_amount_minor' => $unavailableAmount,
            'active_hold_amount_minor' => $activeHoldAmount,
            'overdraft_limit_minor' => $overdraftLimit,
            'available_balance_minor' => $accounting['balance_minor'] - $minimumBalance - $unavailableAmount - $activeHoldAmount + $overdraftLimit,
        ];
    }

    /**
     * The balance oriented to the account's normal side, so a well-behaved
     * account reads positive.
     *
     * A bivalent account (null side) has no side to orient to, so it reports the
     * natural signed balance — positive in debit, negative in credit — and
     * `balance_side` names the position outright.
     */
    private function normalBalance(?string $normalBalanceSide, int $debitTotal, int $creditTotal): int
    {
        if ($normalBalanceSide === LedgerAccount::NORMAL_BALANCE_CREDIT) {
            return $creditTotal - $debitTotal;
        }

        return $debitTotal - $creditTotal;
    }

    /**
     * The side the account is actually on: debit when it owes nothing, credit
     * when the credits exceed the debits. Null only when the two cancel out,
     * which is a position on neither side.
     */
    /**
     * Which side an account actually sits on for a period, from the figures
     * alone — as opposed to the side it was told to sit on. Null when the two
     * totals square, because that is a position on neither side.
     *
     * Public because the arrêté needs the identical answer: a trial balance that
     * derived the side its own way would eventually disagree with the account
     * screen about the same account on the same day.
     */
    public function positionSide(int $debitTotal, int $creditTotal): ?string
    {
        if ($debitTotal === $creditTotal) {
            return null;
        }

        return $debitTotal > $creditTotal
            ? LedgerAccount::NORMAL_BALANCE_DEBIT
            : LedgerAccount::NORMAL_BALANCE_CREDIT;
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
