<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerAccount;
use App\Models\CustomerAccountSignature;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class CustomerAccountSignaturePolicy
{
    public function viewAny(User $user, CustomerAccount $customerAccount): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('customer.account-signatures.view') && $this->isCurrentAgency($user, $customerAccount->agency_id));
    }

    public function view(User $user, CustomerAccountSignature $signature): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('customer.account-signatures.view') && $this->isCurrentAgency($user, $signature->agency_id));
    }

    public function create(User $user, CustomerAccount $customerAccount): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('customer.account-signatures.create') && $this->isCurrentAgency($user, $customerAccount->agency_id));
    }

    public function verify(User $user, CustomerAccountSignature $signature): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('customer.account-signatures.verify') && $this->isCurrentAgency($user, $signature->agency_id));
    }

    public function revoke(User $user, CustomerAccountSignature $signature): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('customer.account-signatures.revoke') && $this->isCurrentAgency($user, $signature->agency_id));
    }

    private function isCurrentAgency(User $user, int $agencyId): bool
    {
        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $currentAgencyId !== null && $currentAgencyId === $agencyId;
    }
}
