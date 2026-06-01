<?php

declare(strict_types=1);

namespace App\Application\BatchRuns;

use App\Models\BatchRun;
use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ExecuteAccountingCloseVerificationBatch
{
    private const array SUPPORTED_PROCEDURE_CODES = [
        'accounting_close_verification',
        'accounting_daily_close',
        'journal_close_verification',
    ];

    public function execute(BatchRun $batchRun): BatchRun
    {
        $batchRun->loadMissing(['batchProcedure', 'agency', 'operator']);
        $procedureCode = $this->normalizedProcedureCode($batchRun);
        if (! in_array($procedureCode, self::SUPPORTED_PROCEDURE_CODES, true)) {
            throw new InvalidArgumentException('This batch procedure is not executable by the accounting close verifier.');
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

        $summary = $this->summary($batchRun, $procedureCode);
        $blocked = $summary['blocking_journals'] > 0;

        $batchRun->forceFill([
            'status' => $blocked ? BatchRun::STATUS_FAILED : BatchRun::STATUS_SUCCEEDED,
            'summary_payload' => $summary,
            'failure_reason' => $blocked ? 'Accounting close controls are not satisfied.' : null,
            'finished_at' => now(),
        ])->save();

        $this->syncAccountingDayCloseSummary($batchRun, $summary);

        return $batchRun->refresh()->loadMissing(['batchProcedure', 'agency', 'operator']);
    }

    /**
     * @return array{procedure_code:string, business_date:string, agency_id:int|null, blocking_journals:int, blocking_status_counts:array<string, int>}
     */
    private function summary(BatchRun $batchRun, string $procedureCode): array
    {
        $blockingStatuses = [
            JournalEntry::STATUS_DRAFT,
            JournalEntry::STATUS_SUBMITTED,
            JournalEntry::STATUS_APPROVED,
        ];

        $query = DB::table('journal_entries')
            ->whereDate('business_date', $batchRun->business_date)
            ->whereIn('status', $blockingStatuses);

        if ($batchRun->agency_id === null) {
            $query->whereNull('agency_id');
        } else {
            $query->where('agency_id', $batchRun->agency_id);
        }

        $statusCounts = [];
        foreach ($blockingStatuses as $status) {
            $statusCounts[$status] = (clone $query)->where('status', $status)->count();
        }

        return [
            'procedure_code' => $procedureCode,
            'business_date' => $batchRun->business_date,
            'agency_id' => $batchRun->agency_id,
            'blocking_journals' => array_sum($statusCounts),
            'blocking_status_counts' => $statusCounts,
        ];
    }

    private function normalizedProcedureCode(BatchRun $batchRun): string
    {
        $procedure = $batchRun->batchProcedure;
        $code = is_string($procedure?->code) ? $procedure->code : '';

        return strtolower(str_replace('-', '_', $code));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function syncAccountingDayCloseSummary(BatchRun $batchRun, array $summary): void
    {
        if (! is_int($batchRun->accounting_day_id)) {
            return;
        }

        $day = DB::table('accounting_days')
            ->where('id', $batchRun->accounting_day_id)
            ->first(['close_summary_payload']);

        $existingSummary = [];
        if ($day !== null && is_string($day->close_summary_payload) && $day->close_summary_payload !== '') {
            $decoded = json_decode($day->close_summary_payload, true);
            if (is_array($decoded)) {
                $existingSummary = $decoded;
            }
        }

        $controls = [];
        $rawControls = $existingSummary['close_control_batches'] ?? [];
        if (is_array($rawControls)) {
            $controls = $rawControls;
        }

        $code = $this->normalizedProcedureCode($batchRun);
        $controls[$code] = [
            'batch_run_public_id' => $batchRun->public_id,
            'status' => $batchRun->status,
            'summary_payload' => $summary,
            'failure_reason' => $batchRun->failure_reason,
            'finished_at' => $batchRun->finished_at?->toAtomString(),
        ];

        $existingSummary['close_control_batches'] = $controls;
        DB::table('accounting_days')
            ->where('id', $batchRun->accounting_day_id)
            ->update([
                'close_summary_payload' => json_encode($existingSummary),
                'updated_at' => now(),
            ]);
    }
}
