<?php

declare(strict_types=1);

namespace App\Application\CashOperations;

use App\Http\Controllers\BaseController;
use App\Models\JournalEntry;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The « brouillard de caisse » — the accounting team's line-by-line cash
 * journal for one till (Édition module).
 *
 * Every settled cash operation appears as its own row — deposits, withdrawals,
 * manual journals, and reversed lines kept visible with their status — in the
 * order the cash moved, with the ten columns the Édition screen prints:
 * n° d'ordre, pièce de caisse, date, n° de compte, libellé, montant débité,
 * montant crédité, solde progressif, code guichet, caissier.
 *
 * « Code guichet » is the branch code (`agency_code`) — the guichet of a RIB,
 * not the caisse. The caisse's own code rides along as `till_code` so the
 * printed sheet can name both without the reader having to guess which one the
 * column meant.
 *
 * Each row's movement is its own journal entry's sum on the till's ledger
 * account — the ledger is the truth, not an amount heuristic: a mixed payment
 * contributes exactly its cash component, because that is all its entry puts on
 * the till.
 *
 * An annulled pair moves nothing. The reversed line and the extourne that
 * cancels it both print their amounts — the brouillard is a record, and an
 * operation that was undone still happened — but neither shifts the solde
 * progressif: the first is cancelled and the second does the cancelling. Taking
 * the extourne's movement while the original is already excluded would subtract
 * the same francs twice, and the sheet would come up short by every reversal of
 * the day against a drawer that is not. That rule is TellerSessionSummary's
 * exactly, which is what the session close validates the counted cash against;
 * a brouillard that disagreed with the close would be reconciled against the
 * drawer and lose.
 *
 * The solde progressif is the till's declared cash position: it restarts at
 * each session's opening declaration (the counted drawer at open) and
 * accumulates that session's settled movements. Between sessions the till can
 * be emptied into the vault, so a session's declaration is the fresh position,
 * not an increment. The balance is computed in SQL over the session's whole
 * history before any date filter or pagination is applied, so page 2 continues
 * page 1's balance instead of restarting at zero — and only one page of rows
 * ever leaves the database.
 */
final class TillCashJournalWorkflow extends BaseController
{
    public function __construct(
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    public function index(Request $request, Till $till): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || $actor->cannot('view', $till)
            || $actor->cannot('viewAny', TellerTransaction::class)) {
            return $this->respondForbidden();
        }

        if (! $actor->hasRole('platform-admin')) {
            $agencyId = $this->staffAgencyScope->currentAgencyId($actor);
            if ($agencyId === null || $agencyId !== $till->agency_id) {
                return $this->respondForbidden('The cash journal can only be read inside your current agency.');
            }
        }

        // A plain teller reads only the journal of the sessions they ran
        // themselves — scoped, not refused, exactly like the teller-transaction
        // list. Refusing outright would lock a teller out of a till the moment a
        // colleague ran one session on it, which is every shared till there is.
        // Agency managers and cash supervisors read the whole till.
        $restrictToTellerId = $actor->hasRole('teller') && ! $actor->hasRole('agency-manager')
            ? $actor->id
            : null;

        $filters = $this->validatedFilters($request);
        if ($filters === null) {
            return $this->respondUnprocessable(errors: ['filter' => ['Unsupported filter parameters.']]);
        }

        $till->loadMissing(['agency']);
        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $page = max($request->integer('page', 1), 1);

        [$lines, $total, $openingBalance] = $this->journalRows($till, $filters, $perPage, $page, $restrictToTellerId);

