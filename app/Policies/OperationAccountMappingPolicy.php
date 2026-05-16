<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OperationAccountMapping;
use App\Models\User;

final class OperationAccountMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.mappings.view');
    }

    public function view(User $user, OperationAccountMapping $mapping): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.mappings.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.mappings.create');
    }

    public function update(User $user, OperationAccountMapping $mapping): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.mappings.update');
    }

    public function delete(User $user, OperationAccountMapping $mapping): bool
    {
        return $user->hasRole('platform-admin') || $user->can('operation.mappings.archive');
    }
}
