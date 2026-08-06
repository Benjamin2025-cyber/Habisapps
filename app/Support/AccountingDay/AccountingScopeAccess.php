<?php

declare(strict_types=1);

namespace App\Support\AccountingDay;

use App\Models\User;

/**
 * Who may act on the institution's own accounting period.
 *
 * The institution-scoped accounting day and calendar were originally reserved
 * for platform administrators — the vendor. But opening and closing the
 * institution's accounting period is the head-office accounting job (the
 * arrêté comptable), so the reservation is expressed as a permission rather
 * than as a hard role check, and `chief-accountant` holds it.
 *
 * The rule lives here because four call sites need exactly the same answer:
 * listing days, resolving the scope of a write, and the two scope policies.
 * Reopening a closed period is deliberately *not* covered — that still needs
 * `accounting.days.reopen`, which only platform-admin holds.
 */
final class AccountingScopeAccess
{
    public const string INSTITUTION_MANAGE = 'accounting.scope.institution.manage';

    public function canManageInstitutionScope(User $user): bool
    {
        return $user->hasRole('platform-admin') || $user->can(self::INSTITUTION_MANAGE);
    }
}
