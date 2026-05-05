<?php

declare(strict_types=1);

namespace App\Application\BatchRuns;

use App\Models\BatchRun;
use Illuminate\Support\Facades\DB;

final class UpdateBatchRunStatus
{
    /**
     * @param  array<string, mixed>  $updates
     */
    public function execute(BatchRun $batchRun, array $updates): BatchRun
    {
        return DB::transaction(function () use ($batchRun, $updates): BatchRun {
            $batchRun->forceFill($updates)->save();

            return $batchRun->refresh()->loadMissing(['batchProcedure', 'agency', 'operator']);
        });
    }
}
