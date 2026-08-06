<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ProductionExceptionRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_api_exceptions_return_generic_json_without_internals(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        Route::middleware('api')->get('/api/v1/test-production-exception', static function (): never {
            throw new RuntimeException('Sensitive implementation detail');
        });

        $response = $this->withHeader('X-Request-ID', 'req-test-123')
            ->getJson('/api/v1/test-production-exception');

        $response->assertStatus(500);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Internal server error');
        $response->assertJsonMissing(['Sensitive implementation detail']);
        $response->assertJsonMissingPath('errors.exception');
        $response->assertJsonMissingPath('errors.file');
        $response->assertJsonMissingPath('errors.line');
        $response->assertJsonMissingPath('errors.trace');
    }

    /**
     * Deliberately *not* run as production: the generic Throwable handler only
     * hides internals when the app is production, and a QueryException's message
     * is the failing SQL. So outside production a duplicate used to answer 500
     * with the statement, the connection and the constraint name in the body.
     */
    public function test_unique_constraint_violations_return_a_conflict_without_the_failing_sql(): void
    {
        Route::middleware('api')->get('/api/v1/test-unique-violation', static function (): never {
            throw new UniqueConstraintViolationException(
                'pgsql',
                'insert into "ledger_accounts" ("agency_id", "code") values (1, 571901)',
                [],
                new RuntimeException(
                    'SQLSTATE[23505]: Unique violation: duplicate key value violates unique constraint "uniq_agency_ledger_account_code"'
                ),
            );
        });

        $response = $this->getJson('/api/v1/test-unique-violation');

        $response->assertStatus(409);
        $response->assertJsonPath('success', false);
        $response->assertDontSee('uniq_agency_ledger_account_code');
        $response->assertDontSee('insert into', false);
        $response->assertDontSee('SQLSTATE', false);
        $response->assertJsonMissingPath('errors.trace');
    }
}
