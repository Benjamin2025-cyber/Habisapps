<?php

declare(strict_types=1);

namespace App\Http\Actions;

use Closure;
use Illuminate\Support\Facades\DB;

abstract class BaseAction
{
    /** @var array<string, mixed> */
    protected array $context = [];

    abstract public function execute(): mixed;

    public static function make(): static
    {
        return app(static::class);
    }

    public function withContext(string $key, mixed $value): static
    {
        $this->context[$key] = $value;

        return $this;
    }

    protected function context(string $key, mixed $default = null): mixed
    {
        return $this->context[$key] ?? $default;
    }

    /** @param array<string, mixed> $context */
    public static function run(array $context = []): mixed
    {
        $instance = static::make();

        foreach ($context as $key => $value) {
            $instance->withContext($key, $value);
        }

        return $instance->execute();
    }

    protected function inTransaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }
}
