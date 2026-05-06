<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Denomination;
use App\Models\User;

final class DenominationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cash.denominations.view');
    }

    public function view(User $user, Denomination $denomination): bool
    {
        return $user->can('cash.denominations.view');
    }

    public function create(User $user): bool
    {
        return $user->can('cash.denominations.manage');
    }

    public function update(User $user, Denomination $denomination): bool
    {
        return $user->can('cash.denominations.manage');
    }

    public function delete(User $user, Denomination $denomination): bool
    {
        return false;
    }
}
