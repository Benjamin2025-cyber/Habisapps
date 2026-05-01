<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClientProxy;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class ClientProxyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.proxies.view');
    }

    public function view(User $user, ClientProxy $proxy): bool
    {
        return $user->can('crm.proxies.view') && $this->canReadInScope($user, $proxy->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->can('crm.proxies.create');
    }

    public function update(User $user, ClientProxy $proxy): bool
    {
        return $user->can('crm.proxies.update') && $this->canManageInScope($user, $proxy->agency_id);
    }

    public function archive(User $user, ClientProxy $proxy): bool
    {
        return $user->can('crm.proxies.archive') && $this->canManageInScope($user, $proxy->agency_id);
    }

    public function verify(User $user, ClientProxy $proxy): bool
    {
        return $user->can('crm.proxies.verify') && $this->canReviewInScope($user, $proxy->agency_id);
    }

    public function reject(User $user, ClientProxy $proxy): bool
    {
        return $user->can('crm.proxies.reject') && $this->canReviewInScope($user, $proxy->agency_id);
    }

    public function expire(User $user, ClientProxy $proxy): bool
    {
        return $user->can('crm.proxies.expire') && $this->canReviewInScope($user, $proxy->agency_id);
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
