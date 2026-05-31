<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Tests\TestCase;

final class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_exposes_paginated_envelope_and_accepts_query_params(): void
    {
        $response = $this->withApiHeaders()
            ->getJson('/api/v1/health?page=2&per_page=1&search=ok');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.status', 'ok');
        $response->assertJsonPath('meta.pagination.current_page', 2);
        $response->assertJsonPath('meta.pagination.per_page', 1);
        $response->assertJsonPath('meta.pagination.total', 1);
        $response->assertJsonPath('meta.pagination.last_page', 1);
    }
}
