<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\StoreTillReconciliationRequest;
use App\Http\Resources\TillReconciliationResource;
use App\Models\Denomination;
use App\Models\JournalEntry;
use App\Models\TellerSession;
use App\Models\TellerTransaction;
use App\Models\Till;
use App\Models\TillReconciliation;
use App\Models\TillReconciliationLine;
use App\Models\User;
use App\Support\Security\SecurityAudit;
use App\Support\Staff\StaffAgencyScope;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TillReconciliationController extends BaseController
{
    public function __construct(
        private readonly SecurityAudit $securityAudit,
        private readonly StaffAgencyScope $staffAgencyScope,
    ) {}

    #[Response(status: 200, type: 'array{success: bool, message: string, data: array{till_reconciliations: array<int, \App\Http\Resources\TillReconciliationResource>}, errors: null, meta: null}')]
    public function index(Request $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', TillReconciliation::class) || ! $this->canAccessSession($actor, $tellerSession)) {
            return $this->respondForbidden();
        }

        $items = TillReconciliation::query()
            ->with(['tellerSession', 'countedBy', 'lines.denomination'])
            ->where('teller_session_id', $tellerSession->id)
            ->latest()
            ->get();

        return $this->respondSuccess([
            'till_reconciliations' => TillReconciliationResource::collection($items),
        ]);
    }

    #[Response(status: 201, type: 'array{success: bool, message: string, data: array{till_reconciliation: \App\Http\Resources\TillReconciliationResource}, errors: null, meta: null}')]
    public function store(StoreTillReconciliationRequest $request, TellerSession $tellerSession): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->canAccessSession($actor, $tellerSession)) {
            return $this->respondForbidden();
        }

        $tellerSession->loadMissing(['till']);
        $till = $tellerSession->till;
        if (! $till instanceof Till) {
            return $this->respondUnprocessable(errors: ['till' => ['The teller session must be linked to a valid till.']]);
        }

        if ($this->hasPendingTransactions($tellerSession->id)) {
            return $this->respondUnprocessable(errors: ['transactions' => ['Pending teller transactions must be posted or cancelled before reconciliation.']]);
        }

        $currency = $this->normalizedCurrency($request->input('currency', $tellerSession->currency ?? $till->currency));
        if ($currency !== $tellerSession->currency) {
            return $this->respondUnprocessable(errors: ['currency' => ['Reconciliation currency must match the teller session currency.']]);
        }

        $counts = $this->validatedDenominationCounts($request->input('denomination_counts'), $currency);
        if ($counts['errors'] !== []) {
            return $this->respondUnprocessable(errors: $counts['errors']);
        }

        $actualBalanceMinor = array_sum(array_column($counts['lines'], 'declared_amount_minor'));
        $theoreticalBalanceMinor = $this->theoreticalBalanceMinor($tellerSession, $till);
        $differenceMinor = $actualBalanceMinor - $theoreticalBalanceMinor;
        if ($differenceMinor !== 0) {
            return $this->respondUnprocessable(errors: ['difference_minor' => ['Reconciliation difference must be zero before it can be recorded.']]);
        }

        $reconciliation = DB::transaction(function () use ($request, $actor, $tellerSession, $currency, $counts, $actualBalanceMinor, $theoreticalBalanceMinor, $differenceMinor): TillReconciliation {
            $record = TillReconciliation::query()->create([
                'public_id' => (string) Str::ulid(),
                'teller_session_id' => $tellerSession->id,
                'counted_by_user_id' => $actor->id,
                'counted_at' => now(),
                'reconciliation_date' => now(),
                'theoretical_balance_minor' => $theoreticalBalanceMinor,
                'actual_balance_minor' => $actualBalanceMinor,
                'difference_minor' => $differenceMinor,
                'currency' => $currency,
                'status' => TillReconciliation::STATUS_BALANCED,
                'notes' => $request->input('notes'),
            ]);

            foreach ($counts['lines'] as $line) {
                TillReconciliationLine::query()->create([
                    'till_reconciliation_id' => $record->id,
                    'denomination_id' => $line['denomination_id'],
                    'count' => $line['count'],
                    'declared_amount_minor' => $line['declared_amount_minor'],
                ]);
            }

            return $record;
        });

        $this->securityAudit->record('cash.till_reconciliation.balanced', actor: $actor, subject: $reconciliation, properties: [
            'teller_session_public_id' => $tellerSession->public_id,
            'actual_balance_minor' => $actualBalanceMinor,
            'theoretical_balance_minor' => $theoreticalBalanceMinor,
            'currency' => $currency,
        ], request: $request);

        return $this->respondCreated(
            TillReconciliationResource::make($reconciliation->loadMissing(['tellerSession', 'countedBy', 'lines.denomination'])),
            'Till reconciliation recorded successfully'
        );
    }

    private function canAccessSession(User $actor, TellerSession $session): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        return $this->staffAgencyScope->currentAgencyId($actor) === $session->agency_id;
    }

    private function hasPendingTransactions(int $tellerSessionId): bool
    {
        return DB::table('teller_transactions')
            ->where('teller_session_id', $tellerSessionId)
            ->whereNotIn('status', [TellerTransaction::STATUS_POSTED, TellerTransaction::STATUS_REVERSED, TellerTransaction::STATUS_CANCELLED])
            ->first(['id']) !== null;
    }

    private function theoreticalBalanceMinor(TellerSession $session, Till $till): int
    {
        $opening = $session->opening_declaration_minor ?? 0;
        $transactions = DB::table('teller_transactions')
            ->where('teller_session_id', $session->id)
            ->where('status', TellerTransaction::STATUS_POSTED)
            ->get(['id', 'transaction_type', 'amount_minor', 'journal_entry_id']);

        $movement = 0;
        foreach ($transactions as $transaction) {
            $type = is_string($transaction->transaction_type) ? $transaction->transaction_type : '';
            $amount = is_numeric($transaction->amount_minor) ? (int) $transaction->amount_minor : 0;

            if ($type === TellerTransaction::TYPE_CASH_DEPOSIT) {
                $movement += $amount;

                continue;
            }

            if ($type === TellerTransaction::TYPE_CASH_WITHDRAWAL) {
                $movement -= $amount;

                continue;
            }

            if ($type === TellerTransaction::TYPE_MANUAL_JOURNAL && is_numeric($transaction->journal_entry_id)) {
                $movement += $this->journalCashMovement((int) $transaction->journal_entry_id, $till->ledger_account_id);
            }
        }

        return $opening + $movement;
    }

    private function journalCashMovement(int $journalEntryId, ?int $tillLedgerAccountId): int
    {
        if ($tillLedgerAccountId === null) {
            return 0;
        }

        $line = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.status', JournalEntry::STATUS_POSTED)
            ->where('journal_lines.journal_entry_id', $journalEntryId)
            ->where('journal_lines.ledger_account_id', $tillLedgerAccountId)
            ->selectRaw('COALESCE(SUM(journal_lines.debit_minor - journal_lines.credit_minor), 0) AS movement_minor')
            ->first();

        return is_object($line) && is_numeric($line->movement_minor) ? (int) $line->movement_minor : 0;
    }

    /**
     * @return array{errors: array<string, array<int, string>>, lines: array<int, array{denomination_id:int, denomination_public_id:string, count:int, declared_amount_minor:int}>}
     */
    private function validatedDenominationCounts(mixed $rawCounts, string $currency): array
    {
        if (! is_array($rawCounts)) {
            return ['errors' => ['denomination_counts' => ['Denomination counts are required.']], 'lines' => []];
        }

        $seen = [];
        $lines = [];
        foreach ($rawCounts as $index => $line) {
            if (! is_array($line)) {
                return ['errors' => ['denomination_counts.'.$index => ['Each denomination count must be an object.']], 'lines' => []];
            }

            $publicId = $line['denomination_public_id'] ?? null;
            $count = $line['count'] ?? null;
            if (! is_string($publicId) || ! is_int($count)) {
                return ['errors' => ['denomination_counts.'.$index => ['Each denomination count must include a denomination and integer count.']], 'lines' => []];
            }

            if (array_key_exists($publicId, $seen)) {
                return ['errors' => ['denomination_counts' => ['Duplicate denominations are not allowed.']], 'lines' => []];
            }
            $seen[$publicId] = true;

            $denomination = Denomination::query()->where('public_id', $publicId)->first();
            if (! $denomination instanceof Denomination || $denomination->status !== Denomination::STATUS_ACTIVE || $denomination->currency !== $currency) {
                return ['errors' => ['denomination_counts.'.$index.'.denomination_public_id' => ['The selected denomination must be active and match the reconciliation currency.']], 'lines' => []];
            }

            $lines[] = [
                'denomination_id' => $denomination->id,
                'denomination_public_id' => $denomination->public_id,
                'count' => $count,
                'declared_amount_minor' => $denomination->value_minor * $count,
            ];
        }

        return ['errors' => [], 'lines' => $lines];
    }

    private function normalizedCurrency(mixed $currency): string
    {
        return is_string($currency) && $currency !== '' ? strtoupper($currency) : 'XAF';
    }
}
