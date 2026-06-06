<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Application\Dashboard\DashboardMetrics;
use App\Http\Controllers\BaseController;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LoanStatsWorkflow extends BaseController
{
    public function __construct(
        private readonly LoanListQuery $loanListQuery,
        private readonly DashboardMetrics $dashboardMetrics,
        private readonly LoanDelinquencyProjection $delinquencyProjection,
        private readonly LoanDisbursementReadiness $disbursementReadiness,
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || $actor->cannot('viewAny', Loan::class)) {
            return $this->respondForbidden();
        }

        $built = $this->loanListQuery->build($actor, $request);
        if ($built['error'] instanceof JsonResponse) {
            return $built['error'];
        }

        $loanIds = array_values($built['query']->pluck('id')
            ->filter(static fn (mixed $id): bool => is_numeric($id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all());

        $byStatus = $this->zeroFilledStatusCounts();
        if ($loanIds !== []) {
            $rows = (clone $built['query'])->toBase()->reorder()
                ->selectRaw('status, COUNT(*) AS row_count')
                ->groupBy('status')
                ->get();
            foreach ($rows as $row) {
                $status = (string) ($row->status ?? '');
                $count = is_numeric($row->row_count ?? null) ? (int) $row->row_count : 0;
                if ($status !== '' && array_key_exists($status, $byStatus)) {
                    $byStatus[$status] = $count;
                }
            }
        }

        $asOfDate = $built['as_of_date'];
        // Match the operational dashboard / loan in-arrears definition: arrears
        // and PAR counts are computed only over reportable (live) loans.
        $reportableIds = $this->dashboardMetrics->reportableLoanIdsWithin($loanIds);
        $delinquentIds = $this->dashboardMetrics->delinquentLoanIdsWithin($reportableIds, $asOfDate);
        $projections = $this->delinquencyProjection->forLoanIds($delinquentIds, $asOfDate);

        $parBuckets = ['par30' => 0, 'par60' => 0, 'par90' => 0];
        foreach ($projections as $projection) {
            $days = $projection['days_in_arrears'];
            if ($this->delinquencyProjection->matchesCumulativeParBucket($days, 30)) {
                $parBuckets['par30']++;
            }
            if ($this->delinquencyProjection->matchesCumulativeParBucket($days, 60)) {
                $parBuckets['par60']++;
            }
            if ($this->delinquencyProjection->matchesCumulativeParBucket($days, 90)) {
                $parBuckets['par90']++;
            }
        }

        $awaitingCount = count($this->disbursementReadiness->awaitingDisbursementIdsWithin($loanIds));

        return $this->respondSuccess([
            'by_status' => $byStatus,
            'in_arrears_count' => count($projections),
            'par_buckets' => $parBuckets,
            'awaiting_disbursement_count' => $awaitingCount,
        ], 'Loan statistics');
    }

    /**
     * @return array<string, int>
     */
    private function zeroFilledStatusCounts(): array
    {
        // Every loan status is zero-filled so by_status is a true partition of
        // the scoped total (sum(by_status) === total). Omitting rescheduled /
        // written_off would silently drop those loans from the breakdown.
        return [
            Loan::STATUS_APPLICATION => 0,
            Loan::STATUS_IN_REVIEW => 0,
            Loan::STATUS_APPROVED => 0,
            Loan::STATUS_DISBURSED => 0,
            Loan::STATUS_ACTIVE => 0,
            Loan::STATUS_RESCHEDULED => 0,
            Loan::STATUS_CLOSED => 0,
            Loan::STATUS_REJECTED => 0,
            Loan::STATUS_WRITTEN_OFF => 0,
        ];
    }
}
