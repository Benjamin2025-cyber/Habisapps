<?php

declare(strict_types=1);

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
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
        Scramble::ignoreDefaultRoutes();
        FormRequest::failOnUnknownFields();

        Route::get('/docs/api', function () {
            $specPath = public_path('docs/api.json');

            if (! File::exists($specPath)) {
                abort(503, 'API documentation is not generated yet.');
            }

            $spec = json_decode(File::get($specPath), true, 512, JSON_THROW_ON_ERROR);

            return view('scramble::docs', [
                'spec' => $spec,
                'config' => Scramble::getGeneratorConfig(Scramble::DEFAULT_API),
            ]);
        })->name('scramble.docs.ui');

        Route::get('/docs/api.json', function () {
            $specPath = public_path('docs/api.json');

            if (! File::exists($specPath)) {
                abort(503, 'API documentation is not generated yet.');
            }

            return response()->file($specPath, [
                'Content-Type' => 'application/json',
            ]);
        })->name('scramble.docs.document');

        RateLimiter::for('auth.login', function (Request $request): Limit {
            $maxAttempts = $this->integerConfig('security.auth.login.max_attempts', 5);
            $decayMinutes = $this->integerConfig('security.auth.login.decay_minutes', 1);

            return Limit::perMinute($maxAttempts, $decayMinutes)
                ->by((string) $request->ip());
        });

        RateLimiter::for('auth.register', function (Request $request): Limit {
            $maxAttempts = $this->integerConfig('security.auth.register.max_attempts', 3);
            $decayMinutes = $this->integerConfig('security.auth.register.decay_minutes', 1);

            return Limit::perMinute($maxAttempts, $decayMinutes)
                ->by((string) $request->ip());
        });

        RateLimiter::for('auth.activation', function (Request $request): Limit {
            $maxAttempts = $this->integerConfig('security.auth.activation.max_attempts', 5);
            $decayMinutes = $this->integerConfig('security.auth.activation.decay_minutes', 1);

            return Limit::perMinute($maxAttempts, $decayMinutes)
                ->by((string) $request->ip());
        });
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }
}
