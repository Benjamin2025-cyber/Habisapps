<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\AccountingDays\AccountingDayWorkflow;
use App\Application\BatchRuns\ExecuteRegisteredBatchRun;
use App\Models\BatchProcedure;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds the batch procedures a fresh environment needs so that executable batch
 * code has matching active `batch_procedures` rows to call.
 *
 * The accounting-day close workflow ({@see AccountingDayWorkflow::executeCloseControlRuns()})
 * hard-requires active procedures for `accounting_close_verification` and
 * `cash_close_verification`; without them a fresh install records
 * `missing_procedure` and can never close a day.
 *
 * Coverage of {@see ExecuteRegisteredBatchRun}:
 *  - This seeder seeds one canonical procedure per distinct executor (accounting
 *    close, cash close, loan arrears assessment, monthly arrears penalty, and a
 *    loan-servicing report hook).
 *  - The remaining codes accepted by the executor (`cash_daily_close`,
 *    `agency_cash_close`, `accounting_daily_close`, `journal_close_verification`,
 *    `credit_portfolio_report_hook`, `portfolio_report_generation`,
 *    `loan_servicing_notification_hook`, `loan_notifications_hook`,
 *    `credit_notification_hook`) are alternate identifiers that route to the same
 *    executors via code normalization. They are intentionally not seeded as
 *    duplicate rows; a deployment that wants those specific codes can add them
 *    without new application code.
 */
final class BatchProcedureSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::procedures() as $procedure) {
            // Match on the normalized code so repeated runs (and any pre-existing
            // hyphen/underscore/case variant) update the same logical procedure
            // rather than creating a duplicate.
            $normalized = strtolower(str_replace('-', '_', $procedure['code']));

            $query = BatchProcedure::query();
            $query->getQuery()->whereRaw('LOWER(REPLACE(code, ?, ?)) = ?', ['-', '_', $normalized]);
            $existing = $query->first();

            if ($existing instanceof BatchProcedure) {
                $existing->fill([
                    'name' => $procedure['name'],
                    'description' => $procedure['description'],
                    'schedule_type' => $procedure['schedule_type'],
                    'schedule_metadata' => $procedure['schedule_metadata'],
                    'status' => BatchProcedure::STATUS_ACTIVE,
                ])->save();

                continue;
            }

            BatchProcedure::query()->create([
                'public_id' => (string) Str::ulid(),
                'code' => $procedure['code'],
                'name' => $procedure['name'],
                'description' => $procedure['description'],
                'schedule_type' => $procedure['schedule_type'],
                'schedule_metadata' => $procedure['schedule_metadata'],
                'status' => BatchProcedure::STATUS_ACTIVE,
            ]);
        }
    }

    /**
     * Canonical seeded procedures. Codes are stored in normalized
     * (lower-snake-case) form to match the executor and the close workflow.
     *
     * @return list<array{code: string, name: string, description: string, schedule_type: string, schedule_metadata: array<string, mixed>}>
     */
    private static function procedures(): array
    {
        return [
            [
                'code' => 'accounting_close_verification',
                'name' => 'Accounting Close Verification',
                'description' => 'Verifies that no unposted journal entries remain before an accounting day can close.',
                'schedule_type' => 'manual',
                'schedule_metadata' => ['execution_priority' => 10],
            ],
            [
                'code' => 'cash_close_verification',
                'name' => 'Cash Close Verification',
                'description' => 'Verifies that teller sessions are closed and reconciled and no cash transactions remain pending before an accounting day can close.',
                'schedule_type' => 'manual',
                'schedule_metadata' => ['execution_priority' => 20],
            ],
            [
                'code' => 'loan_arrears_assessment',
                'name' => 'Loan Arrears Assessment',
                'description' => 'Assesses loan arrears against the active repayment schedule.',
                'schedule_type' => 'daily',
                'schedule_metadata' => ['execution_priority' => 30],
            ],
            [
                'code' => 'loan_monthly_arrears_penalty',
                'name' => 'Monthly Arrears Penalty',
                'description' => 'Applies the monthly arrears penalty to overdue loan installments.',
                'schedule_type' => 'monthly',
                'schedule_metadata' => ['execution_priority' => 40],
            ],
            [
                'code' => 'loan_portfolio_report_hook',
                'name' => 'Loan Portfolio Report Hook',
                'description' => 'Generates loan-servicing portfolio reporting outputs as part of end-of-day hooks.',
                'schedule_type' => 'daily',
                'schedule_metadata' => ['execution_priority' => 50],
            ],
        ];
    }
}
