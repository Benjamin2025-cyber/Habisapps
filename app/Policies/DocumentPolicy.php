<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('documents.view');
    }

    public function view(User $user, Document $document): bool
    {
        if (! ($user->hasRole('platform-admin') || $user->can('documents.view'))) {
            return false;
        }

        // Platform/institution-scoped actors can reach any agency's documents,
        // mirroring the upload agency-resolution model so a document they
        // uploaded for a selected agency can also be shown/downloaded (AIR-003).
        return $this->canReadAnyAgency($user) || $this->isCurrentAgency($user, $document->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('documents.create');
    }

    public function archive(User $user, Document $document): bool
    {
        if (! ($user->hasRole('platform-admin') || $user->can('documents.archive'))) {
            return false;
        }

        return $this->canManageAnyAgency($user) || $this->isCurrentAgency($user, $document->agency_id);
    }

    private function canReadAnyAgency(User $user): bool
    {
        return $user->hasRole('platform-admin')
            || $user->can('crm.scope.institution.read')
            || $user->can('crm.scope.institution.review')
            || $user->can('crm.scope.institution.manage');
    }

    private function canManageAnyAgency(User $user): bool
    {
        return $user->hasRole('platform-admin')
            || $user->can('crm.scope.institution.manage');
    }

    private function isCurrentAgency(User $user, int $agencyId): bool
    {
        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $currentAgencyId !== null && $currentAgencyId === $agencyId;
    }
}
