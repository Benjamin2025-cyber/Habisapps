<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClientIdentityDocument;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class ClientIdentityDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('crm.identity_documents.view');
    }

    public function view(User $user, ClientIdentityDocument $document): bool
    {
        return $user->can('crm.identity_documents.view') && $this->canReadInScope($user, $document->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->can('crm.identity_documents.create');
    }

    public function update(User $user, ClientIdentityDocument $document): bool
    {
        return $user->can('crm.identity_documents.update') && $this->canManageInScope($user, $document->agency_id);
    }

    public function archive(User $user, ClientIdentityDocument $document): bool
    {
        return $user->can('crm.identity_documents.archive') && $this->canManageInScope($user, $document->agency_id);
    }

    public function verify(User $user, ClientIdentityDocument $document): bool
    {
        return $user->can('crm.identity_documents.verify') && $this->canReviewInScope($user, $document->agency_id);
    }

    public function reject(User $user, ClientIdentityDocument $document): bool
    {
        return $user->can('crm.identity_documents.reject') && $this->canReviewInScope($user, $document->agency_id);
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
