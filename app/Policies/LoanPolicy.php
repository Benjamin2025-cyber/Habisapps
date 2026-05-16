<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Loan;
use App\Models\User;

final class LoanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loans.view');
    }

    public function view(User $user, Loan $loan): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loans.create');
    }

    public function update(User $user, Loan $loan): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loans.update');
    }
}
