<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\StaffAgencyAssignment;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class StaffAssignmentPolicy
{
    public function viewAny(User $user, User $staffUser): bool
    {
        return $user->can('staff.assignments.view') && $this->canAccessStaffUser($user, $staffUser);
    }

    public function create(User $user, User $staffUser): bool
    {
        return $user->can('staff.assignments.manage') && $this->canAccessStaffUser($user, $staffUser);
    }

    public function update(User $user, User $staffUser, StaffAgencyAssignment $assignment): bool
    {
        if (! $user->can('staff.assignments.manage')) {
            return false;
        }

        if (! $this->canAccessStaffUser($user, $staffUser)) {
            return false;
        }

        if ($user->hasRole('platform-admin')) {
            return true;
        }

        $actorAgencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $actorAgencyId !== null && $assignment->agency_id === $actorAgencyId;
    }

    private function canAccessStaffUser(User $actor, User $target): bool
    {
        if ($actor->hasRole('platform-admin')) {
            return true;
        }

        $actorAgencyId = app(StaffAgencyScope::class)->currentAgencyId($actor);

        return $actorAgencyId !== null && app(StaffAgencyScope::class)->currentAgencyId($target) === $actorAgencyId;
    }
}
