<?php

declare(strict_types=1);

use App\Support\Readiness\ProductionReadinessChecker;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

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
