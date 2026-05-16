<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OperationCode;
use App\Models\User;

final class OperationCodePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.codes.view');
    }

    public function view(User $user, OperationCode $operationCode): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.codes.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.codes.create');
    }

    public function update(User $user, OperationCode $operationCode): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.codes.update');
    }

    public function delete(User $user, OperationCode $operationCode): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.codes.archive');
    }
}
