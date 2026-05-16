<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TellerSession;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class TellerSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cash.sessions.view');
    }

    public function view(User $user, TellerSession $tellerSession): bool
    {
        return $user->can('cash.sessions.view') && $this->canAccessAgency($user, $tellerSession->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->can('cash.sessions.manage');
    }

    public function close(User $user, TellerSession $tellerSession): bool
    {
        return $user->can('cash.sessions.manage') && $this->canAccessAgency($user, $tellerSession->agency_id);
    }

    private function canAccessAgency(User $user, int $agencyId): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $agencyId;
    }
}
