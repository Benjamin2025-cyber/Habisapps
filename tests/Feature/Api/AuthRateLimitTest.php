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
                'phone_number' => '+237699100001',
                'password' => 'wrong-password',
            ]);

            $response->assertStatus($attempt === 6 ? 429 : 401);
        }
    }

    public function test_login_rate_limit_is_not_keyed_to_the_victim_phone_number(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.10'])->postJson('/api/v1/login', [
                'phone_number' => '+237699100002',
                'password' => 'wrong-password-'.$attempt,
            ])->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.11'])->postJson('/api/v1/login', [
            'phone_number' => '+237699100002',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    public function test_activation_is_rate_limited_after_too_many_attempts(): void
    {
        foreach (range(1, 6) as $attempt) {
            $response = $this->postJson('/api/v1/activate', [
                'phone_number' => '+237699100003',
                'otp' => '000000',
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);

            $response->assertStatus($attempt === 6 ? 429 : 422);
        }
    }
}
