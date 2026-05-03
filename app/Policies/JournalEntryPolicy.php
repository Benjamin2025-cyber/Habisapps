<?php

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

final class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function view(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function update(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function delete(User $user, JournalEntry $journalEntry): bool
    {
        return $user->hasRole('platform-admin');
    }
}
