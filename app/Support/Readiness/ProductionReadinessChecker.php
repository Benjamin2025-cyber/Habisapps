<?php

declare(strict_types=1);

namespace App\Support\Readiness;

final class ProductionReadinessChecker
{
    /**
     * @return array<int, ReadinessCheckResult>
     */
    public function check(): array
    {
        return [
            $this->appDebugDisabled(),
            $this->appKeyConfigured(),
            $this->cacheStoreIsDurable(),
            $this->sessionDriverIsDurable(),
            $this->queueConnectionIsAsync(),
            $this->moneyCurrencyConfigured(),
            $this->formulaPoliciesRemainExplicit(),
        ];
    }

    /**
     * @param  array<int, ReadinessCheckResult>  $results
     */
    public function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if (! $result->passed) {
                return true;
            }
        }

        return false;
    }

    private function appDebugDisabled(): ReadinessCheckResult
    {
        return config('app.debug') === false
            ? ReadinessCheckResult::pass('app.debug', 'APP_DEBUG is disabled.')
            : ReadinessCheckResult::fail('app.debug', 'APP_DEBUG must be false outside local development.');
    }

    private function appKeyConfigured(): ReadinessCheckResult
    {
        $key = config('app.key');

        return is_string($key) && $key !== ''
            ? ReadinessCheckResult::pass('app.key', 'Application key is configured.')
            : ReadinessCheckResult::fail('app.key', 'APP_KEY is missing.');
    }

    private function cacheStoreIsDurable(): ReadinessCheckResult
    {
        $store = config('cache.default');

        return in_array($store, ['redis', 'memcached', 'database', 'dynamodb'], true)
            ? ReadinessCheckResult::pass('cache.default', 'Cache store is durable/shared.')
            : ReadinessCheckResult::fail('cache.default', 'Use a durable/shared cache store in production, preferably redis.');
    }

    private function sessionDriverIsDurable(): ReadinessCheckResult
    {
        $driver = config('session.driver');

        return in_array($driver, ['redis', 'database', 'memcached'], true)
            ? ReadinessCheckResult::pass('session.driver', 'Session driver is durable/shared.')
            : ReadinessCheckResult::fail('session.driver', 'Use a durable/shared session driver in production.');
    }

    private function queueConnectionIsAsync(): ReadinessCheckResult
    {
        $connection = config('queue.default');

        return ! in_array($connection, ['sync', 'null'], true)
            ? ReadinessCheckResult::pass('queue.default', 'Queue connection is asynchronous.')
            : ReadinessCheckResult::fail('queue.default', 'Use an asynchronous queue connection in production.');
    }

    private function moneyCurrencyConfigured(): ReadinessCheckResult
    {
        return config('money.default_currency') === 'XAF'
            ? ReadinessCheckResult::pass('money.default_currency', 'Default currency is XAF.')
            : ReadinessCheckResult::fail('money.default_currency', 'Default currency must remain XAF for this deployment context.');
    }

    private function formulaPoliciesRemainExplicit(): ReadinessCheckResult
    {
        $policies = config('formulas.policies');

        if (! is_array($policies) || $policies === []) {
            return ReadinessCheckResult::fail('formulas.policies', 'Formula policy approval gates are missing.');
        }

        foreach ($policies as $key => $policy) {
            if (! is_array($policy) || ! array_key_exists('approved', $policy)) {
                return ReadinessCheckResult::fail('formulas.policies', sprintf('Formula policy [%s] has no explicit approved flag.', (string) $key));
            }
        }

        return ReadinessCheckResult::pass('formulas.policies', 'Formula policy approval gates are explicit.');
    }
}
