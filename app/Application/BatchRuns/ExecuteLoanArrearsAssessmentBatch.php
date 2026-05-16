<?php

declare(strict_types=1);

namespace App\Application\BatchRuns;

use App\Application\Loans\AssessLoanArrearsAndPenalties;
use App\Models\BatchRun;
use App\Models\Loan;
use App\Models\LoanScheduleSnapshot;
use App\Support\Finance\FormulaPolicyNotApproved;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class ExecuteLoanArrearsAssessmentBatch
{
    private const array SUPPORTED_PROCEDURE_CODES = [
        'loan_arrears_assessment',
        'loan_monthly_arrears_penalty',
    ];

    public function __construct(
        private readonly AssessLoanArrearsAndPenalties $assessLoanArrearsAndPenalties,
    ) {}

    public function execute(BatchRun $batchRun): BatchRun
    {
        $batchRun->loadMissing(['batchProcedure', 'agency', 'operator']);
        $procedureCode = $this->normalizedProcedureCode($batchRun);
        if (! in_array($procedureCode, self::SUPPORTED_PROCEDURE_CODES, true)) {
            throw new InvalidArgumentException('This batch procedure is not executable by the loan arrears assessor.');
        }

        if (! in_array($batchRun->status, [BatchRun::STATUS_PENDING, BatchRun::STATUS_FAILED], true)) {
            throw new InvalidArgumentException('Only pending or failed batch runs can be executed.');
        }

        $this->markRunning($batchRun);

        try {
            $summary = $this->assessLoans($batchRun, $procedureCode);
            $batchRun->forceFill([
                'status' => BatchRun::STATUS_SUCCEEDED,
                'summary_payload' => $summary,
                'failure_reason' => null,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $batchRun->forceFill([
                'status' => BatchRun::STATUS_FAILED,
                'failure_reason' => $exception->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }

        return $batchRun->refresh()->loadMissing(['batchProcedure', 'agency', 'operator']);
    }

    private function markRunning(BatchRun $batchRun): void
    {
        $batchRun->forceFill([
            'status' => BatchRun::STATUS_RUNNING,
            'started_at' => $batchRun->started_at ?? now(),
            'finished_at' => null,
            'failure_reason' => null,
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function assessLoans(BatchRun $batchRun, string $procedureCode): array
    {
        $assessedLoans = 0;
        $loansWithNewPenalties = 0;
        $assessedPenaltyMinor = 0;
        $arrearsRows = 0;
        $failedLoans = 0;
        $failures = [];

        $this->eligibleLoanQuery($batchRun)
            ->select('loans.id')
            ->whereExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('loan_schedule_snapshots')
                    ->whereColumn('loan_schedule_snapshots.loan_id', 'loans.id')
                    ->where('loan_schedule_snapshots.status', LoanScheduleSnapshot::STATUS_ACTIVE);
            })
            ->orderBy('id')
            ->chunkById(100, function ($loanRows) use (&$assessedLoans, &$loansWithNewPenalties, &$assessedPenaltyMinor, &$arrearsRows, &$failedLoans, &$failures, $batchRun): void {
                foreach ($loanRows as $loanRow) {
                    $loanId = $loanRow->id;
                    if (! is_int($loanId)) {
                        continue;
                    }

                    $loan = Loan::query()->whereKey($loanId)->first();
                    if (! $loan instanceof Loan) {
                        continue;
                    }

                    try {
                        $result = $this->assessLoanArrearsAndPenalties->handle($loan, $batchRun->business_date);
                    } catch (FormulaPolicyNotApproved $exception) {
                        throw $exception;
                    } catch (Throwable $exception) {
                        $failedLoans++;
                        if (count($failures) < 20) {
                            $failures[] = [
                                'loan_public_id' => $loan->public_id,
                                'reason' => $exception->getMessage(),
                            ];
                        }

                        continue;
                    }

                    $assessedLoans++;
                    $assessedPenaltyMinor += $result['assessed_penalty_minor'];
                    $arrearsRows += count($result['arrears']);
                    if ($result['assessed_penalty_minor'] > 0) {
                        $loansWithNewPenalties++;
                    }
                }
            });

        return [
            'procedure_code' => $procedureCode,
            'business_date' => $batchRun->business_date,
            'agency_id' => $batchRun->agency_id,
            'assessed_loans' => $assessedLoans,
            'loans_with_new_penalties' => $loansWithNewPenalties,
            'assessed_penalty_minor' => $assessedPenaltyMinor,
            'arrears_rows' => $arrearsRows,
            'failed_loans' => $failedLoans,
            'failures' => $failures,
            'skipped_without_active_schedule' => $this->countLoansWithoutActiveSchedule($batchRun),
        ];
    }

    private function eligibleLoanQuery(BatchRun $batchRun): Builder
    {
        $query = DB::table('loans')
            ->whereIn('status', [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED]);

        if ($batchRun->agency_id !== null) {
            $query->where('agency_id', $batchRun->agency_id);
        }

        return $query;
    }

    private function countLoansWithoutActiveSchedule(BatchRun $batchRun): int
    {
        return (clone $this->eligibleLoanQuery($batchRun))
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('loan_schedule_snapshots')
                    ->whereColumn('loan_schedule_snapshots.loan_id', 'loans.id')
                    ->where('loan_schedule_snapshots.status', LoanScheduleSnapshot::STATUS_ACTIVE);
            })
            ->count();
    }

    private function normalizedProcedureCode(BatchRun $batchRun): string
    {
        $procedure = $batchRun->batchProcedure;
        $code = is_string($procedure?->code) ? $procedure->code : '';

        return strtolower(str_replace('-', '_', $code));
    }
}
