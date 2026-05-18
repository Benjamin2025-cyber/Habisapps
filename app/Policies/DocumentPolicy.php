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
        return $user->hasRole('platform-admin')
            || ($user->can('documents.view') && $this->isCurrentAgency($user, $document->agency_id));
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('documents.create');
    }

    public function archive(User $user, Document $document): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('documents.archive') && $this->isCurrentAgency($user, $document->agency_id));
    }

    private function isCurrentAgency(User $user, int $agencyId): bool
    {
        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $currentAgencyId !== null && $currentAgencyId === $agencyId;
    }
}
