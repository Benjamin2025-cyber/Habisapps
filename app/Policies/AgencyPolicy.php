<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class AgencyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('agencies.view') || $user->can('agencies.manage');
    }

    public function view(User $user, Agency $agency): bool
    {
        if (! $user->can('agencies.view')) {
            return false;
        }

        return $this->canViewAgency($user, $agency);
    }

    public function create(User $user): bool
    {
        return $user->can('agencies.manage');
    }

    public function update(User $user, Agency $agency): bool
    {
        return $user->can('agencies.manage');
    }

    public function updateStatus(User $user, Agency $agency): bool
    {
        return $user->can('agencies.manage');
    }

    public function delete(User $user, Agency $agency): bool
    {
        return $user->can('agencies.manage');
    }

    public function updateManager(User $user, Agency $agency): bool
    {
        return $user->can('agencies.manage');
    }

    private function canViewAgency(User $user, Agency $agency): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        $currentAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $currentAgencyId !== null && $currentAgencyId === $agency->id;
    }
}
