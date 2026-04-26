<?php

declare(strict_types=1);

namespace App\Support\Finance;

final class FormulaPolicyRegistry
{
    public function isApproved(FormulaPolicyKey $policy): bool
    {
        $value = config('formulas.policies.'.$policy->value.'.approved', false);

        return $value === true;
    }

    public function requireApproved(FormulaPolicyKey $policy): void
    {
        if (! $this->isApproved($policy)) {
            throw FormulaPolicyNotApproved::forPolicy($policy);
        }
    }
}
