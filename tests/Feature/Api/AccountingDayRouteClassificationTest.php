<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Http\Middleware\EnforceAccountingDayRegistrationLock;
use App\Support\AccountingDay\RouteClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Tests\TestCase;

final class AccountingDayRouteClassificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_authenticated_mutating_routes_resolve_to_a_valid_classification(): void
    {
        $routes = app('router')->getRoutes()->getRoutes();
        $invalid = [];
        $missingLock = [];
        $checked = 0;

        /** @var Route $route */
        foreach ($routes as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $middleware = $route->gatherMiddleware();
            if (! in_array('auth:sanctum', $middleware, true)) {
                continue;
            }

            /** @var array<string> $routeMethods */
            $routeMethods = $route->methods();
            $methods = array_values(array_diff($routeMethods, ['HEAD', 'OPTIONS']));
            foreach ($methods as $method) {
                if (in_array($method, ['GET'], true)) {
                    continue;
                }

                $checked++;
                $classification = $route->defaults['accounting_day_classification'] ?? RouteClassification::REGISTRATION;
                if (! in_array($classification, [
                    RouteClassification::REGISTRATION,
                    RouteClassification::DAY_LIFECYCLE,
                    RouteClassification::SYSTEM_MAINTENANCE,
                    RouteClassification::ADMINISTRATION,
                ], true)) {
                    $invalid[] = $method.' '.$uri.' => '.$classification;
                }

                if (! in_array('accounting.day.registration-lock', $middleware, true)
                    && ! in_array(EnforceAccountingDayRegistrationLock::class, $middleware, true)
                ) {
                    $missingLock[] = $method.' '.$uri;
                }
            }
        }

        self::assertGreaterThan(0, $checked, 'The route-classification test did not inspect any authenticated mutating API routes.');
        self::assertSame([], $invalid, 'Mutating auth routes have invalid accounting-day classification: '.implode(', ', $invalid));
        self::assertSame([], $missingLock, 'Mutating auth routes are missing accounting-day lock middleware: '.implode(', ', $missingLock));
    }

    public function test_day_lifecycle_and_system_maintenance_routes_are_explicitly_classified(): void
    {
        $routes = app('router')->getRoutes();

        $expectations = [
            'POST v1/accounting-days/open' => RouteClassification::DAY_LIFECYCLE,
            'POST v1/accounting-days/{accountingDay}/start-close' => RouteClassification::DAY_LIFECYCLE,
            'POST v1/accounting-days/{accountingDay}/close' => RouteClassification::DAY_LIFECYCLE,
            'POST v1/accounting-days/{accountingDay}/reopen' => RouteClassification::DAY_LIFECYCLE,
            'POST v1/logout' => RouteClassification::SYSTEM_MAINTENANCE,
            // Identity & access administration is allowlisted out of the lock.
            'POST v1/staff-users' => RouteClassification::ADMINISTRATION,
            'PATCH v1/staff-users/{staffUser}' => RouteClassification::ADMINISTRATION,
            'PATCH v1/staff-users/{staffUser}/status' => RouteClassification::ADMINISTRATION,
            'PUT v1/staff-users/{staffUser}/roles' => RouteClassification::ADMINISTRATION,
            'POST v1/staff-users/{staffUser}/assignments' => RouteClassification::ADMINISTRATION,
            'PUT v1/roles/{role}/permissions' => RouteClassification::ADMINISTRATION,
            'POST v1/roles/{role}/permissions/{permission}' => RouteClassification::ADMINISTRATION,
            'DELETE v1/roles/{role}/permissions/{permission}' => RouteClassification::ADMINISTRATION,
            // User self-service notification read-state is allowlisted out of the lock.
            'POST v1/notifications/read-all' => RouteClassification::ADMINISTRATION,
            'POST v1/notifications/{notification}/read' => RouteClassification::ADMINISTRATION,
        ];

        foreach ($expectations as $signature => $expected) {
            [$method, $uri] = explode(' ', $signature, 2);
            $route = $routes->match(Request::create('/api/'.$uri, $method));
            self::assertInstanceOf(Route::class, $route);
            self::assertSame($expected, $route->defaults['accounting_day_classification'] ?? null, $signature.' must be explicitly classified.');
        }
    }

    public function test_non_mutating_methods_are_always_consultation_and_never_gated(): void
    {
        $classifier = new RouteClassification;

        // Read methods short-circuit to consultation regardless of any route
        // default, so a non-mutating route can never reach the registration lock.
        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            self::assertSame(
                RouteClassification::CONSULTATION,
                $classifier->classify(Request::create('/api/v1/anything', $method)),
                $method.' must classify as consultation.'
            );

            // Even if a read route somehow carried a registration default, the
            // method must still win: the lock only ever gates state mutations.
            $route = (new Route([$method], 'anything', []))
                ->defaults('accounting_day_classification', RouteClassification::REGISTRATION);
            $request = Request::create('/api/v1/anything', $method);
            $request->setRouteResolver(static fn (): Route => $route);

            self::assertSame(
                RouteClassification::CONSULTATION,
                $classifier->classify($request),
                $method.' must remain consultation even with a registration default.'
            );
        }
    }

    public function test_mutating_methods_default_to_the_locked_registration_classification(): void
    {
        $classifier = new RouteClassification;

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            self::assertSame(
                RouteClassification::REGISTRATION,
                $classifier->classify(Request::create('/api/v1/anything', $method)),
                $method.' without an explicit classification must default to registration (gated).'
            );
        }
    }
}
