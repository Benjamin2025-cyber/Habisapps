<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\ActivateStaffRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RequestPasswordResetOtpRequest;
use App\Http\Requests\Api\V1\ResendActivationOtpRequest;
use App\Http\Requests\Api\V1\ResetPasswordRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\Otp\OtpService;
use App\Support\Security\SecurityAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final class AuthController extends BaseController
{
    public function __construct(private readonly SecurityAudit $securityAudit) {}

    /**
     * Login
     *
     * @response StaffUserResource
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $password = $request->string('password')->toString();
        $user = User::query()->where('phone_number', $request->string('phone_number')->toString())->first();

        if (! $user instanceof User
            || ! is_string($user->password)
            || ! Hash::check($password, $user->password)
            || $user->status !== User::STATUS_ACTIVE
            || $user->phone_verified_at === null) {
            $this->securityAudit->record('auth.login_failed', subject: $user instanceof User ? $user : null, properties: [
                'phone_hash' => hash('sha256', $request->string('phone_number')->toString()),
            ], request: $request);

            return $this->respondUnauthorized('Invalid credentials.');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $this->createAccessToken($user);
        $this->securityAudit->record('auth.login_succeeded', actor: $user, subject: $user, request: $request);

        return $this->respondSuccess(
            [
                'user' => StaffUserResource::make(
                    $user->loadMissing(['agency', 'hrEmployee.supervisor', 'roles.permissions', 'permissions'])
                ),
                'token' => $token->plainTextToken,
            ],
            'Login successful'
        );
    }

    /**
     * Activate staff account
     *
     * @authenticated
     *
     * @response StaffUserResource
     */
    public function activate(ActivateStaffRequest $request, OtpService $otpService): JsonResponse
    {
        $user = User::query()
            ->where('phone_number', $request->string('phone_number')->toString())
            ->where('status', User::STATUS_PENDING_VERIFICATION)
            ->first();

        if (! $user instanceof User
            || ! $otpService->verifyActivationCode($user, $request->string('otp')->toString())) {
            return $this->respondUnprocessable('Invalid or expired verification code.');
        }

        $user->forceFill([
            'password' => $request->string('password')->toString(),
            'phone_verified_at' => now(),
            'activated_at' => now(),
            'status' => User::STATUS_ACTIVE,
        ])->save();
        $this->securityAudit->record('auth.account_activated', actor: $user, subject: $user, request: $request);

        return $this->respondSuccess(
            ['user' => StaffUserResource::make(
                $user->loadMissing(['agency', 'hrEmployee.supervisor', 'roles.permissions', 'permissions'])
            )],
            'Account activated successfully'
        );
    }

    public function resendActivationOtp(ResendActivationOtpRequest $request, OtpService $otpService): JsonResponse
    {
        $user = User::query()
            ->where('phone_number', $request->string('phone_number')->toString())
            ->where('status', User::STATUS_PENDING_VERIFICATION)
            ->first();

        if ($user instanceof User) {
            $latestChallenge = OtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', OtpChallenge::PURPOSE_ACTIVATION)
                ->latest()
                ->first();

            if (! $latestChallenge instanceof OtpChallenge
                || $latestChallenge->last_sent_at === null
                || $latestChallenge->last_sent_at->addMinutes($this->integerConfig('security.otp.resend_decay_minutes', 1))->isPast()) {
                $otpService->resendActivationChallenge($user, $request);
                $this->securityAudit->record('otp.activation_resent', subject: $user, request: $request);
            }
        }

        return $this->respondSuccess(message: 'If the account can be activated, a verification code has been sent.');
    }

    public function requestPasswordResetOtp(RequestPasswordResetOtpRequest $request, OtpService $otpService): JsonResponse
    {
        $user = User::query()
            ->where('phone_number', $request->string('phone_number')->toString())
            ->where('status', User::STATUS_ACTIVE)
            ->where('phone_verified_at', '!=', null)
            ->first();

        if ($user instanceof User) {
            $latestChallenge = OtpChallenge::query()
                ->where('user_id', $user->id)
                ->where('purpose', OtpChallenge::PURPOSE_PASSWORD_RESET)
                ->latest()
                ->first();

            if (! $latestChallenge instanceof OtpChallenge
                || $latestChallenge->last_sent_at === null
                || $latestChallenge->last_sent_at->addMinutes($this->integerConfig('security.otp.resend_decay_minutes', 1))->isPast()) {
                $otpService->issuePasswordResetChallenge($user, $request);
                $this->securityAudit->record('otp.password_reset_requested', subject: $user, request: $request);
            }
        }

        return $this->respondSuccess(message: 'If the account can reset its password, a verification code has been sent.');
    }

    public function resetPassword(ResetPasswordRequest $request, OtpService $otpService): JsonResponse
    {
        $user = User::query()
            ->where('phone_number', $request->string('phone_number')->toString())
            ->where('status', User::STATUS_ACTIVE)
            ->where('phone_verified_at', '!=', null)
            ->first();

        if (! $user instanceof User
            || ! $otpService->verifyPasswordResetCode($user, $request->string('otp')->toString())) {
            $this->securityAudit->record('auth.password_reset_failed', subject: $user instanceof User ? $user : null, properties: [
                'phone_hash' => hash('sha256', $request->string('phone_number')->toString()),
            ], request: $request);

            return $this->respondUnprocessable('Invalid or expired verification code.');
        }

        $user->forceFill([
            'password' => $request->string('password')->toString(),
        ])->save();
        $this->revokeAllTokens($user);
        $this->securityAudit->record('auth.password_reset_succeeded', actor: $user, subject: $user, request: $request);

        return $this->respondSuccess(message: 'Password reset successfully');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->currentAccessToken()->delete();
            $this->securityAudit->record('auth.logout_succeeded', actor: $user, subject: $user, request: $request);
        }

        return $this->respondSuccess(message: 'Logout successful');
    }

    /**
     * Return the currently authenticated staff user with roles, permissions,
     * and agency context. Use this to refresh a session after role changes
     * without forcing a re-login.
     *
     * @authenticated
     *
     * @response StaffUserResource
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $this->respondUnauthorized();
        }

        return $this->respondSuccess(
            ['user' => StaffUserResource::make(
                $user->loadMissing(['agency', 'hrEmployee.supervisor', 'roles.permissions', 'permissions'])
            )]
        );
    }

    private function createAccessToken(User $user): NewAccessToken
    {
        /** @var array<int, string> $abilities */
        $abilities = config('security.auth.token_abilities', ['access-api']);
        $ttlMinutes = $this->integerConfig('security.auth.token_ttl_minutes', 60);

        return $user->createToken(
            'auth-token',
            $abilities,
            now()->addMinutes($ttlMinutes)
        );
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }

    private function revokeAllTokens(User $user): void
    {
        foreach ($user->tokens()->get() as $token) {
            $token->delete();
        }
    }
}
