<?php

declare(strict_types=1);

namespace App\Support\Otp;

use Illuminate\Support\Facades\Http;
use Throwable;

final class HttpSmsOtpDeliveryChannel implements OtpDeliveryChannel
{
    public function send(string $channel, string $destination, string $code): OtpDeliveryResult
    {
        if ($channel !== 'sms') {
            return OtpDeliveryResult::failed('The configured OTP provider supports SMS only.');
        }

        $endpoint = $this->stringConfig('security.otp.http_sms.endpoint');
        $token = $this->stringConfig('security.otp.http_sms.token');
        if ($endpoint === null || $token === null) {
            return OtpDeliveryResult::failed('HTTP SMS OTP provider is not configured.');
        }

        try {
            $response = Http::asJson()
                ->timeout($this->timeout())
                ->withToken($token)
                ->post($endpoint, [
                    'to' => $destination,
                    'message' => sprintf('Your HABIS verification code is %s.', $code),
                    'sender' => $this->stringConfig('security.otp.http_sms.sender'),
                ]);
        } catch (Throwable $exception) {
            return OtpDeliveryResult::failed('HTTP SMS OTP provider request failed: '.$exception->getMessage());
        }

        if ($response->failed()) {
            return OtpDeliveryResult::failed('HTTP SMS OTP provider returned HTTP '.$response->status().'.');
        }

        return OtpDeliveryResult::sent($this->providerReference($response->json()));
    }

    private function timeout(): int
    {
        $timeout = config('security.otp.http_sms.timeout_seconds', 10);

        return is_int($timeout) && $timeout > 0 ? $timeout : 10;
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function providerReference(mixed $payload): ?string
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['message_id', 'reference', 'id'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
