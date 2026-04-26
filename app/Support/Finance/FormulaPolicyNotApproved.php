<?php

declare(strict_types=1);

namespace App\Support\Finance;

use RuntimeException;

final class FormulaPolicyNotApproved extends RuntimeException
{
    public static function forPolicy(FormulaPolicyKey $policy): self
    {
        return new self(sprintf(
            'Formula policy [%s] is not approved. Stakeholder sign-off is required before implementing or executing this calculation.',
            $policy->value
        ));
    }
}
