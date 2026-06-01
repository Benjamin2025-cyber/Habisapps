<?php

declare(strict_types=1);

namespace App\Support\AccountingDay;

use App\Models\AccountingDay;
use App\Models\JournalEntry;
use App\Models\TellerSession;
use Illuminate\Support\Facades\DB;

/**
 * Evaluates whether an accounting day satisfies all end-of-day close controls.
 *
 * A day cannot move from `closing` to `closed` while any of these remain:
 *  - unposted journal entries (draft/submitted/approved) for the day,
 *  - open teller sessions in scope,
 *  - pending teller transactions awaiting post/cancel.
 */
final class CloseControlService
{
    /**
     * Journal statuses that block close because they are not yet final.
     *
     * @var array<int, string>
     */
    private const UNPOSTED_JOURNAL_STATUSES = [
        JournalEntry::STATUS_DRAFT,
        JournalEntry::STATUS_SUBMITTED,
        JournalEntry::STATUS_APPROVED,
    ];

    public function evaluate(AccountingDay $day): CloseReadinessResult
    {
        $businessDate = $day->business_date?->toDateString();
        $blockers = [];

        $unpostedJournals = $this->countUnpostedJournals($day, $businessDate);
        if ($unpostedJournals > 0) {
            $blockers[] = [
                'control' => 'unposted_journals',
                'message' => 'There are unposted journal entries (draft, submitted, or approved) for this accounting day.',
                'count' => $unpostedJournals,
            ];
        }

        $openSessions = $this->countOpenTellerSessions($day, $businessDate);
        if ($openSessions > 0) {
            $blockers[] = [
                'control' => 'open_teller_sessions',
                'message' => 'There are open teller sessions in scope that must be closed.',
                'count' => $openSessions,
            ];
        }

        $pendingTransactions = $this->countPendingTellerTransactions($day, $businessDate);
        if ($pendingTransactions > 0) {
            $blockers[] = [
                'control' => 'pending_teller_transactions',
                'message' => 'There are pending teller transactions that must be posted or cancelled.',
                'count' => $pendingTransactions,
            ];
        }

        $summary = [
            'business_date' => $businessDate,
            'scope_type' => $day->scope_type,
            'agency_id_scope' => $day->agency_id,
            'unposted_journals' => $unpostedJournals,
            'open_teller_sessions' => $openSessions,
            'pending_teller_transactions' => $pendingTransactions,
        ];

        return new CloseReadinessResult($blockers === [], $blockers, $summary);
    }

    private function countUnpostedJournals(AccountingDay $day, ?string $businessDate): int
    {
        $query = JournalEntry::query()
            ->whereIn('status', self::UNPOSTED_JOURNAL_STATUSES)
            ->where('business_date', $businessDate);

        if ($day->scope_type === AccountingDay::SCOPE_AGENCY) {
            $query->where('agency_id', $day->agency_id);
        }

        return $query->count();
    }

    private function countOpenTellerSessions(AccountingDay $day, ?string $businessDate): int
    {
        $query = TellerSession::query()
            ->where('status', TellerSession::STATUS_OPEN)
            ->where('business_date', $businessDate);

        if ($day->scope_type === AccountingDay::SCOPE_AGENCY) {
            $query->where('agency_id', $day->agency_id);
        }

        return $query->count();
    }

    private function countPendingTellerTransactions(AccountingDay $day, ?string $businessDate): int
    {
        $query = DB::table('teller_transactions')
            ->join('teller_sessions', 'teller_transactions.teller_session_id', '=', 'teller_sessions.id')
            ->where('teller_sessions.business_date', $businessDate)
            ->whereNotIn('teller_transactions.status', ['posted', 'reversed', 'cancelled']);

        if ($day->scope_type === AccountingDay::SCOPE_AGENCY) {
            $query->where('teller_sessions.agency_id', $day->agency_id);
        }

        return $query->count();
    }
}
