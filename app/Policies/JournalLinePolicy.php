<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\JournalLine;
use App\Models\User;
use App\Support\AccountingDay\AccountingScopeAccess;
use App\Support\Staff\StaffAgencyScope;

/**
 * A journal line belongs to the agency of its entry, so access follows the same
 * rule as the entry: head office (institution accounting authority) works in any
 * agency's books, everyone else only in their own.
 *
 * This policy used to answer `hasRole('platform-admin')` to everything, ignoring
 * the `journal.lines.*` permissions entirely — which made those permissions
 * unusable for any other role, `chief-accountant` included.
 */
final class JournalLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('journal.lines.view');
    }

    public function view(User $user, JournalLine $journalLine): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('journal.lines.view') && $this->sameBooks($user, $journalLine));
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can('journal.lines.create');
    }

    public function update(User $user, JournalLine $journalLine): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('journal.lines.update') && $this->sameBooks($user, $journalLine));
    }

    public function delete(User $user, JournalLine $journalLine): bool
    {
        return $user->hasRole('platform-admin')
            || ($user->can('journal.lines.archive') && $this->sameBooks($user, $journalLine));
    }

    private function sameBooks(User $user, JournalLine $journalLine): bool
    {
        if (app(AccountingScopeAccess::class)->canManageInstitutionScope($user)) {
            return true;
        }

        $agencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $agencyId !== null && $agencyId === $journalLine->agency_id;
    }
}
