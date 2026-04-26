<?php

declare(strict_types=1);

namespace App\Support\Otp;

use InvalidArgumentException;

final class OtpDeliveryChannelManager
{
    public function driver(): OtpDeliveryChannel
    {
        $provider = config('security.otp.delivery_provider', 'log');
        $providerName = is_string($provider) ? $provider : 'log';

        return match ($providerName) {
            'log' => app(LogOtpDeliveryChannel::class),
            default => throw new InvalidArgumentException(sprintf('Unsupported OTP delivery provider [%s].', $providerName)),
        };
    }
}
