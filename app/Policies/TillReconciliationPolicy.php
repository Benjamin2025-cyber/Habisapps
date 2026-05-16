<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TillReconciliation;
use App\Models\User;
use App\Support\Staff\StaffAgencyScope;

final class TillReconciliationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('cash.reconciliations.view');
    }

    public function view(User $user, TillReconciliation $tillReconciliation): bool
    {
        $session = $tillReconciliation->tellerSession;

        return $user->can('cash.reconciliations.view')
            && $session !== null
            && $this->canAccessAgency($user, $session->agency_id);
    }

    public function create(User $user): bool
    {
        return $user->can('cash.reconciliations.manage');
    }

    private function canAccessAgency(User $user, int $agencyId): bool
    {
        if ($user->hasRole('platform-admin')) {
            return true;
        }

        return app(StaffAgencyScope::class)->currentAgencyId($user) === $agencyId;
    }
}
