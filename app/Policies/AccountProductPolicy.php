<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AccountProduct;
use App\Models\User;

final class AccountProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('account.products.view');
    }

    public function view(User $user, AccountProduct $accountProduct): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('account.products.view') && $this->canReadInScope($user, $accountProduct));
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('account.products.create');
    }

    public function update(User $user, AccountProduct $accountProduct): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('account.products.update') && $this->canManageInScope($user, $accountProduct));
    }

    public function delete(User $user, AccountProduct $accountProduct): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('account.products.archive') && $this->canManageInScope($user, $accountProduct));
    }

    private function canReadInScope(User $user, AccountProduct $accountProduct): bool
    {
        return $accountProduct->agency_id === null || $user->currentAgencyId() === $accountProduct->agency_id;
    }

    private function canManageInScope(User $user, AccountProduct $accountProduct): bool
    {
        return $accountProduct->agency_id !== null && $user->currentAgencyId() === $accountProduct->agency_id;
    }
}
