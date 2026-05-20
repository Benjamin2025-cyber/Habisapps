<?php

declare(strict_types=1);

use App\Support\Readiness\ProductionReadinessChecker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:production-readiness-check', function (ProductionReadinessChecker $checker): int {
    $results = $checker->check();

    foreach ($results as $result) {
        $line = sprintf('[%s] %s: %s', $result->passed ? 'PASS' : 'FAIL', $result->key, $result->message);
        $result->passed ? $this->info($line) : $this->error($line);
    }

    return $checker->hasFailures($results) ? 1 : 0;
})->purpose('Validate deployment-critical production configuration.');

Schedule::command('notifications:produce-client-alerts --type=all')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('notifications:produce-internal-alerts --type=all')
    ->dailyAt('07:15')
    ->withoutOverlapping()
    ->runInBackground();
