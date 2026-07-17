<?php

declare(strict_types=1);

namespace App\Application\CashOperations;

use App\Models\JournalEntry;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for teller-session cash totals and the expected
 * (theoretical) till balance.
 *
 * Both the close-session validation and the dashboard session summary consume
 * this service so the two can never drift: the till-balance direction map lives
 * here only, and the expected balance is always opening declaration plus the
 * signed sum of posted till-affecting transactions.
 */
final class TellerSessionSummary
{
    /**
     * Exact transaction-type direction map for the physical till balance.
     * Adding a new till-affecting teller transaction type? Add it here AND to
     * the posted per-type sums in {@see aggregateFor()}. Unknown types fail
     * closed (do not move the till).
     *
     * @var array<string, int>
     */
    public const array TILL_BALANCE_DIRECTION = [
        TellerTransaction::TYPE_CASH_DEPOSIT => 1,
        TellerTransaction::TYPE_CASH_WITHDRAWAL => -1,
    ];

    /**
     * Theoretical till balance for a single session (opening + posted movement).
     * This is the authoritative figure the close workflow validates against.
     */
    public function theoreticalBalanceMinor(TellerSession $session): int
    {
        $rows = DB::table('teller_transactions')
            ->where('teller_session_id', $session->id)
            ->where('status', TellerTransaction::STATUS_POSTED)
            ->get([
                'transaction_type',
                'amount_minor',
                'cash_amount_minor',
                'cheque_amount_minor',
                'transfer_amount_minor',
                'journal_entry_id',
            ]);

        $movement = 0;
        $tillLedgerAccountId = $this->tillLedgerAccountId($session);
        foreach ($rows as $row) {
            $type = is_string($row->transaction_type) ? $row->transaction_type : '';
            if ($type === TellerTransaction::TYPE_MANUAL_JOURNAL && is_numeric($row->journal_entry_id ?? null)) {
                $movement += $this->journalCashMovement((int) $row->journal_entry_id, $tillLedgerAccountId);

                continue;
            }

            $movement += (self::TILL_BALANCE_DIRECTION[$type] ?? 0) * $this->physicalCashAmountMinor($row);
        }

        return $this->openingDeclarationMinor($session) + $movement;
    }

    /**
     * Attach a `cash_summary` attribute to each session using a single grouped
     * aggregate query for the whole set (no per-row query / no N+1).
     *
     * @param  Collection<int, TellerSession>|iterable<TellerSession>  $sessions
     */
    public function attach(iterable $sessions): void
    {
        /** @var array<int, TellerSession> $list */
        $list = [];
        $ids = [];
        foreach ($sessions as $session) {
            $list[] = $session;
            $ids[] = $session->id;
        }

        if ($list === []) {
            return;
        }

        $aggregates = $this->aggregateFor($ids);

        foreach ($list as $session) {
            $session->setAttribute('cash_summary', $this->buildSummary($session, $aggregates[$session->id] ?? null));
        }
    }

