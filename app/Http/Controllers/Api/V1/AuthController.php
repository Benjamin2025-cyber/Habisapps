<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\BaseController;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\NewAccessToken;

final class AuthController extends BaseController
{
    public function register(RegisterRequest $request): JsonResponse
    {
        if (! (bool) config('security.auth.registration.enabled', false)) {
            return $this->respondForbidden('Registration is disabled.');
        }

        $user = User::query()->create([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
        ]);

        $token = $this->createAccessToken($user);

        return $this->respondCreated([
            'user' => $user,
            'token' => $token->plainTextToken,
        ], 'Registration successful');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $password = $request->string('password')->toString();
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if ($user === null || ! Hash::check($password, $user->password)) {
            return $this->respondUnauthorized('Invalid credentials.');
        }

        $token = $this->createAccessToken($user);

        return $this->respondSuccess([
            'user' => $user,
            'token' => $token->plainTextToken,
        ], 'Login successful');
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
