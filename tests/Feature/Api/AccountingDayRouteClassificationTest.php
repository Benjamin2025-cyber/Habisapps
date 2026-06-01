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
        $routes = app('router')->getRoutes();
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

            $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));
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
        ];

        foreach ($expectations as $signature => $expected) {
            [$method, $uri] = explode(' ', $signature, 2);
            $route = $routes->match(Request::create('/api/'.$uri, $method));
            self::assertInstanceOf(Route::class, $route);
            self::assertSame($expected, $route->defaults['accounting_day_classification'] ?? null, $signature.' must be explicitly classified.');
        }
    }
}
