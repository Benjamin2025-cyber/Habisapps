<?php

declare(strict_types=1);

namespace App\Application\BatchRuns;

use App\Models\BatchRun;
use App\Models\TellerTransaction;
use App\Models\TillReconciliation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ExecuteCashCloseVerificationBatch
{
    private const array SUPPORTED_PROCEDURE_CODES = [
        'cash_close_verification',
        'cash_daily_close',
        'agency_cash_close',
    ];

    public function execute(BatchRun $batchRun): BatchRun
    {
        $batchRun->loadMissing(['batchProcedure', 'agency', 'operator']);
        $procedureCode = $this->normalizedProcedureCode($batchRun);
        if (! in_array($procedureCode, self::SUPPORTED_PROCEDURE_CODES, true)) {
            throw new InvalidArgumentException('This batch procedure is not executable by the cash close verifier.');
        }

        if (! in_array($batchRun->status, [BatchRun::STATUS_PENDING, BatchRun::STATUS_FAILED], true)) {
            throw new InvalidArgumentException('Only pending or failed batch runs can be executed.');
        }

        $batchRun->forceFill([
            'status' => BatchRun::STATUS_RUNNING,
            'started_at' => $batchRun->started_at ?? now(),
            'finished_at' => null,
            'failure_reason' => null,
        ])->save();

        $summary = $this->summary($batchRun);
        $blocked = $summary['open_sessions'] > 0
            || $summary['pending_transactions'] > 0
            || $summary['unreconciled_closed_sessions'] > 0;

        $batchRun->forceFill([
            'status' => $blocked ? BatchRun::STATUS_FAILED : BatchRun::STATUS_SUCCEEDED,
            'summary_payload' => $summary,
            'failure_reason' => $blocked ? 'Cash close controls are not satisfied.' : null,
            'finished_at' => now(),
        ])->save();

        return $batchRun->refresh()->loadMissing(['batchProcedure', 'agency', 'operator']);
    }

    /**
     * @return array{procedure_code:string, business_date:string, agency_id:int|null, open_sessions:int, pending_transactions:int, unreconciled_closed_sessions:int}
     */
    private function summary(BatchRun $batchRun): array
    {
        $sessionScope = DB::table('teller_sessions')
            ->whereDate('business_date', $batchRun->business_date);

        if ($batchRun->agency_id !== null) {
            $sessionScope->where('agency_id', $batchRun->agency_id);
        }

        $openSessions = (clone $sessionScope)
            ->where('status', 'open')
            ->count();

        $sessionIds = (clone $sessionScope)
            ->pluck('id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $pendingTransactions = $sessionIds === []
            ? 0
            : DB::table('teller_transactions')
                ->whereIn('teller_session_id', $sessionIds)
                ->whereNotIn('status', [
                    TellerTransaction::STATUS_POSTED,
                    TellerTransaction::STATUS_REVERSED,
                    TellerTransaction::STATUS_CANCELLED,
                ])
                ->count();

        $unreconciledClosedSessions = (clone $sessionScope)
            ->where('status', 'closed')
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('till_reconciliations')
                    ->whereColumn('till_reconciliations.teller_session_id', 'teller_sessions.id')
                    ->where('till_reconciliations.status', TillReconciliation::STATUS_BALANCED)
                    ->where('till_reconciliations.difference_minor', 0);
            })
            ->count();

        return [
            'procedure_code' => $this->normalizedProcedureCode($batchRun),
            'business_date' => $batchRun->business_date,
            'agency_id' => $batchRun->agency_id,
            'open_sessions' => $openSessions,
            'pending_transactions' => $pendingTransactions,
            'unreconciled_closed_sessions' => $unreconciledClosedSessions,
        ];
    }

    private function normalizedProcedureCode(BatchRun $batchRun): string
    {
        $procedure = $batchRun->batchProcedure;
        $code = is_string($procedure?->code) ? $procedure->code : '';

        return strtolower(str_replace('-', '_', $code));
    }
}
