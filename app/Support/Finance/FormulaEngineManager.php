<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Support\Finance\Contracts\FormulaEngine;
use App\Support\Finance\Engines\UnavailableFormulaEngine;
use InvalidArgumentException;

final readonly class FormulaEngineManager
{
    public function __construct(private FormulaPolicyRegistry $policies) {}

    public function engine(FormulaEngineKey $key): FormulaEngine
    {
        $driver = $this->driverName($key);

        if ($driver === 'unavailable') {
            return new UnavailableFormulaEngine($key, $this->policies);
        }

        $className = config('formulas.drivers.'.$driver);

        if (! is_string($className) || ! class_exists($className)) {
            throw new InvalidArgumentException(sprintf('Formula driver [%s] is not registered.', $driver));
        }

        $engine = app($className);

        if (! $engine instanceof FormulaEngine) {
            throw new InvalidArgumentException(sprintf('Formula driver [%s] must implement %s.', $driver, FormulaEngine::class));
        }

        if ($engine->key() !== $key) {
            throw new InvalidArgumentException(sprintf('Formula driver [%s] does not serve engine [%s].', $driver, $key->value));
        }

        $this->policies->requireApproved($engine->requiredPolicy());

        return $engine;
    }

    private function driverName(FormulaEngineKey $key): string
    {
        $driver = config('formulas.engines.'.$key->value, 'unavailable');

        return is_string($driver) && $driver !== '' ? $driver : 'unavailable';
    }
}
