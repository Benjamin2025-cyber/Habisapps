<?php

declare(strict_types=1);

namespace App\Support\Otp;

use Illuminate\Support\Facades\Mail;
use Throwable;

final class MailOtpDeliveryChannel implements OtpDeliveryChannel
{
    public function send(string $channel, string $destination, string $code): OtpDeliveryResult
    {
        if ($channel !== 'email') {
            return OtpDeliveryResult::failed('The configured OTP mail provider supports email only.');
        }

        $fromAddress = $this->stringConfig('mail.from.address');
        $fromName = $this->stringConfig('mail.from.name')
            ?? 'HABIS';
        if ($fromAddress === null) {
            return OtpDeliveryResult::failed('OTP mail provider is not configured.');
        }

        try {
            $subject = 'Your HABIS verification code';
            $textBody = $this->textBody($code);

            Mail::raw($textBody, function ($message) use ($destination, $fromAddress, $fromName, $subject): void {
                $message->to($destination)
                    ->from($fromAddress, $fromName)
                    ->subject($subject);

                $message->getHeaders()
                    ->addTextHeader('X-Auto-Response-Suppress', 'All');
                $message->getHeaders()
                    ->addTextHeader('Auto-Submitted', 'auto-generated');
            });
        } catch (Throwable $exception) {
            return OtpDeliveryResult::failed('OTP mail provider request failed: '.$exception->getMessage());
        }

        return OtpDeliveryResult::sent('mail-delivery');
    }

    private function stringConfig(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function textBody(string $code): string
    {
        $expiry = $this->expiresMinutes();

        return implode("\n", [
            'HABIS verification code',
            '',
            sprintf('Your one-time verification code is: %s', $code),
            sprintf('This code expires in %d minutes.', $expiry),
            '',
            'If you did not request this code, you can ignore this message.',
            '',
            'HABIS Security Team',
        ]);
    }

    private function expiresMinutes(): int
    {
        $value = config('security.otp.expires_minutes', 10);

        return is_int($value) && $value > 0 ? $value : 10;
    }
}
