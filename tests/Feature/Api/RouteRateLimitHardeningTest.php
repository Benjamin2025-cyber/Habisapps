<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RouteRateLimitHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_authenticated_route_rate_limit_returns_standard_json_429(): void
    {
        config()->set('security.rate_limits.reference_reserve.max_attempts', 1);
        config()->set('security.rate_limits.reference_reserve.decay_minutes', 1);

        $actor = User::factory()->createOne([
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $actor->assignRole('teller');

        $this->actingAsSanctum($actor)
            ->postJson('/api/v1/reference-numbers', ['key' => 'receipt'])
            ->assertCreated();

        $response = $this->actingAsSanctum($actor)
            ->postJson('/api/v1/reference-numbers', ['key' => 'receipt']);

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');
        $response->assertJsonPath('success', false);
        $retryAfter = (int) $response->headers->get('Retry-After');
        self::assertGreaterThanOrEqual(1, $retryAfter);
        self::assertLessThanOrEqual(60, $retryAfter);
        self::assertMatchesRegularExpression(
            '/^Rate limit exceeded\. Try again in [1-9][0-9]? seconds\.$/',
            is_string($response->json('message')) ? $response->json('message') : '',
        );
        $response->assertJsonMissingPath('exception');
        $response->assertJsonMissingPath('trace');
    }
}
