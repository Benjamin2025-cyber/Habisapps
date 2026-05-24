<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\IslamicFinance\IslamicApprovalStateMachine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconciliation pass for IF-011 cutover.
 *
 * The approval workflow is the source of truth for new-use eligibility.
 * Legacy subject `status` columns are mirrors during the transition window.
 * This command reports drift and, with --fix, repairs mirror columns to
 * match the workflow's current_state.
 */
final class ReconcileIslamicApprovalStatuses extends Command
{
    protected $signature = 'islamic:approval-workflow:reconcile-statuses
        {--fix : Update legacy subject status columns to match workflow state}';

    protected $description = 'Report (and optionally repair) drift between Islamic approval workflows and legacy subject status mirrors.';

    /**
     * Mapping of workflow current_state -> legacy subject status mirror value.
     *
     * @var array<string, string>
     */
    private const PRODUCT_STATE_MIRROR = [
        IslamicApprovalStateMachine::STATE_DRAFT => 'draft',
        IslamicApprovalStateMachine::STATE_SUBMITTED => 'draft',
        IslamicApprovalStateMachine::STATE_APPROVED => 'approved',
        IslamicApprovalStateMachine::STATE_REJECTED => 'draft',
    ];

    public function handle(): int
    {
        $fix = $this->option('fix') === true;

        $mismatchCount = 0;
        $mismatchCount += $this->reconcileProducts($fix);

        if ($mismatchCount === 0) {
            $this->info('Islamic approval workflow mirrors are consistent.');

            return self::SUCCESS;
        }

        $this->warn($mismatchCount.' approval workflow mirror mismatch(es) '.($fix ? 'repaired.' : 'detected. Re-run with --fix to repair.'));

        return $fix ? self::SUCCESS : self::FAILURE;
    }

    private function reconcileProducts(bool $fix): int
    {
        $rows = DB::table('islamic_approval_workflows as w')
            ->join('islamic_products as p', 'p.public_id', '=', 'w.subject_public_id')
            ->where('w.subject_type', IslamicApprovalStateMachine::SUBJECT_PRODUCT)
            ->select([
                'p.id as product_id',
                'p.public_id as product_public_id',
                'p.status as legacy_status',
                'w.current_state as workflow_state',
            ])
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $workflowState = is_string($row->workflow_state) ? $row->workflow_state : '';
            $legacyStatus = is_string($row->legacy_status) ? $row->legacy_status : '';
            $expected = self::PRODUCT_STATE_MIRROR[$workflowState] ?? null;
            if ($expected === null) {
                continue;
            }
            if ($expected === $legacyStatus) {
                continue;
            }

            $count++;
            $this->line(sprintf(
                'mismatch: islamic_product %s legacy=%s workflow=%s expected_mirror=%s',
                is_string($row->product_public_id) ? $row->product_public_id : '?',
                $legacyStatus,
                $workflowState,
                $expected,
            ));

            if ($fix) {
                DB::table('islamic_products')
                    ->where('id', $row->product_id)
                    ->update(['status' => $expected, 'updated_at' => now()]);
            }
        }

        return $count;
    }
}
