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

    /*
     * Both scopes admit the institution-wide holder as well as the agency's own
     * staff. Without that the chef comptable — who carries no agency assignment,
     * deliberately, because head office belongs to no branch — matched no agency
     * product at all. He owns this catalogue and is the only role that may write
     * it, yet he could create a product for an agency and then never edit it
     * again: create carries no scope test, update does. The index already reads
     * institution scope this way, so the policy was the odd one out.
     */
    private function canReadInScope(User $user, AccountProduct $accountProduct): bool
    {
        return $accountProduct->agency_id === null
            || $user->can('ledger.scope.institution.read')
            || $user->currentAgencyId() === $accountProduct->agency_id;
    }

    private function canManageInScope(User $user, AccountProduct $accountProduct): bool
    {
        if ($user->can('ledger.scope.institution.manage')) {
            return true;
        }

        return $accountProduct->agency_id !== null && $user->currentAgencyId() === $accountProduct->agency_id;
    }
}
