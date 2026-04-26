<?php

declare(strict_types=1);

namespace App\Support\Finance;

use InvalidArgumentException;

final readonly class JournalEntryDraft
{
    /** @var array<int, JournalEntryLineDraft> */
    public array $lines;

    /**
     * @param  array<int, JournalEntryLineDraft>  $lines
     */
    private function __construct(array $lines)
    {
        $this->lines = $lines;
    }

    public static function make(JournalEntryLineDraft ...$lines): self
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A journal entry requires at least two lines.');
        }

        $currency = $lines[0]->amount->currency();
        $debits = MoneyAmount::zero($currency);
        $credits = MoneyAmount::zero($currency);

        foreach ($lines as $line) {
            if ($line->amount->currency() !== $currency) {
                throw new InvalidArgumentException('A journal entry cannot mix currencies.');
            }

            if ($line->type === LedgerLineType::Debit) {
                $debits = $debits->plus($line->amount);
            } else {
                $credits = $credits->plus($line->amount);
            }
        }

        if (! $debits->isEqualTo($credits)) {
            throw new InvalidArgumentException(sprintf(
                'Journal entry is not balanced: debits=%s %s, credits=%s %s.',
                $debits->amount(),
                $debits->currency(),
                $credits->amount(),
                $credits->currency()
            ));
        }

        return new self(array_values($lines));
    }
}