        return $this->respondSuccess([
            'till' => [
                'public_id' => $till->public_id,
                'code' => $till->code,
                'name' => $till->name,
                'agency_public_id' => $till->agency?->public_id,
                // « Code guichet » in the printed brouillard: the branch code,
                // which is what the column means in BEAC practice. The caisse
                // keeps its own code in `till.code` and on every line.
                'agency_code' => $till->agency?->code,
            ],
            'opening_balance_minor' => $openingBalance,
            'lines' => $lines,
        ], meta: [
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => (int) ceil(max($total, 1) / $perPage),
            ],
        ]);
    }

    /**
     * @return array{from: ?string, to: ?string, transaction_type: ?string}|null
     */
    private function validatedFilters(Request $request): ?array
    {
        $filter = $request->query('filter');
        $filter = is_array($filter) ? $filter : [];

        $unknown = array_diff(array_keys($filter), ['transaction_date_from', 'transaction_date_to', 'transaction_type']);
        if ($unknown !== []) {
            return null;
        }

        $from = $filter['transaction_date_from'] ?? null;
        $to = $filter['transaction_date_to'] ?? null;
        foreach ([$from, $to] as $date) {
            if ($date !== null && (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1)) {
                return null;
            }
        }

        $type = $filter['transaction_type'] ?? null;
        if ($type !== null && (! is_string($type) || ! in_array($type, [
            TellerTransaction::TYPE_CASH_DEPOSIT,
            TellerTransaction::TYPE_CASH_WITHDRAWAL,
            TellerTransaction::TYPE_REVERSAL,
            TellerTransaction::TYPE_MANUAL_JOURNAL,
        ], true))) {
            return null;
        }

        return [
            'from' => is_string($from) ? $from : null,
            'to' => is_string($to) ? $to : null,
            'transaction_type' => is_string($type) ? $type : null,
        ];
    }

    /**
     * One SQL pass does the heavy lifting: the per-row movement (correlated to
     * the till's ledger), the per-session reset to the declared opening, the
     * running balance over the whole history, the filtered window, its
     * ordinals, and the pagination — so memory and payload stay the size of
     * one page no matter how long the till has been running.
     *
     * @param  array{from: ?string, to: ?string, transaction_type: ?string}  $filters
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: int} the page's lines, the window's total, and the balance before it
     */
    private function journalRows(Till $till, array $filters, int $perPage, int $page, ?int $restrictToTellerId): array
    {
        $posted = TellerTransaction::STATUS_POSTED;
        $postedJournal = JournalEntry::STATUS_POSTED;

        $windowConditions = '';
        $windowBindings = [];
        if ($filters['from'] !== null) {
            $windowConditions .= ' AND transaction_date >= :from';
            $windowBindings['from'] = $filters['from'];
        }
        if ($filters['to'] !== null) {
            $windowConditions .= ' AND transaction_date <= :to';
            $windowBindings['to'] = $filters['to'];
        }
        if ($filters['transaction_type'] !== null) {
            $windowConditions .= ' AND transaction_type = :transactionType';
            $windowBindings['transactionType'] = $filters['transaction_type'];
        }

        // The window is cut last, after the running balance: the solde
        // progressif is a property of the till, not of the slice asked for.
        // ROW_NUMBER runs after the WHERE and before the LIMIT, so the ordinals
        // number the whole filtered brouillard and page 2 continues page 1 —
        // as does the running balance.
        $pagedSql = $this->baseSql($restrictToTellerId)." SELECT
                *,
                ROW_NUMBER() OVER (ORDER BY transaction_date, id) AS sequence_number
            FROM running
            WHERE 1 = 1{$windowConditions}
            ORDER BY transaction_date, id
            LIMIT :limit OFFSET :offset";

        $rows = DB::select($pagedSql, [
            ...$this->baseBindings($till, $restrictToTellerId),
            ...$windowBindings,
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        // A page beyond the window must still report the window's true size,
        // or the client could not page back — hence a dedicated count rather
        // than reading the total off the returned rows.
        $countRow = DB::selectOne(
            $this->baseSql($restrictToTellerId).' SELECT COUNT(*) AS total FROM running WHERE 1 = 1'.$windowConditions,
            [...$this->baseBindings($till, $restrictToTellerId), ...$windowBindings],
        );
        // COUNT() comes back as a string on some drivers; `is_int` alone would
        // have reported an empty brouillard for a full one.
        $total = is_object($countRow) ? $this->rowInt($countRow, 'total') : 0;

        $lines = [];
        foreach (array_values($rows) as $row) {
            $lines[] = $this->rowPayload(
                $row,
                $this->rowInt($row, 'sequence_number'),
                $this->rowInt($row, 'recorded_minor'),
                $this->rowInt($row, 'running_balance_minor'),
            );
        }

        return [$lines, $total, $this->balanceBefore($till, $rows, $posted, $postedJournal, $filters['from'], $restrictToTellerId)];
    }

    /**
     * The balance the printed brouillard opens with: the till's position just
     * before the first visible row's movement. When that row opens its session,
     * the position is the declared opening — the counted drawer — so the
     * declaration is carried, not rewound.
     *
     * When the window is empty the position is the one the day before it would
     * have started: the running balance of the last operation preceding `from`,
     * and nil when the window opens at the beginning of the till's life, since
     * nothing precedes it. Reading the till's *end* balance instead would have
     * printed today's drawer at the head of a sheet covering 2021.
     *
     * The type filter deliberately plays no part here: the balance a brouillard
     * opens with is the till's, not the selected operation type's.
     *
     * @param  array<int, object>  $rows
     */
    private function balanceBefore(Till $till, array $rows, string $posted, string $postedJournal, ?string $from, ?int $restrictToTellerId): int
    {
        $firstRow = $rows[0] ?? null;
        if ($firstRow !== null) {
            return $this->rowInt($firstRow, 'running_balance_minor') - $this->settledMovement($firstRow, $posted, $postedJournal);
        }

        if ($from === null) {
            return 0;
        }

        $priorRow = DB::select(
            $this->baseSql($restrictToTellerId).' SELECT * FROM running WHERE transaction_date < :from ORDER BY transaction_date DESC, id DESC LIMIT 1',
            [...$this->baseBindings($till, $restrictToTellerId), 'from' => $from],
        )[0] ?? null;
        if ($priorRow === null) {
            return 0;
        }

        return $this->rowInt($priorRow, 'running_balance_minor');
    }

    /**
     * The shared query body: every settled or reversed cash movement of the
     * till, its journal-derived movement, and the running balance that resets
     * to each session's declared opening.
     */
    private function baseSql(?int $restrictToTellerId): string
    {
        $posted = TellerTransaction::STATUS_POSTED;
        $reversed = TellerTransaction::STATUS_REVERSED;
        $reversalType = TellerTransaction::TYPE_REVERSAL;
        $postedJournal = JournalEntry::STATUS_POSTED;
        $tellerCondition = $restrictToTellerId === null ? '' : ' AND s.teller_user_id = :tellerUserId';

        return <<<SQL
            WITH lines AS (
                SELECT
                    tt.id,
                    tt.public_id,
                    tt.reference,
                    tt.event_number,
                    tt.transaction_date,
                    tt.transaction_type,
                    tt.status,
                    tt.operation_code,
                    tt.currency,
                    tt.amount_minor,
                    tt.teller_session_id,
                    tt.description AS transaction_description,
                    s.public_id AS teller_session_public_id,
                    COALESCE(s.opening_declaration_minor, 0) AS opening_declaration_minor,
                    je.description AS journal_description,
                    je.status AS journal_status,
                    ca.account_number,
                    la.code AS ledger_account_code,
                    t.code AS till_code,
                    t.name AS till_name,
                    ag.code AS agency_code,
                    u.name AS teller_name,
                    COALESCE((
                        SELECT SUM(jl.debit_minor - jl.credit_minor)
                        FROM journal_lines jl
                        WHERE jl.journal_entry_id = tt.journal_entry_id
                          AND jl.ledger_account_id = t.ledger_account_id
                    ), 0) AS recorded_minor
                FROM teller_transactions tt
                JOIN teller_sessions s ON s.id = tt.teller_session_id
                JOIN tills t ON t.id = tt.till_id
                LEFT JOIN agencies ag ON ag.id = tt.agency_id
                LEFT JOIN users u ON u.id = s.teller_user_id
                LEFT JOIN customer_accounts ca ON ca.id = tt.customer_account_id
                LEFT JOIN ledger_accounts la ON la.id = ca.ledger_account_id
                LEFT JOIN journal_entries je ON je.id = tt.journal_entry_id
                WHERE tt.till_id = :tillId
                  AND tt.agency_id = :agencyId
                  AND tt.status IN ('{$posted}', '{$reversed}'){$tellerCondition}
            ),
            ranked AS (
                SELECT
                    l.*,
                    ROW_NUMBER() OVER (
                        PARTITION BY l.teller_session_id
                        ORDER BY l.transaction_date, l.id
                    ) AS session_rank
                FROM lines l
            ),
            running AS (
                SELECT
                    r.*,
                    SUM(
                        CASE WHEN r.session_rank = 1 THEN r.opening_declaration_minor ELSE 0 END
                        + CASE WHEN r.status = '{$posted}'
                                AND r.journal_status = '{$postedJournal}'
                                AND r.transaction_type <> '{$reversalType}'
                            THEN r.recorded_minor ELSE 0 END
                    ) OVER (
                        PARTITION BY r.teller_session_id
                        ORDER BY r.transaction_date, r.id
                        ROWS UNBOUNDED PRECEDING
                    ) AS running_balance_minor
                FROM ranked r
            )
            SQL;
    }

    /**
     * @return array<string, int|string>
     */
    private function baseBindings(Till $till, ?int $restrictToTellerId): array
    {
        $bindings = [
            'tillId' => $till->id,
            'agencyId' => $till->agency_id,
        ];

        if ($restrictToTellerId !== null) {
            $bindings['tellerUserId'] = $restrictToTellerId;
        }

        return $bindings;
    }

    /**
     * The movement a row actually makes on the drawer — the PHP mirror of the
     * running-balance CASE in {@see baseSql()}. The two must stay identical, or
     * a filtered brouillard's opening balance would be derived by one rule and
     * its rows carried forward by another.
     */
    private function settledMovement(object $row, string $posted, string $postedJournal): int
    {
        $settled = $this->rowString($row, 'status') === $posted
            && $this->rowString($row, 'journal_status') === $postedJournal
            && $this->rowString($row, 'transaction_type') !== TellerTransaction::TYPE_REVERSAL;

        return $settled ? $this->rowInt($row, 'recorded_minor') : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowPayload(object $row, int $sequence, int $recorded, int $running): array
    {
        $transactionType = $this->rowString($row, 'transaction_type');
        $label = $this->rowNullableString($row, 'transaction_description');
        // Reversal descriptions are generated by the workflow in English
        // ("Reversal of …"), not entered by a teller. Let the client fall back
        // to its localized operation label instead of printing that technical
        // description in the French cash journal.
        if ($transactionType === TellerTransaction::TYPE_REVERSAL
            && is_string($label)
            && str_starts_with($label, 'Reversal of ')) {
            $label = null;
        }

        return [
            'sequence_number' => $sequence,
            'reference' => $this->rowString($row, 'reference'),
            'event_number' => $this->rowNullableString($row, 'event_number'),
            'transaction_date' => $this->rowString($row, 'transaction_date'),
            'transaction_type' => $transactionType,
            'status' => $this->rowString($row, 'status'),
            'operation_code' => $this->rowNullableString($row, 'operation_code'),
            /*
             * The libellé is what the teller typed, and null when they typed
             * nothing — deliberately not falling back to the journal entry's
             * description, which in that case is a generated English string
             * ("Cash deposit ACC-001"). A French brouillard printed for the
             * accounting team must not carry it; the screen names the operation
             * in the reader's language instead. The journal's own wording still
             * travels, under its own key, for the entries where someone wrote it.
             */
            'label' => $label,
            'journal_description' => $this->rowNullableString($row, 'journal_description'),
            'amount_minor' => $this->rowInt($row, 'amount_minor'),
            'currency' => $this->rowString($row, 'currency'),
            'customer_account_number' => $this->rowNullableString($row, 'account_number'),
            'ledger_account_code' => $this->rowNullableString($row, 'ledger_account_code'),
            'cash_debit_minor' => $recorded > 0 ? $recorded : 0,
            'cash_credit_minor' => $recorded < 0 ? -$recorded : 0,
            'running_balance_minor' => $running,
            'agency_code' => $this->rowNullableString($row, 'agency_code'),
            'till_code' => $this->rowString($row, 'till_code'),
            'till_name' => $this->rowString($row, 'till_name'),
            'teller_name' => $this->rowNullableString($row, 'teller_name'),
            'teller_session_public_id' => $this->rowString($row, 'teller_session_public_id'),
            'public_id' => $this->rowString($row, 'public_id'),
        ];
    }

    private function rowString(object $row, string $key): string
    {
        $value = ((array) $row)[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = ((array) $row)[$key] ?? null;

        return $value === null ? null : (is_string($value) ? $value : (string) $value);
    }

    private function rowInt(object $row, string $key): int
    {
        $value = ((array) $row)[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }
}
