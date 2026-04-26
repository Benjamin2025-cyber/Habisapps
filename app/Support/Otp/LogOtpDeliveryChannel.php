<?php

declare(strict_types=1);

namespace App\Support\Otp;

use Illuminate\Support\Facades\Log;

final class LogOtpDeliveryChannel implements OtpDeliveryChannel
{
    public function send(string $channel, string $destination, string $code): OtpDeliveryResult
    {
        Log::info('OTP delivery requested through log provider.', [
            'channel' => $channel,
            'destination_hash' => hash('sha256', strtolower($destination)),
            'code_length' => strlen($code),
        ]);

        return OtpDeliveryResult::sent(app()->environment('testing') ? 'test-delivery' : 'log-delivery');
    }
}
