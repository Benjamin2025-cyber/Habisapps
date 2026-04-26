<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\ActivateStaffRequest;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\ResendActivationOtpRequest;
use App\Http\Resources\StaffUserResource;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final class AuthController extends BaseController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $password = $request->string('password')->toString();
        $user = User::query()->where('phone_number', $request->string('phone_number')->toString())->first();

        if (! $user instanceof User
            || ! is_string($user->password)
            || ! Hash::check($password, $user->password)
            || $user->status !== User::STATUS_ACTIVE
            || $user->phone_verified_at === null) {
            return $this->respondUnauthorized('Invalid credentials.');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $this->createAccessToken($user);

        return $this->respondSuccess([
            'user' => StaffUserResource::make($user)->resolve(),
            'token' => $token->plainTextToken,
        ], 'Login successful');
    }

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

        return $this->respondSuccess([
            'user' => StaffUserResource::make($user)->resolve(),
        ], 'Account activated successfully');
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
            }
        }

        return $this->respondSuccess(message: 'If the account can be activated, a verification code has been sent.');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->currentAccessToken()->delete();
        }

        return $this->respondSuccess(message: 'Logout successful');
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
}
