<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('audit.view');
    }
}
