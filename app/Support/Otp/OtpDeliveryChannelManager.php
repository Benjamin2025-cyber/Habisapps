<?php

declare(strict_types=1);

namespace App\Support\Otp;

use InvalidArgumentException;

final class OtpDeliveryChannelManager
{
    public function send(string $channel, string $destination, string $code): OtpDeliveryResult
    {
        return $this->driverForChannel($channel)->send($channel, $destination, $code);
    }

    private function driverForChannel(string $channel): OtpDeliveryChannel
    {
        return match ($channel) {
            'sms' => $this->smsDriver(),
            'email' => $this->emailDriver(),
            default => throw new InvalidArgumentException(sprintf('Unsupported OTP delivery channel [%s].', $channel)),
        };
    }

    private function smsDriver(): OtpDeliveryChannel
    {
        $provider = config('security.otp.delivery_provider', 'log');
        $providerName = is_string($provider) ? $provider : 'log';

        return match ($providerName) {
            'log' => app(LogOtpDeliveryChannel::class),
            'http_sms' => app(HttpSmsOtpDeliveryChannel::class),
            default => throw new InvalidArgumentException(sprintf('Unsupported SMS OTP delivery provider [%s].', $providerName)),
        };
    }

    private function emailDriver(): OtpDeliveryChannel
    {
        $provider = config('security.otp.email_provider', 'log');
        $providerName = is_string($provider) ? $provider : 'log';

        return match ($providerName) {
            'log' => app(LogOtpDeliveryChannel::class),
            'mail' => app(MailOtpDeliveryChannel::class),
            default => throw new InvalidArgumentException(sprintf('Unsupported email OTP delivery provider [%s].', $providerName)),
        };
    }
}
