<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AccountingDay\RouteClassification;
use App\Support\ApiResponse;
use App\Support\DatabaseManagement\DatabaseMaintenanceLock;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a database restore holds the maintenance lock, financial registration
 * writes are refused with a clear 503 so no transaction can be recorded against
 * a database that is mid-restore. Consultation (reads), system-maintenance, and
 * administration routes stay available so operators can still watch restore
 * status and health (ADM-DB-010).
 */
final class EnforceDatabaseRestoreLock
{
    public function __construct(
        private readonly DatabaseMaintenanceLock $lock,
        private readonly RouteClassification $routeClassification,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->lock->isActive()) {
            return $next($request);
        }

        $classification = $this->routeClassification->classify($request);
        $blocked = in_array($classification, [
            RouteClassification::REGISTRATION,
            RouteClassification::DAY_LIFECYCLE,
        ], true);

        if (! $blocked) {
            return $next($request);
        }

        $current = $this->lock->current();

        return ApiResponse::error(
            'A database restore is in progress. Registration writes are temporarily unavailable.',
            [
                'code' => 'database_restore_in_progress',
                'reason' => $current['reason'] ?? 'Database restore in progress',
                'expires_at' => $current['expires_at'] ?? null,
            ],
            Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
