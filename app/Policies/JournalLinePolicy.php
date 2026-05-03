<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\JournalLine;
use App\Models\User;

final class JournalLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function view(User $user, JournalLine $journalLine): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function update(User $user, JournalLine $journalLine): bool
    {
        return $user->hasRole('platform-admin');
    }

    public function delete(User $user, JournalLine $journalLine): bool
    {
        return $user->hasRole('platform-admin');
    }
}
