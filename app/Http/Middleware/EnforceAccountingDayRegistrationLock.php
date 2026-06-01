<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\AccountingDay\RouteClassification;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceAccountingDayRegistrationLock
{
    public function __construct(
        private readonly AccountingDayGuard $accountingDayGuard,
        private readonly RouteClassification $routeClassification,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        if (! $actor instanceof User) {
            return $next($request);
        }

        $classification = $this->routeClassification->classify($request);
        if ($classification === RouteClassification::UNCLASSIFIED) {
            return ApiResponse::error(
                'Mutating route is missing accounting-day classification.',
                [
                    'code' => 'accounting_day_route_unclassified',
                    'method' => strtoupper($request->method()),
                    'path' => $request->path(),
                ],
                500
            );
        }

        if (in_array($classification, [RouteClassification::CONSULTATION, RouteClassification::DAY_LIFECYCLE, RouteClassification::SYSTEM_MAINTENANCE], true)) {
            return $next($request);
        }

        $operation = strtoupper($request->method()).' '.$request->path();
        $this->accountingDayGuard->assertCanRegister($actor, $operation, null, $request);

        return $next($request);
    }
}

