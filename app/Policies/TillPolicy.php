<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Till;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class TillPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cash.tills.view');
    }

    public function view(User $user, Till $till): bool
    {
        return $user->can('cash.tills.view') && $this->canAccessAgency($user, $till->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->can('cash.tills.manage');
    }

    public function update(User $user, Till $till): bool
    {
        return $user->can('cash.tills.manage') && $this->canAccessAgency($user, $till->agency_id);
    }

    public function delete(User $user, Till $till): bool
    {
        return false;
    }

    private function canAccessAgency(User $user, int $agencyId): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $agencyId;
    }
}
