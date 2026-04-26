<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\Otp\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rejects_unknown_fields(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'phone_number' => '+237699000001',
            'password' => 'Password123!',
            'role' => 'platform-admin',
        ]);

        $this->assertJsonError($response, 422, 'Validation failed');
        $response->assertJsonValidationErrors(['role']);
    }

    public function test_login_rejects_oversized_passwords(): void
    {
        $response = $this->postJson('/api/v1/login', [
            'phone_number' => '+237699000001',
            'password' => str_repeat('a', 256),
        ]);

        $this->assertJsonError($response, 422, 'Validation failed');
        $response->assertJsonValidationErrors(['password']);
    }

    public function test_pending_staff_cannot_login(): void
    {
        User::factory()->unverified()->createOne([
            'phone_number' => '+237699000002',
            'password' => 'Password123!',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'phone_number' => '+237699000002',
            'password' => 'Password123!',
        ]);

        $this->assertJsonError($response, 401, 'Invalid credentials.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_returns_a_token_for_active_verified_staff(): void
    {
        $user = User::factory()->createOne([
            'phone_number' => '+237699000003',
            'password' => 'Password123!',
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'phone_number' => '+237699000003',
            'password' => 'Password123!',
        ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'Login successful');
        $response->assertJsonPath('data.user.public_id', $user->public_id);
        $response->assertJsonMissingPath('data.user.id');
        $response->assertJsonPath('data.token', fn (mixed $token): bool => is_string($token) && $token !== '');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'expires_at' => null,
        ]);
        self::assertNotNull($user->refresh()->last_login_at);
    }

    public function test_login_idempotency_does_not_persist_plain_text_token_response(): void
    {
        User::factory()->createOne([
            'phone_number' => '+237699000004',
            'password' => 'Password123!',
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);

        $response = $this
            ->withApiHeaders(['Idempotency-Key' => 'login-token-storage-check'])
            ->postJson('/api/v1/login', [
                'phone_number' => '+237699000004',
                'password' => 'Password123!',
            ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('data.token', fn (mixed $token): bool => is_string($token) && $token !== '');

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('api_idempotency_keys', 0);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->createOne([
            'phone_number' => '+237699000005',
            'password' => 'Password123!',
        ]);

        $response = $this->postJson('/api/v1/login', [
            'phone_number' => '+237699000005',
            'password' => 'wrong-password',
        ]);

        $this->assertJsonError($response, 401, 'Invalid credentials.');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_staff_can_activate_account_with_otp_and_set_password(): void
    {
        $user = User::factory()->unverified()->createOne([
            'phone_number' => '+237699000006',
            'password' => null,
        ]);

        app(OtpService::class)->issueActivationChallenge($user, request());

        $response = $this->postJson('/api/v1/activate', [
            'phone_number' => '+237699000006',
            'otp' => '123456',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'Account activated successfully');
        $response->assertJsonPath('data.user.status', User::STATUS_ACTIVE);

        $user->refresh();

        self::assertSame(User::STATUS_ACTIVE, $user->status);
        self::assertNotNull($user->phone_verified_at);
        self::assertNotNull($user->activated_at);
        self::assertTrue(Hash::check('Password123!', (string) $user->password));
        self::assertNotNull(OtpChallenge::query()->first()?->used_at);
    }

    public function test_activation_rejects_invalid_otp(): void
    {
        $user = User::factory()->unverified()->createOne([
            'phone_number' => '+237699000007',
            'password' => null,
        ]);

        app(OtpService::class)->issueActivationChallenge($user, request());

        $response = $this->postJson('/api/v1/activate', [
            'phone_number' => '+237699000007',
            'otp' => '000000',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertJsonError($response, 422, 'Invalid or expired verification code.');
        self::assertSame(User::STATUS_PENDING_VERIFICATION, $user->refresh()->status);
    }

    public function test_resend_activation_otp_uses_generic_response(): void
    {
        User::factory()->unverified()->createOne([
            'phone_number' => '+237699000008',
            'password' => null,
        ]);

        $response = $this->postJson('/api/v1/activation/resend', [
            'phone_number' => '+237699000008',
        ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'If the account can be activated, a verification code has been sent.');
        $this->assertDatabaseCount('otp_challenges', 1);
        $this->assertDatabaseCount('otp_deliveries', 2);
    }

    public function test_active_staff_can_request_password_reset_otp_with_generic_response(): void
    {
        User::factory()->createOne([
            'phone_number' => '+237699000009',
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/v1/password/otp', [
            'phone_number' => '+237699000009',
        ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'If the account can reset its password, a verification code has been sent.');
        $this->assertDatabaseHas('otp_challenges', [
            'purpose' => OtpChallenge::PURPOSE_PASSWORD_RESET,
            'phone_number' => '+237699000009',
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'otp.password_reset_requested',
        ]);
    }

    public function test_password_reset_verifies_otp_sets_password_and_revokes_tokens(): void
    {
        $user = User::factory()->createOne([
            'phone_number' => '+237699000010',
            'password' => 'OldPassword123!',
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);
        $user->createToken('mobile-device');

        app(OtpService::class)->issuePasswordResetChallenge($user, request());

        $response = $this->postJson('/api/v1/password/reset', [
            'phone_number' => '+237699000010',
            'otp' => '123456',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $this->assertJsonSuccess($response);
        $response->assertJsonPath('message', 'Password reset successfully');
        self::assertTrue(Hash::check('NewPassword123!', (string) $user->refresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'auth.password_reset_succeeded',
        ]);
    }

    public function test_password_reset_rejects_invalid_otp_with_generic_error(): void
    {
        $user = User::factory()->createOne([
            'phone_number' => '+237699000011',
            'password' => 'OldPassword123!',
            'status' => User::STATUS_ACTIVE,
            'phone_verified_at' => now(),
        ]);

        app(OtpService::class)->issuePasswordResetChallenge($user, request());

        $response = $this->postJson('/api/v1/password/reset', [
            'phone_number' => '+237699000011',
            'otp' => '000000',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $this->assertJsonError($response, 422, 'Invalid or expired verification code.');
        self::assertTrue(Hash::check('OldPassword123!', (string) $user->refresh()->password));
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'auth.password_reset_failed',
        ]);
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
        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'security',
            'event' => 'auth.logout_succeeded',
        ]);
    }
}
