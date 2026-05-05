<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Agency;
use App\Models\BatchProcedure;
use App\Models\BatchRun;
use App\Models\Document;
use App\Models\StaffAgencyAssignment;
use App\Models\User;
use App\Policies\AgencyPolicy;
use App\Policies\AuditEventPolicy;
use App\Policies\BatchProcedurePolicy;
use App\Policies\BatchRunPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\RolePolicy;
use App\Policies\StaffAssignmentPolicy;
use App\Policies\UserPolicy;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

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
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, AuditEventPolicy::class);
        Gate::policy(Agency::class, AgencyPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(StaffAgencyAssignment::class, StaffAssignmentPolicy::class);
        Gate::policy(BatchProcedure::class, BatchProcedurePolicy::class);
        Gate::policy(BatchRun::class, BatchRunPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);

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

        RateLimiter::for('document.upload', fn (Request $request): Limit => Limit::perMinute(
            $this->integerConfig('security.rate_limits.document_upload.max_attempts', 30),
            $this->integerConfig('security.rate_limits.document_upload.decay_minutes', 1),
        )->by($this->rateLimitKey($request)));

        RateLimiter::for('client.create', fn (Request $request): Limit => Limit::perMinute(
            $this->integerConfig('security.rate_limits.client_create.max_attempts', 30),
            $this->integerConfig('security.rate_limits.client_create.decay_minutes', 1),
        )->by($this->rateLimitKey($request)));

        RateLimiter::for('journal.write', fn (Request $request): Limit => Limit::perMinute(
            $this->integerConfig('security.rate_limits.journal_write.max_attempts', 60),
            $this->integerConfig('security.rate_limits.journal_write.decay_minutes', 1),
        )->by($this->rateLimitKey($request)));

        RateLimiter::for('audit.browse', fn (Request $request): Limit => Limit::perMinute(
            $this->integerConfig('security.rate_limits.audit_browse.max_attempts', 120),
            $this->integerConfig('security.rate_limits.audit_browse.decay_minutes', 1),
        )->by($this->rateLimitKey($request)));

        RateLimiter::for('reference.reserve', fn (Request $request): Limit => Limit::perMinute(
            $this->integerConfig('security.rate_limits.reference_reserve.max_attempts', 60),
            $this->integerConfig('security.rate_limits.reference_reserve.decay_minutes', 1),
        )->by($this->rateLimitKey($request)));
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) ? $value : $default;
    }

    private function rateLimitKey(Request $request): string
    {
        $user = $request->user();

        return $user instanceof User && $user->public_id !== ''
            ? 'user:'.$user->public_id
            : 'ip:'.((string) $request->ip());
    }
}
