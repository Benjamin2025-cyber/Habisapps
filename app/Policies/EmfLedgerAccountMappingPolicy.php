<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\EmfLedgerAccountMapping;
use App\Models\User;

final class EmfLedgerAccountMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.mappings.view');
    }

    public function view(User $user, EmfLedgerAccountMapping $mapping): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.mappings.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.mappings.create');
    }

    public function update(User $user, EmfLedgerAccountMapping $mapping): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.mappings.update');
    }

    public function delete(User $user, EmfLedgerAccountMapping $mapping): bool
    {
        return $user->hasRole('platform-admin') || $user->can('emf.mappings.archive');
    }
}
