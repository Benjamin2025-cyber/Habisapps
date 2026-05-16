<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LoanProduct;
use App\Models\User;

final class LoanProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loan.products.view');
    }

    public function view(User $user, LoanProduct $loanProduct): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loan.products.view');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loan.products.create');
    }

    public function update(User $user, LoanProduct $loanProduct): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loan.products.update');
    }

    public function delete(User $user, LoanProduct $loanProduct): bool
    {
        return $user->hasRole('platform-admin') || $user->hasPermissionTo('loan.products.archive');
    }
}
