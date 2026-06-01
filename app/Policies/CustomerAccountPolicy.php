<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerAccount;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class CustomerAccountPolicy
{
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

    private function isCurrentAgency(User $user, int $agencyId): bool
    {
        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $currentAgencyId !== null && $currentAgencyId === $agencyId;
    }
}
