<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.clients.view');
    }

    public function view(User $user, Client $client): bool
    {
        return $user->can('crm.clients.view') && $this->canReadInScope($user, $client->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->can('crm.clients.create');
    }

    public function update(User $user, Client $client): bool
    {
        return $user->can('crm.clients.update') && $this->canManageInScope($user, $client->agency_id);
    }

    public function archive(User $user, Client $client): bool
    {
        return $user->can('crm.clients.archive') && $this->canManageInScope($user, $client->agency_id);
    }

    public function submitKyc(User $user, Client $client): bool
    {
        return $user->can('crm.kyc.submit') && $this->canManageInScope($user, $client->agency_id);
    }

    public function verifyKyc(User $user, Client $client): bool
    {
        return $user->can('crm.kyc.verify') && $this->canReviewInScope($user, $client->agency_id);
    }

    public function rejectKyc(User $user, Client $client): bool
    {
        return $user->can('crm.kyc.reject') && $this->canReviewInScope($user, $client->agency_id);
    }

    public function suspendKyc(User $user, Client $client): bool
    {
        return $user->can('crm.kyc.review') && $this->canReviewInScope($user, $client->agency_id);
    }

    public function viewKycReviews(User $user, Client $client): bool
    {
        return $user->can('crm.reviews.view') && $this->canReadInScope($user, $client->agency_id);
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
