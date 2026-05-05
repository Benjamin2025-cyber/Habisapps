<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

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
}