    /**
     * @param  array<int, int>  $sessionIds
     * @return array<int, array<string, mixed>>
     */
    private function aggregateFor(array $sessionIds): array
    {
        $deposit = TellerTransaction::TYPE_CASH_DEPOSIT;
        $withdrawal = TellerTransaction::TYPE_CASH_WITHDRAWAL;
        $manual = TellerTransaction::TYPE_MANUAL_JOURNAL;
        $reversal = TellerTransaction::TYPE_REVERSAL;
        $posted = TellerTransaction::STATUS_POSTED;
        $closedStatuses = [
            TellerTransaction::STATUS_POSTED,
            TellerTransaction::STATUS_REVERSED,
            TellerTransaction::STATUS_CANCELLED,
        ];

        // Transaction-type and status values are class constants, never request
        // input; keep this aggregate grouped so session lists do not run one
        // transaction query per row.
        $rows = DB::table('teller_transactions')
            ->whereIn('teller_session_id', $sessionIds)
            ->groupBy('teller_session_id')
            ->get([
                'teller_session_id',
                DB::raw("COALESCE(SUM(CASE WHEN transaction_type = '{$deposit}' AND status = '{$posted}' THEN CASE WHEN COALESCE(cash_amount_minor, 0) > 0 THEN cash_amount_minor WHEN COALESCE(cheque_amount_minor, 0) > 0 OR COALESCE(transfer_amount_minor, 0) > 0 THEN 0 ELSE amount_minor END ELSE 0 END), 0) AS deposits_total"),
                DB::raw("COALESCE(SUM(CASE WHEN transaction_type = '{$withdrawal}' AND status = '{$posted}' THEN CASE WHEN COALESCE(cash_amount_minor, 0) > 0 THEN cash_amount_minor WHEN COALESCE(cheque_amount_minor, 0) > 0 OR COALESCE(transfer_amount_minor, 0) > 0 THEN 0 ELSE amount_minor END ELSE 0 END), 0) AS withdrawals_total"),
                DB::raw("COALESCE(SUM(CASE WHEN transaction_type = '{$manual}' AND status = '{$posted}' THEN amount_minor ELSE 0 END), 0) AS manual_journals_total"),
                DB::raw("COALESCE(SUM(CASE WHEN transaction_type = '{$reversal}' AND status = '{$posted}' THEN amount_minor ELSE 0 END), 0) AS reversals_total"),
                DB::raw('COUNT(*) AS transaction_count'),
                DB::raw("COALESCE(SUM(CASE WHEN status = '{$posted}' THEN 1 ELSE 0 END), 0) AS posted_count"),
                DB::raw("COALESCE(SUM(CASE WHEN status NOT IN ('".implode("', '", $closedStatuses)."') THEN 1 ELSE 0 END), 0) AS pending_count"),
                DB::raw("COALESCE(SUM(CASE WHEN status = '{$posted}' AND fees_applied = true THEN fee_amount_minor ELSE 0 END), 0) AS commissions_total"),
                DB::raw("COUNT(DISTINCT CASE WHEN status = '{$posted}' AND client_id IS NOT NULL THEN client_id END) AS distinct_clients_served"),
                DB::raw('MAX(created_at) AS last_transaction_at'),
            ]);

        $bySession = [];
        foreach ($rows as $row) {
            $array = (array) $row;
            $bySession[(int) $row->teller_session_id] = $array;
        }

        foreach ($this->manualCashMovementsFor($sessionIds) as $sessionId => $movementMinor) {
            $bySession[$sessionId] ??= ['teller_session_id' => $sessionId];
            $bySession[$sessionId]['manual_cash_movement_minor'] = $movementMinor;
        }

        return $bySession;
    }

