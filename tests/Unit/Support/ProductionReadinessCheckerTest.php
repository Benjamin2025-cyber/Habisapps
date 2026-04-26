<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Readiness\ProductionReadinessChecker;
use App\Support\Readiness\ReadinessCheckResult;
use Tests\TestCase;

final class ProductionReadinessCheckerTest extends TestCase
{
    public function test_checker_reports_failures_for_local_unsafe_defaults(): void
    {
        config([
            'app.debug' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
        ]);

        $checker = app(ProductionReadinessChecker::class);
        $results = $checker->check();

        self::assertTrue($checker->hasFailures($results));
        self::assertContains('app.debug', $this->failedKeys($results));
        self::assertContains('cache.default', $this->failedKeys($results));
        self::assertContains('session.driver', $this->failedKeys($results));
        self::assertContains('queue.default', $this->failedKeys($results));
    }

    public function test_checker_passes_deployment_safe_core_settings(): void
    {
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.str_repeat('a', 44),
            'cache.default' => 'redis',
            'session.driver' => 'redis',
            'queue.default' => 'redis',
            'money.default_currency' => 'XAF',
        ]);

        $checker = app(ProductionReadinessChecker::class);
        $results = $checker->check();

        self::assertFalse($checker->hasFailures($results));
    }

    /**
     * @param  array<int, ReadinessCheckResult>  $results
     * @return array<int, string>
     */
    private function failedKeys(array $results): array
    {
        return array_values(array_map(
            static fn ($result): string => $result->key,
            array_filter($results, static fn ($result): bool => ! $result->passed)
        ));
    }
}
