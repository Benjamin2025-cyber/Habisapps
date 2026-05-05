<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Client;
use App\Models\ClientGuarantor;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class ClientGuarantorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.guarantors.view');
    }

    public function view(User $user, ClientGuarantor $guarantor): bool
    {
        return $user->can('crm.guarantors.view') && $this->canReadInScope($user, $guarantor->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->can('crm.guarantors.create');
    }

    public function createForClient(User $user, Client $client): bool
    {
        return $user->can('crm.guarantors.create') && $this->canManageInScope($user, $client->agency_id);
    }

    public function update(User $user, ClientGuarantor $guarantor): bool
    {
        return $user->can('crm.guarantors.update') && $this->canManageInScope($user, $guarantor->agency_id);
    }

    public function archive(User $user, ClientGuarantor $guarantor): bool
    {
        return $user->can('crm.guarantors.archive') && $this->canManageInScope($user, $guarantor->agency_id);
    }

    public function verify(User $user, ClientGuarantor $guarantor): bool
    {
        return $user->can('crm.guarantors.verify') && $this->canReviewInScope($user, $guarantor->agency_id);
    }

    public function reject(User $user, ClientGuarantor $guarantor): bool
    {
        return $user->can('crm.guarantors.reject') && $this->canReviewInScope($user, $guarantor->agency_id);
    }

    private function canReadInScope(User $user, int $agencyId): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        if ($user->can('crm.scope.institution.read')
            || $user->can('crm.scope.institution.review')
            || $user->can('crm.scope.institution.manage')) {
            return true;
        }

        return $this->isCurrentAgency($user, $agencyId);
    }

    private function canReviewInScope(User $user, int $agencyId): bool
    {
        if ($user->hasRole('platform-admin') || $user->can('crm.scope.institution.review') || $user->can('crm.scope.institution.manage')) {
            return true;
        }

        return $this->isCurrentAgency($user, $agencyId);
    }

    private function canManageInScope(User $user, int $agencyId): bool
    {
        if ($user->hasRole('platform-admin') || $user->can('crm.scope.institution.manage')) {
            return true;
        }

        return $this->isCurrentAgency($user, $agencyId);
    }

    private function isCurrentAgency(User $user, int $agencyId): bool
    {
        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $currentAgencyId !== null && $currentAgencyId === $agencyId;
    }
}
