<?php

declare(strict_types=1);

namespace App\Support\AccountingDay;

use App\Models\AccountingDay;
use App\Models\JournalEntry;
use App\Models\TellerSession;
use App\Models\TillReconciliation;
use Illuminate\Database\Eloquent\Builder;
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
        $businessDate = $day->business_date->toDateString();
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

    private function countUnpostedJournals(AccountingDay $day, string $businessDate): int
    {
        $query = JournalEntry::query()
            ->where('business_date', $businessDate);
        $query->getQuery()->whereIn('status', self::UNPOSTED_JOURNAL_STATUSES);

        if ($day->scope_type === AccountingDay::SCOPE_AGENCY) {
            $query->where('agency_id', $day->agency_id);
        }

        return $query->getQuery()->count();
    }

    private function countOpenTellerSessions(AccountingDay $day, string $businessDate): int
    {
        return $this->openTellerSessionsQuery($day, $businessDate)->getQuery()->count();
    }

    /**
     * Public identifiers of the open teller sessions blocking a close for the day.
     *
     * Shares the exact scope/date rules with {@see countOpenTellerSessions()} and
     * the cash-close verification batch so the start-close preflight and the final
     * close controls can never disagree about what counts as an open session.
     *
     * @return array<int, array{teller_session_public_id: string, till_public_id: string|null, teller_user_public_id: string|null, business_date: string}>
     */
    public function openTellerSessionDigest(AccountingDay $day): array
    {
        $sessions = $this->openTellerSessionsQuery($day, $day->business_date->toDateString())
            ->with(['till:id,public_id', 'teller:id,public_id'])
            ->get();

        return $sessions->map(static fn (TellerSession $session): array => [
            'teller_session_public_id' => $session->public_id,
            'till_public_id' => $session->till?->public_id,
            'teller_user_public_id' => $session->teller?->public_id,
            'business_date' => $session->business_date->toDateString(),
        ])->values()->all();
    }

    /**
     * Closed teller sessions for the day that lack a balanced, zero-difference
     * reconciliation. Mirrors the cash-close verification batch rule so the
     * start-close preflight can refuse to enter `closing` when the final cash
     * control would fail — while `closing`, the registration lock forbids
     * recording the reconciliation that would clear the blocker.
     */
    public function unreconciledClosedSessionCount(AccountingDay $day): int
    {
        $query = DB::table('teller_sessions')
            ->where('status', TellerSession::STATUS_CLOSED)
            ->whereDate('business_date', $day->business_date->toDateString())
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('till_reconciliations')
                    ->whereColumn('till_reconciliations.teller_session_id', 'teller_sessions.id')
                    ->where('till_reconciliations.status', TillReconciliation::STATUS_BALANCED)
                    ->where('till_reconciliations.difference_minor', 0);
            });

        if ($day->scope_type === AccountingDay::SCOPE_AGENCY) {
            $query->where('agency_id', $day->agency_id);
        }

        return $query->count();
    }

    /**
     * @return Builder<TellerSession>
     */
    private function openTellerSessionsQuery(AccountingDay $day, string $businessDate): Builder
    {
        $query = TellerSession::query()
            ->where('status', TellerSession::STATUS_OPEN)
            ->where('business_date', $businessDate);

        if ($day->scope_type === AccountingDay::SCOPE_AGENCY) {
            $query->where('agency_id', $day->agency_id);
        }

        return $query;
    }

    private function countPendingTellerTransactions(AccountingDay $day, string $businessDate): int
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
