<?php

declare(strict_types=1);

namespace App\Support\AccountingDay;

use Illuminate\Http\Request;

final class RouteClassification
{
    public const string CONSULTATION = 'consultation';

    public const string REGISTRATION = 'registration';

    public const string DAY_LIFECYCLE = 'day_lifecycle';

    public const string SYSTEM_MAINTENANCE = 'system_maintenance';

    public const string UNCLASSIFIED = 'unclassified';

    /**
     * Classify the current request for accounting-day lock policy.
     *
     * Safe read methods are always consultation. Mutating methods default to
     * registration unless explicitly classified as day lifecycle or maintenance.
     */
    public function classify(Request $request): string
    {
        $method = strtoupper($request->method());
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return self::CONSULTATION;
        }

        $classification = $request->route()?->defaults['accounting_day_classification'] ?? null;
        if (! is_string($classification) || $classification === '') {
            return self::REGISTRATION;
        }

        return match ($classification) {
            self::REGISTRATION,
            self::DAY_LIFECYCLE,
            self::SYSTEM_MAINTENANCE => $classification,
            default => self::UNCLASSIFIED,
        };
    }
}
