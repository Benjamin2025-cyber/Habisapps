<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FormRequest::failOnUnknownFields();

        RateLimiter::for('auth.login', function (Request $request): Limit {
            $maxAttempts = $this->integerConfig('security.auth.login.max_attempts', 5);
            $decayMinutes = $this->integerConfig('security.auth.login.decay_minutes', 1);
            $email = strtolower($this->stringInput($request, 'email', 'guest'));

            return Limit::perMinute($maxAttempts, $decayMinutes)
                ->by($email.'|'.$request->ip());
        });

        RateLimiter::for('auth.register', function (Request $request): Limit {
            $maxAttempts = $this->integerConfig('security.auth.register.max_attempts', 3);
            $decayMinutes = $this->integerConfig('security.auth.register.decay_minutes', 1);

            return Limit::perMinute($maxAttempts, $decayMinutes)
                ->by((string) $request->ip());
        });
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }

    private function stringInput(Request $request, string $key, string $default): string
    {
        $value = $request->input($key, $default);

        return is_string($value) ? $value : $default;
    }
}
