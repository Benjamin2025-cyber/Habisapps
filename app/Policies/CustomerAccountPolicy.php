<?php

namespace App\Policies;

use App\Models\CustomerAccount;
use App\Models\User;

final class CustomerAccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function view(User $user, CustomerAccount $customerAccount): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function update(User $user, CustomerAccount $customerAccount): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function delete(User $user, CustomerAccount $customerAccount): bool
    {
        return $user->hasRole('platform-admin');
    }
}
