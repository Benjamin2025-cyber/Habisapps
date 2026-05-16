<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

final class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('journal.entries.view');
    }

    public function view(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('journal.entries.create');
    }

    public function update(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.update');
    }

    public function delete(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.archive');
    }

    public function submit(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.create') || $user->hasPermissionTo('journal.entries.update');
    }

    public function approve(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.review');
    }

    public function reject(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.review');
    }

    public function post(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.post');
    }

    public function reverse(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasPermissionTo('journal.entries.reverse');
    }
}
