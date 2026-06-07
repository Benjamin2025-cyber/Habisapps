<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\AccountingCalendarDay;
use App\Models\AccountingDay;
use App\Models\AccountProduct;
use App\Models\Agency;
use App\Models\BatchProcedure;
use App\Models\BatchRun;
use App\Models\CustomerAccount;
use App\Models\CustomerAccountSignature;
use App\Models\Denomination;
use App\Models\Document;
use App\Models\EmfLedgerAccountMapping;
use App\Models\EmfRegulatoryAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\OperationAccountMapping;
use App\Models\OperationCode;
use App\Models\StaffAgencyAssignment;
use App\Models\Till;
use App\Models\User;
use App\Policies\AccountingCalendarDayPolicy;
use App\Policies\AccountingDayPolicy;
use App\Policies\AccountProductPolicy;
use App\Policies\AgencyPolicy;
use App\Policies\AuditEventPolicy;
use App\Policies\BatchProcedurePolicy;
use App\Policies\BatchRunPolicy;
use App\Policies\CustomerAccountPolicy;
use App\Policies\CustomerAccountSignaturePolicy;
use App\Policies\DenominationPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\EmfLedgerAccountMappingPolicy;
use App\Policies\EmfRegulatoryAccountPolicy;
use App\Policies\LoanPolicy;
use App\Policies\LoanProductPolicy;
use App\Policies\OperationAccountMappingPolicy;
use App\Policies\OperationCodePolicy;
use App\Policies\RolePolicy;
use App\Policies\StaffAssignmentPolicy;
use App\Policies\TillPolicy;
use App\Policies\UserPolicy;
use App\Support\DatabaseManagement\Contracts\DatabaseBackupRunner;
use App\Support\DatabaseManagement\Contracts\DatabaseRestoreRunner;
use App\Support\DatabaseManagement\NativePostgresBackupRunner;
use App\Support\DatabaseManagement\NativePostgresRestoreRunner;
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
        $this->app->bind(
            DatabaseBackupRunner::class,
            NativePostgresBackupRunner::class,
        );

        $this->app->bind(
            DatabaseRestoreRunner::class,
            NativePostgresRestoreRunner::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(Activity::class, AuditEventPolicy::class);
        Gate::policy(Agency::class, AgencyPolicy::class);
        Gate::policy(AccountProduct::class, AccountProductPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(StaffAgencyAssignment::class, StaffAssignmentPolicy::class);
        Gate::policy(BatchProcedure::class, BatchProcedurePolicy::class);
        Gate::policy(BatchRun::class, BatchRunPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(CustomerAccount::class, CustomerAccountPolicy::class);
        Gate::policy(CustomerAccountSignature::class, CustomerAccountSignaturePolicy::class);
        Gate::policy(EmfLedgerAccountMapping::class, EmfLedgerAccountMappingPolicy::class);
        Gate::policy(EmfRegulatoryAccount::class, EmfRegulatoryAccountPolicy::class);
        Gate::policy(LoanProduct::class, LoanProductPolicy::class);
        Gate::policy(Loan::class, LoanPolicy::class);
        Gate::policy(OperationAccountMapping::class, OperationAccountMappingPolicy::class);
        Gate::policy(OperationCode::class, OperationCodePolicy::class);
        Gate::policy(Denomination::class, DenominationPolicy::class);
        Gate::policy(Till::class, TillPolicy::class);
        Gate::policy(AccountingDay::class, AccountingDayPolicy::class);
        Gate::policy(AccountingCalendarDay::class, AccountingCalendarDayPolicy::class);

        Scramble::ignoreDefaultRoutes();
        FormRequest::failOnUnknownFields();

        Route::get('/docs/api', function () {
            $specPath = public_path('docs/api.json');

            if (! File::exists($specPath)) {
                abort(503, __('api.documentation_not_generated'));
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
                abort(503, __('api.documentation_not_generated'));
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
                ->by($this->authVictimRateLimitKey($request));
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

        RateLimiter::for('accounting.lifecycle', fn (Request $request): Limit => Limit::perMinute(
            $this->integerConfig('security.rate_limits.accounting_lifecycle.max_attempts', 30),
            $this->integerConfig('security.rate_limits.accounting_lifecycle.decay_minutes', 1),
        )->by($this->rateLimitKey($request)));

        RateLimiter::for('media.storage.status', fn (Request $request): Limit => Limit::perMinute(
            $this->integerConfig('security.rate_limits.media_storage_status.max_attempts', 60),
            $this->integerConfig('security.rate_limits.media_storage_status.decay_minutes', 1),
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

    private function authVictimRateLimitKey(Request $request): string
    {
        $victim = $request->input('phone_number', $request->input('email'));
        $victimKey = is_string($victim) && $victim !== ''
            ? hash('sha256', mb_strtolower(trim($victim)))
            : 'anonymous';

        return 'ip:'.((string) $request->ip()).'|victim:'.$victimKey;
    }
}
