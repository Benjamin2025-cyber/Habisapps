<?php

declare(strict_types=1);

namespace App\Support\Otp;

use App\Models\OtpChallenge;
use App\Models\OtpDelivery;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class OtpService
{
    public function __construct(private readonly OtpDeliveryChannelManager $deliveryChannelManager) {}

    public function issueActivationChallenge(User $user, ?Request $request = null): OtpChallenge
    {
        return $this->issueChallenge($user, OtpChallenge::PURPOSE_ACTIVATION, $request);
    }

    public function issuePasswordResetChallenge(User $user, ?Request $request = null): OtpChallenge
    {
        return $this->issueChallenge($user, OtpChallenge::PURPOSE_PASSWORD_RESET, $request);
    }

    public function issueChallenge(User $user, string $purpose, ?Request $request = null): OtpChallenge
    {
        $code = $this->generateCode();
        $challenge = OtpChallenge::query()->create([
            'user_id' => $user->id,
            'purpose' => $this->validPurpose($purpose),
            'phone_number' => $user->phone_number,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($this->integerConfig('security.otp.expires_minutes', 10)),
            'max_attempts' => $this->integerConfig('security.otp.max_attempts', 5),
            'last_sent_at' => now(),
            'resend_count' => 0,
            'created_ip' => $request?->ip(),
            'created_user_agent' => $request?->userAgent(),
        ]);

        $this->deliver($challenge, $user, $code);

        return $challenge;
    }

    public function resendActivationChallenge(User $user, ?Request $request = null): OtpChallenge
    {
        $challenge = $this->issueActivationChallenge($user, $request);
        $resendCount = DB::table('otp_challenges')
            ->where('user_id', $user->id)
            ->where('purpose', OtpChallenge::PURPOSE_ACTIVATION)
            ->where('used_at', null)
            ->where('expires_at', '>', now())
            ->count();

        $challenge->forceFill(['resend_count' => max(1, $resendCount - 1)])->save();

        return $challenge;
    }

    public function verifyActivationCode(User $user, string $code): bool
    {
        return $this->verifyCode($user, OtpChallenge::PURPOSE_ACTIVATION, $code);
    }

    public function verifyPasswordResetCode(User $user, string $code): bool
    {
        return $this->verifyCode($user, OtpChallenge::PURPOSE_PASSWORD_RESET, $code);
    }

    public function verifyCode(User $user, string $purpose, string $code): bool
    {
        return DB::transaction(function () use ($code, $purpose, $user): bool {
            $query = (new OtpChallenge)->newQuery()
                ->where('user_id', $user->id)
                ->where('purpose', $this->validPurpose($purpose))
                ->where('used_at', null)
                ->where('expires_at', '>', now())
                ->latest('created_at');
            $query->getQuery()->lockForUpdate();

            $challenge = $query->first();

            if (! $challenge instanceof OtpChallenge) {
                return false;
            }

            if ($this->activeAttemptCount($user, $purpose) >= $challenge->max_attempts) {
                return false;
            }

            $challenge->increment('attempts');
            $challenge->refresh();

            if (! Hash::check($code, $challenge->code_hash)) {
                return false;
            }

            $challenge->forceFill(['used_at' => now()])->save();

            return true;
        });
    }

    private function activeAttemptCount(User $user, string $purpose): int
    {
        $value = DB::table('otp_challenges')
            ->where('user_id', $user->id)
            ->where('purpose', $this->validPurpose($purpose))
            ->where('used_at', null)
            ->where('expires_at', '>', now())
            ->sum('attempts');

        return is_int($value) ? $value : (int) $value;
    }

    private function validPurpose(string $purpose): string
    {
        if (! in_array($purpose, [OtpChallenge::PURPOSE_ACTIVATION, OtpChallenge::PURPOSE_PASSWORD_RESET], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported OTP purpose [%s].', $purpose));
        }

        return $purpose;
    }

    private function deliver(OtpChallenge $challenge, User $user, string $code): void
    {
        $channels = $this->resolvedChannels($user);

        if (in_array(OtpDelivery::CHANNEL_SMS, $channels, true)) {
            $this->recordDelivery(
                $challenge,
                OtpDelivery::CHANNEL_SMS,
                $user->phone_number,
                $this->maskPhone($user->phone_number),
                $code
            );
        }

        if (in_array(OtpDelivery::CHANNEL_EMAIL, $channels, true)
            && is_string($user->email)
            && $user->email !== '') {
            $this->recordDelivery(
                $challenge,
                OtpDelivery::CHANNEL_EMAIL,
                $user->email,
                $this->maskEmail($user->email),
                $code
            );
        }
    }

    private function recordDelivery(
        OtpChallenge $challenge,
        string $channel,
        string $destination,
        string $maskedDestination,
        string $code,
    ): void {
        $result = $this->sendOtp($channel, $destination, $code);

        OtpDelivery::query()->create([
            'otp_challenge_id' => $challenge->id,
            'channel' => $channel,
            'destination_hash' => hash('sha256', Str::lower($destination)),
            'destination_masked' => $maskedDestination,
            'status' => $result->sent ? OtpDelivery::STATUS_SENT : OtpDelivery::STATUS_FAILED,
            'retry_count' => 0,
            'max_attempts' => $this->integerConfig('security.otp.delivery_max_attempts', 3),
            'last_attempt_at' => now(),
            'next_attempt_at' => $result->sent ? null : now()->addMinutes(1),
            'provider_reference' => $result->providerReference,
            'error_summary' => $result->errorSummary,
            'sent_at' => $result->sent ? now() : null,
            'failed_at' => $result->sent ? null : now(),
        ]);
    }

    private function sendOtp(string $channel, string $destination, string $code): OtpDeliveryResult
    {
        try {
            return $this->deliveryChannelManager->send($channel, $destination, $code);
        } catch (\Throwable $e) {
            return OtpDeliveryResult::failed($e->getMessage());
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolvedChannels(User $user): array
    {
        $channels = config('security.otp.delivery_channels', [OtpDelivery::CHANNEL_SMS, OtpDelivery::CHANNEL_EMAIL]);

        if (! is_array($channels)) {
            $channels = [OtpDelivery::CHANNEL_SMS];
        }

        $resolved = array_values(array_unique(array_filter($channels, static fn (mixed $channel): bool => is_string($channel) && $channel !== '')));
        $requireEmail = config('security.otp.require_email_delivery', true) === true;

        if ($requireEmail && is_string($user->email) && $user->email !== '' && ! in_array(OtpDelivery::CHANNEL_EMAIL, $resolved, true)) {
            $resolved[] = OtpDelivery::CHANNEL_EMAIL;
        }

        return $resolved;
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
