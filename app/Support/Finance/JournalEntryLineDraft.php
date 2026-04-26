<?php

declare(strict_types=1);

namespace App\Support\Finance;

use InvalidArgumentException;

final readonly class JournalEntryLineDraft
{
    private function __construct(
        public string $accountCode,
        public LedgerLineType $type,
        public MoneyAmount $amount,
    ) {}

    public static function make(string $accountCode, LedgerLineType $type, MoneyAmount $amount): self
    {
        if (trim($accountCode) === '') {
            throw new InvalidArgumentException('Journal entry line account code is required.');
        }

        if ($amount->isZero()) {
            throw new InvalidArgumentException('Journal entry line amount must be non-zero.');
        }

        return new self($accountCode, $type, $amount);
    }
}
