<?php

declare(strict_types=1);

use App\Http\Middleware\ApiVersion;
use App\Http\Middleware\EnforceAccountingDayRegistrationLock;
use App\Http\Middleware\EnforceDatabaseRestoreLock;
use App\Http\Middleware\IdempotencyMiddleware;
use App\Http\Middleware\RemoveServerDisclosureHeaders;
use App\Http\Middleware\SetApiLocale;
use App\Support\AccountingDay\AccountingDayException;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'api.version' => ApiVersion::class,
            'accounting.day.registration-lock' => EnforceAccountingDayRegistrationLock::class,
            'idempotency' => IdempotencyMiddleware::class,
        ]);

        $middleware->redirectGuestsTo(fn (): ?string => null);

        // Global so locale negotiation also applies to pre-routing exceptions
        // (e.g. unmatched-route 404s) that render as JSON. The middleware only
        // mutates the locale for API/JSON requests, leaving web rendering alone.
        $middleware->prepend(SetApiLocale::class);

        $middleware->api(
            append: [
                EnforceDatabaseRestoreLock::class,
                RemoveServerDisclosureHeaders::class,
            ],
            prepend: [
                IdempotencyMiddleware::class,
            ]
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportDuplicates();

        $exceptions->context(function (): array {
            if (! app()->bound('request')) {
                return [
                    'app_env' => app()->environment(),
                ];
            }

            $request = request();
            $requestId = $request->headers->get('X-Request-ID');
            $apiVersion = $request->headers->get('X-API-Version');

            return array_filter([
                'app_env' => app()->environment(),
                'api_version' => is_string($apiVersion) && $apiVersion !== '' ? $apiVersion : null,
                'request_id' => is_string($requestId) && preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $requestId) === 1 ? $requestId : null,
                'route_name' => $request->route()?->getName(),
            ], static fn (mixed $value): bool => $value !== null);
        });

        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e): bool {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::unprocessable(__('api.validation_failed'), $e->errors());
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::notFound();
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::notFound();
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(__('api.method_not_allowed'), null, Response::HTTP_METHOD_NOT_ALLOWED);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::unauthorized(__('api.unauthenticated'));
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::forbidden();
        });

        // Tampered or expired signed URLs (e.g. profile-photo thumbnails) are a
        // 403, not an unhandled 500.
        $exceptions->render(function (InvalidSignatureException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::forbidden(__('api.invalid_signature'));
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;

            return ApiResponse::tooManyRequests(
                __('api.rate_limit_exceeded', ['seconds' => (int) $retryAfter]),
                (int) $retryAfter
            );
        });

        $exceptions->render(function (AccountingDayException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return $e->render($request);
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            if (app()->isProduction()) {
                return ApiResponse::error(__('api.internal_server_error'), null, Response::HTTP_INTERNAL_SERVER_ERROR);
            }

            return ApiResponse::error(
                $e->getMessage(),
                [
                    'exception' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => collect($e->getTrace())->take(20)->toArray(),
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        });
    })->create();
