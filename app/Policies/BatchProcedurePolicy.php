<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BatchProcedure;
use App\Models\User;

final class BatchProcedurePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('batch.procedures.view') || $user->can('batch.procedures.manage');
    }

    public function view(User $user, BatchProcedure $batchProcedure): bool
    {
        return $user->can('batch.procedures.view') || $user->can('batch.procedures.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('batch.procedures.manage');
    }

    public function update(User $user, BatchProcedure $batchProcedure): bool
    {
        return $user->can('batch.procedures.manage');
    }

    public function updateStatus(User $user, BatchProcedure $batchProcedure): bool
    {
        return $user->can('batch.procedures.manage');
    }
}
