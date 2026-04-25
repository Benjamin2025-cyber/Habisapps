<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_success(): void
    {
        $response = $this->getJson('/api/v1/health');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.status', 'ok');
        $response->assertJsonPath('data.service', 'habis-finance-api');
        $response->assertJsonPath('data.version', '1.0.0');
    }
}
