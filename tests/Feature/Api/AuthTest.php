<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_user_and_returns_a_token(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertJsonSuccess($response, 201);
        $response->assertJsonPath('message', 'Registration successful');
        $response->assertJsonPath('data.user.email', 'ada@example.com');
        $response->assertJsonPath('data.token', fn (mixed $token): bool => is_string($token) && $token !== '');

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        $user = User::factory()->createOne([
            'email' => 'grace@example.com',
            'password' => 'Password123!',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'grace@example.com',
            'password' => 'Password123!',
        ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'Login successful');
        $response->assertJsonPath('data.user.email', $user->email);
        $response->assertJsonPath('data.token', fn (mixed $token): bool => is_string($token) && $token !== '');

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->createOne([
            'email' => 'grace@example.com',
            'password' => 'Password123!',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'grace@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertJsonError($response, 401, 'Invalid credentials.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_revokes_the_current_access_token(): void
    {
        $user = User::factory()->createOne();
        $plainTextToken = $user->createToken('test-token')->plainTextToken;

        $response = $this
            ->withApiHeaders(['Authorization' => 'Bearer '.$plainTextToken])
            ->postJson('/api/v1/logout');

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'Logout successful');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
