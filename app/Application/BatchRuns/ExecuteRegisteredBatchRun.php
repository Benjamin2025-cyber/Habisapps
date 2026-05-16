<?php

declare(strict_types=1);

namespace App\Application\BatchRuns;

use App\Models\BatchProcedure;
use App\Models\BatchRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ExecuteRegisteredBatchRun
{
    private const array LOAN_ARREARS_PROCEDURE_CODES = [
        'loan_arrears_assessment',
        'loan_monthly_arrears_penalty',
    ];

    private const array CASH_CLOSE_PROCEDURE_CODES = [
        'cash_close_verification',
        'cash_daily_close',
        'agency_cash_close',
    ];

    private const array ACCOUNTING_CLOSE_PROCEDURE_CODES = [
        'accounting_close_verification',
        'accounting_daily_close',
        'journal_close_verification',
    ];

    public function __construct(
        private readonly ExecuteLoanArrearsAssessmentBatch $executeLoanArrearsAssessmentBatch,
        private readonly ExecuteCashCloseVerificationBatch $executeCashCloseVerificationBatch,
        private readonly ExecuteAccountingCloseVerificationBatch $executeAccountingCloseVerificationBatch,
        private readonly ExecuteLoanServicingHooksBatch $executeLoanServicingHooksBatch,
    ) {}

    public function execute(BatchRun $batchRun): BatchRun
    {
        $batchRun->loadMissing(['batchProcedure', 'agency', 'operator']);
        $procedure = $batchRun->batchProcedure;

        if ($procedure === null) {
            throw new InvalidArgumentException('Batch procedure is missing.');
        }

        if ($procedure->status !== BatchProcedure::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Inactive batch procedures cannot be executed.');
        }

        $procedureCode = $this->normalizedProcedureCode($batchRun);
        $this->guardSupportedProcedure($procedureCode);
        $this->guardNoRunningBatchInScope($batchRun);
        $this->guardPrerequisitesSatisfied($batchRun);

        if (in_array($procedureCode, self::LOAN_ARREARS_PROCEDURE_CODES, true)) {
            return $this->executeLoanArrearsAssessmentBatch->execute($batchRun);
        }

        if (in_array($procedureCode, self::CASH_CLOSE_PROCEDURE_CODES, true)) {
            return $this->executeCashCloseVerificationBatch->execute($batchRun);
        }

        if ($this->executeLoanServicingHooksBatch->supports($procedureCode)) {
            return $this->executeLoanServicingHooksBatch->execute($batchRun);
        }

        return $this->executeAccountingCloseVerificationBatch->execute($batchRun);
    }

    private function guardSupportedProcedure(string $procedureCode): void
    {
        if (
            ! in_array($procedureCode, self::LOAN_ARREARS_PROCEDURE_CODES, true)
            && ! in_array($procedureCode, self::CASH_CLOSE_PROCEDURE_CODES, true)
            && ! in_array($procedureCode, self::ACCOUNTING_CLOSE_PROCEDURE_CODES, true)
            && ! $this->executeLoanServicingHooksBatch->supports($procedureCode)
        ) {
            throw new InvalidArgumentException('This batch procedure is not executable.');
        }
    }

    private function guardNoRunningBatchInScope(BatchRun $batchRun): void
    {
        $runningQuery = DB::table('batch_runs')
            ->where('batch_procedure_id', $batchRun->batch_procedure_id)
            ->whereDate('business_date', $batchRun->business_date)
            ->where('status', BatchRun::STATUS_RUNNING)
            ->where('id', '<>', $batchRun->id);

        if ($batchRun->agency_id === null) {
            $runningQuery->whereNull('agency_id');
        } else {
            $runningQuery->where('agency_id', $batchRun->agency_id);
        }

        if ($runningQuery->exists()) {
            throw new InvalidArgumentException('A batch run is already executing for this procedure, agency, and business date.');
        }
    }

    private function guardPrerequisitesSatisfied(BatchRun $batchRun): void
    {
        $prerequisiteCodes = $this->prerequisiteProcedureCodes($batchRun);
        if ($prerequisiteCodes === []) {
            return;
        }

        $missing = [];
        $incomplete = [];
        $failed = [];

        foreach ($prerequisiteCodes as $code) {
            $procedureId = DB::table('batch_procedures')
                ->whereRaw('LOWER(REPLACE(code, ?, ?)) = ?', ['-', '_', $code])
                ->value('id');

            if (! is_int($procedureId)) {
                $missing[] = $code;

                continue;
            }

            $runQuery = DB::table('batch_runs')
                ->where('batch_procedure_id', $procedureId)
                ->whereDate('business_date', $batchRun->business_date);

            if ($batchRun->agency_id === null) {
                $runQuery->whereNull('agency_id');
            } else {
                $runQuery->where('agency_id', $batchRun->agency_id);
            }

            $status = $runQuery
                ->latest('id')
                ->value('status');

            if ($status === BatchRun::STATUS_SUCCEEDED) {
                continue;
            }

            if ($status === BatchRun::STATUS_FAILED) {
                $failed[] = $code;

                continue;
            }

            $incomplete[] = [
                'procedure_code' => $code,
                'status' => is_string($status) ? $status : 'missing_run',
            ];
        }

        if ($missing === [] && $incomplete === [] && $failed === []) {
            return;
        }

        $summary = [
            'dependency_status' => 'blocked',
            'missing_prerequisites' => $missing,
            'incomplete_prerequisites' => $incomplete,
            'failed_prerequisites' => $failed,
        ];

        $batchRun->forceFill([
            'status' => BatchRun::STATUS_FAILED,
            'summary_payload' => $summary,
            'failure_reason' => 'Batch prerequisites are not satisfied.',
            'finished_at' => now(),
        ])->save();

        throw new InvalidArgumentException('Batch prerequisites are not satisfied.');
    }

    /**
     * @return list<string>
     */
    private function prerequisiteProcedureCodes(BatchRun $batchRun): array
    {
        $metadata = $batchRun->batchProcedure?->schedule_metadata;
        if (! is_array($metadata)) {
            return [];
        }

        $rawCodes = $metadata['prerequisite_procedure_codes'] ?? $metadata['prerequisites'] ?? [];
        if (! is_array($rawCodes)) {
            return [];
        }

        $codes = [];
        foreach ($rawCodes as $rawCode) {
            if (! is_string($rawCode) || trim($rawCode) === '') {
                continue;
            }

            $codes[] = strtolower(str_replace('-', '_', trim($rawCode)));
        }

        return array_values(array_unique($codes));
    }

    private function normalizedProcedureCode(BatchRun $batchRun): string
    {
        $procedure = $batchRun->batchProcedure;
        $code = is_string($procedure?->code) ? $procedure->code : '';

        return strtolower(str_replace('-', '_', $code));
    }
}
