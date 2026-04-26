<?php

declare(strict_types=1);

namespace App\Support\Otp;

final readonly class OtpDeliveryResult
{
    private function __construct(
        public bool $sent,
        public ?string $providerReference,
        public ?string $errorSummary,
    ) {}

    public static function sent(?string $providerReference = null): self
    {
        return new self(true, $providerReference, null);
    }

    public static function failed(string $errorSummary): self
    {
        return new self(false, null, $errorSummary);
    }
}
