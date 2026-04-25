<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_is_rate_limited_after_too_many_attempts(): void
    {
        foreach (range(1, 6) as $attempt) {
            $response = $this->postJson('/api/v1/login', [
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus($attempt === 6 ? 429 : 401);
        }
    }

    public function test_register_is_rate_limited_after_too_many_attempts(): void
    {
        config(['security.auth.registration.enabled' => true]);

        foreach (range(1, 4) as $attempt) {
            $response = $this->postJson('/api/v1/register', [
                'name' => 'Test User',
                'email' => 'rate-limit-'.$attempt.'@example.com',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

            $response->assertStatus($attempt === 4 ? 429 : 201);
        }
    }
}