    /**
     * @param  array<string, mixed>|null  $aggregate
     * @return array<string, mixed>
     */
    private function buildSummary(TellerSession $session, ?array $aggregate): array
    {
        $deposits = $this->intFrom($aggregate, 'deposits_total');
        $withdrawals = $this->intFrom($aggregate, 'withdrawals_total');
        $manual = $this->intFrom($aggregate, 'manual_journals_total');
        $manualCashMovement = $this->intFrom($aggregate, 'manual_cash_movement_minor');
        $reversals = $this->intFrom($aggregate, 'reversals_total');
        $transactionCount = $this->intFrom($aggregate, 'transaction_count');
        $postedCount = $this->intFrom($aggregate, 'posted_count');
        $pendingCount = $this->intFrom($aggregate, 'pending_count');
        $rawLast = $aggregate['last_transaction_at'] ?? null;
        $lastTransactionAt = is_string($rawLast) && $rawLast !== ''
            ? CarbonImmutable::parse($rawLast)->toAtomString()
            : null;

        // Expected cash balance applies the till-direction map to posted
        // till-affecting components, matching theoreticalBalanceMinor() exactly.
        $expected = $this->openingDeclarationMinor($session)
            + (self::TILL_BALANCE_DIRECTION[TellerTransaction::TYPE_CASH_DEPOSIT] * $deposits)
            + (self::TILL_BALANCE_DIRECTION[TellerTransaction::TYPE_CASH_WITHDRAWAL] * $withdrawals)
            + $manualCashMovement;

        return [
            'deposits_total_minor' => $deposits,
            'withdrawals_total_minor' => $withdrawals,
            'manual_journals_total_minor' => $manual,
            'manual_journal_cash_movement_minor' => $manualCashMovement,
            'reversals_total_minor' => $reversals,
            'commissions_total_minor' => $this->intFrom($aggregate, 'commissions_total'),
            'distinct_clients_served_count' => $this->intFrom($aggregate, 'distinct_clients_served'),
            'transaction_count' => $transactionCount,
            'posted_transaction_count' => $postedCount,
            'pending_transaction_count' => $pendingCount,
            'expected_cash_balance_minor' => $expected,
            'last_transaction_at' => $lastTransactionAt,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $row
     */
    private function intFrom(?array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }

    private function openingDeclarationMinor(TellerSession $session): int
    {
        $value = $session->getAttribute('opening_declaration_minor');

        return is_numeric($value) ? (int) $value : 0;
    }

    private function physicalCashAmountMinor(object $row): int
    {
        $cash = is_numeric($row->cash_amount_minor ?? null) ? (int) $row->cash_amount_minor : 0;
        if ($cash > 0) {
            return $cash;
        }

        $cheque = is_numeric($row->cheque_amount_minor ?? null) ? (int) $row->cheque_amount_minor : 0;
        $transfer = is_numeric($row->transfer_amount_minor ?? null) ? (int) $row->transfer_amount_minor : 0;
        if ($cheque > 0 || $transfer > 0) {
            return 0;
        }

        return is_numeric($row->amount_minor ?? null) ? (int) $row->amount_minor : 0;
    }

    private function tillLedgerAccountId(TellerSession $session): ?int
    {
        $value = DB::table('tills')->where('id', $session->till_id)->value('ledger_account_id');

        return is_numeric($value) ? (int) $value : null;
    }

    private function journalCashMovement(int $journalEntryId, ?int $tillLedgerAccountId): int
    {
        if ($tillLedgerAccountId === null) {
            return 0;
        }

        $value = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.journal_entry_id', $journalEntryId)
            ->where('journal_lines.ledger_account_id', $tillLedgerAccountId)
            ->sum(DB::raw('journal_lines.debit_minor - journal_lines.credit_minor'));

        return (int) $value;
    }

    /**
     * @param  array<int, int>  $sessionIds
     * @return array<int, int>
     */
    private function manualCashMovementsFor(array $sessionIds): array
    {
        $rows = DB::table('teller_transactions as transactions')
            ->join('teller_sessions as sessions', 'sessions.id', '=', 'transactions.teller_session_id')
            ->join('tills', 'tills.id', '=', 'sessions.till_id')
            ->join('journal_entries', 'journal_entries.id', '=', 'transactions.journal_entry_id')
            ->join('journal_lines', function ($join): void {
                $join->on('journal_lines.journal_entry_id', '=', 'journal_entries.id')
                    ->on('journal_lines.ledger_account_id', '=', 'tills.ledger_account_id');
            })
            ->whereIn('transactions.teller_session_id', $sessionIds)
            ->where('transactions.transaction_type', TellerTransaction::TYPE_MANUAL_JOURNAL)
            ->where('transactions.status', TellerTransaction::STATUS_POSTED)
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->groupBy('transactions.teller_session_id')
            ->get([
                'transactions.teller_session_id',
                DB::raw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) AS movement_minor'),
            ]);

        $movements = [];
        foreach ($rows as $row) {
            $movements[(int) $row->teller_session_id] = is_numeric($row->movement_minor) ? (int) $row->movement_minor : 0;
        }

        return $movements;
    }
}
