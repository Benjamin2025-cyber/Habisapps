<?php

declare(strict_types=1);

namespace App\Support\Otp;

interface OtpDeliveryChannel
{
    public function send(string $channel, string $destination, string $code): OtpDeliveryResult;
}
