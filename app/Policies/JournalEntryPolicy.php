<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;
use App\Support\AccountingDay\AccountingScopeAccess;
use App\Support\Staff\StaffAgencyScope;

/**
 * Entries belong to the agency where the financial event occurred. Head office
 * (institution accounting authority) works in any agency's books — it validates
 * and consolidates for the whole institution; an agency actor is confined to its
 * own, matching what JournalEntryListQuery::applyActorScope already returns.
 */
final class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('journal.entries.view');
    }

    public function view(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.view') && $this->sameBooks($user, $journalEntry);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('journal.entries.create');
    }

    public function update(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.update') && $this->sameBooks($user, $journalEntry);
    }

    public function delete(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.archive') && $this->sameBooks($user, $journalEntry);
    }

    public function submit(User $user, JournalEntry $journalEntry): bool
    {
        return ($user->hasPermissionTo('journal.entries.create') || $user->hasPermissionTo('journal.entries.update'))
            && $this->sameBooks($user, $journalEntry);
    }

    public function approve(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.review') && $this->sameBooks($user, $journalEntry);
    }

    public function reject(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.review') && $this->sameBooks($user, $journalEntry);
    }

    public function post(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.post') && $this->sameBooks($user, $journalEntry);
    }

    public function reverse(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.reverse') && $this->sameBooks($user, $journalEntry);
    }

    /**
     * Whether the actor may act on the books this entry belongs to.
     */
    private function sameBooks(User $user, JournalEntry $journalEntry): bool
    {
        if ($user->hasRole('platform-admin') || app(AccountingScopeAccess::class)->canManageInstitutionScope($user)) {
            return true;
        }

        $agencyId = app(StaffAgencyScope::class)->currentAgencyId($user);

        return $agencyId !== null && $agencyId === $journalEntry->agency_id;
    }
}
