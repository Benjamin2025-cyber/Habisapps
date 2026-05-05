<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BatchRun;
use App\Models\User;

final class BatchRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('batch.runs.view') || $user->can('batch.runs.manage');
    }

    public function view(User $user, BatchRun $batchRun): bool
    {
        return $user->can('batch.runs.view') || $user->can('batch.runs.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('batch.runs.manage');
    }

    public function updateStatus(User $user, BatchRun $batchRun): bool
    {
        return $user->can('batch.runs.manage');
    }
}
