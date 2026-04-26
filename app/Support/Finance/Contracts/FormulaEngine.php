<?php

declare(strict_types=1);

namespace App\Support\Finance\Contracts;

use App\Support\Finance\FormulaEngineKey;
use App\Support\Finance\FormulaPolicyKey;

interface FormulaEngine
{
    public function key(): FormulaEngineKey;

    public function requiredPolicy(): FormulaPolicyKey;
}
