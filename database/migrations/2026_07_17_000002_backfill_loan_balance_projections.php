<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('loans')
            ->whereNotNull('disbursed_on')
            ->orderBy('id')
            ->chunkById(100, function ($loans): void {
                foreach ($loans as $loan) {
                    $componentTotals = DB::table('loan_repayment_allocations')
                        ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
                        ->where('loan_repayments.loan_id', $loan->id)
                        ->where('loan_repayments.status', 'posted')
                        ->groupBy('loan_repayment_allocations.component')
                        ->selectRaw('loan_repayment_allocations.component, SUM(loan_repayment_allocations.amount_minor) AS total_minor')
                        ->pluck('total_minor', 'component');

                    $principal = (int) ($loan->approved_principal_minor ?? $loan->requested_amount_minor ?? 0);
                    $principalRepaid = (int) ($componentTotals['principal'] ?? 0);
                    $interestRepaid = (int) ($componentTotals['interest'] ?? 0);
                    $penaltiesPaid = (int) ($componentTotals['penalty'] ?? 0);
                    $outstandingPrincipal = max(0, $principal - $principalRepaid);

                    $snapshotId = DB::table('loan_schedule_snapshots')
                        ->where('loan_id', $loan->id)
                        ->where('status', 'active')
                        ->latest('id')
                        ->value('id');
                    $scheduleTotal = 0;
                    $installmentAmount = null;
                    $nextRepaymentDate = null;
                    if (is_numeric($snapshotId)) {
                        $lines = DB::table('loan_schedule_lines')
                            ->where('loan_schedule_snapshot_id', (int) $snapshotId);
                        $scheduleTotal = (int) (clone $lines)->sum('total_installment_minor');
                        $firstLine = (clone $lines)->orderBy('installment_number')->first(['total_installment_minor']);
                        $installmentAmount = is_object($firstLine) ? (int) $firstLine->total_installment_minor : null;
                        $nextRepaymentDate = (clone $lines)
                            ->whereDate('due_date', '>=', now()->toDateString())
                            ->orderBy('due_date')
                            ->value('due_date');
                    }

                    $allRepaid = array_sum(array_map(static fn (mixed $value): int => (int) $value, $componentTotals->all()));
                    $globalOutstanding = $scheduleTotal > 0
                        ? max(0, $scheduleTotal - $allRepaid)
                        : $outstandingPrincipal;

                    DB::table('loans')->where('id', $loan->id)->update([
                        'outstanding_principal_minor' => $outstandingPrincipal,
                        'installment_amount_minor' => $installmentAmount,
                        'total_principal_repaid_minor' => $principalRepaid,
                        'total_interest_repaid_minor' => $interestRepaid,
                        'total_penalties_paid_minor' => $penaltiesPaid,
                        'global_outstanding_amount_minor' => $globalOutstanding,
                        'next_repayment_date' => $nextRepaymentDate,
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Projection values may have changed through live repayments after this
        // migration, so clearing them on rollback would destroy valid state.
    }
};
