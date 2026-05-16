<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\BatchRun;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class BatchRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('batch.runs.view') || $user->can('batch.runs.manage');
    }

    public function view(User $user, BatchRun $batchRun): bool
    {
        if (! $user->can('batch.runs.view') && ! $user->can('batch.runs.manage')) {
            return false;
        }

        if ($user->can('batch.runs.manage')) {
            return true;
        }

        if ($batchRun->agency_id === null) {
            return false;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $batchRun->agency_id;
    }

    public function create(User $user): bool
    {
        return $user->can('batch.runs.manage');
    }

    public function updateStatus(User $user, BatchRun $batchRun): bool
    {
        return $user->can('batch.runs.manage');
    }

    public function execute(User $user, BatchRun $batchRun): bool
    {
        return $user->can('batch.runs.manage');
    }

    public function retry(User $user, BatchRun $batchRun): bool
    {
        return $user->can('batch.runs.manage');
    }

    public function cancel(User $user, BatchRun $batchRun): bool
    {
        return $user->can('batch.runs.manage');
    }
}
