<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerAccount;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class CustomerAccountPolicy
{
    /*
     * Each method pairs the platform-admin bypass with the permission it is
     * named for, the way the rest of the app does. These returned the role alone,
     * so a permission could be granted, seeded and shown in the role editor and
     * still refuse the request — the grant looked applied and did nothing.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('customer.accounts.view');
    }

    public function view(User $user, CustomerAccount $customerAccount): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('customer.accounts.view') && $this->isCurrentAgency($user, $customerAccount->agency_id));
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('customer.accounts.create');
    }

    public function update(User $user, CustomerAccount $customerAccount): bool
    {
        return $user->hasRole('platform-admin') || $user->can('customer.accounts.update');
    }

    public function delete(User $user, CustomerAccount $customerAccount): bool
    {
        return $user->hasRole('platform-admin') || $user->can('customer.accounts.close');
    }

    private function isCurrentAgency(User $user, int $agencyId): bool
    {
        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $currentAgencyId !== null && $currentAgencyId === $agencyId;
    }
}
