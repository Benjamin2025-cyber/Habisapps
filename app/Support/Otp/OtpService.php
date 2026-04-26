<?php

declare(strict_types=1);

namespace App\Support\Otp;

use App\Models\OtpChallenge;
use App\Models\OtpDelivery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class OtpService
{
    public function issueActivationChallenge(User $user, ?Request $request = null): OtpChallenge
    {
        $code = $this->generateCode();
        $challenge = OtpChallenge::query()->create([
            'user_id' => $user->id,
            'purpose' => OtpChallenge::PURPOSE_ACTIVATION,
            'phone_number' => $user->phone_number,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($this->integerConfig('security.otp.expires_minutes', 10)),
            'max_attempts' => $this->integerConfig('security.otp.max_attempts', 5),
            'last_sent_at' => now(),
            'resend_count' => 0,
            'created_ip' => $request?->ip(),
            'created_user_agent' => $request?->userAgent(),
        ]);

        $this->deliver($challenge, $user);

        return $challenge;
    }

    public function resendActivationChallenge(User $user, ?Request $request = null): OtpChallenge
    {
        $challenge = $this->issueActivationChallenge($user, $request);
        $challenge->forceFill(['resend_count' => 1])->save();

        return $challenge;
    }

    public function verifyActivationCode(User $user, string $code): bool
    {
        $challenge = OtpChallenge::query()
            ->where('user_id', $user->id)
            ->where('purpose', OtpChallenge::PURPOSE_ACTIVATION)
            ->where('used_at', null)
            ->where('expires_at', '>', now())
            ->latest()
            ->first();

        if (! $challenge instanceof OtpChallenge) {
            return false;
        }

        if ($challenge->attempts >= $challenge->max_attempts) {
            return false;
        }

        $challenge->increment('attempts');

        if (! Hash::check($code, $challenge->code_hash)) {
            return false;
        }

        $challenge->forceFill(['used_at' => now()])->save();

        return true;
    }

    private function deliver(OtpChallenge $challenge, User $user): void
    {
        $this->recordDelivery(
            $challenge,
            OtpDelivery::CHANNEL_SMS,
            $user->phone_number,
            $this->maskPhone($user->phone_number)
        );

        if (is_string($user->email) && $user->email !== '') {
            $this->recordDelivery(
                $challenge,
                OtpDelivery::CHANNEL_EMAIL,
                $user->email,
                $this->maskEmail($user->email)
            );
        }
    }

    private function recordDelivery(
        OtpChallenge $challenge,
        string $channel,
        string $destination,
        string $maskedDestination,
    ): void {
        OtpDelivery::query()->create([
            'otp_challenge_id' => $challenge->id,
            'channel' => $channel,
            'destination_hash' => hash('sha256', Str::lower($destination)),
            'destination_masked' => $maskedDestination,
            'status' => OtpDelivery::STATUS_SENT,
            'provider_reference' => app()->environment('testing') ? 'test-delivery' : null,
            'sent_at' => now(),
        ]);
    }

    private function generateCode(): string
    {
        if (app()->environment('testing')) {
            return '123456';
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4).substr($phone, -4);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return substr($name, 0, 1).'***@'.$domain;
    }

    private function integerConfig(string $key, int $default): int
    {
        $value = config($key, $default);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
