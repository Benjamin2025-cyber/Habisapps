<?php

declare(strict_types=1);

namespace App\Support\CashOperations;

use App\Models\TellerTransaction;
use App\Support\References\ReferenceNumberGenerator;
use DateTimeInterface;

/**
 * Builds the cash voucher number the accounting team asked for:
 *
 *     REC-G001-2026-09-000042
 *     │   │    │    │  └── 6-digit counter, restarts each month
 *     │   │    │    └───── month
 *     │   │    └────────── year
 *     │   └─────────────── code guichet
 *     └─────────────────── document type
 *
 * (CORRECTIONS HABISLOAN 14/09/2026, deuxième préoccupation.)
 *
 * The counter itself is drawn by ReferenceNumberGenerator, so cash vouchers are
 * locked and incremented exactly like every other numbered series in the system
 * — and a cash operation that rolls back hands its number back, which is what a
 * voucher series wants: an auditor reads a gap as a missing document.
 */
final class CashVoucherNumberGenerator
{
    /**
     * « Le type de document ». A versement and a retrait are distinct voucher
     * kinds, each numbered from 1, exactly as on the paper pads they replace.
     */
    private const array TYPE_PREFIXES = [
        TellerTransaction::TYPE_CASH_DEPOSIT => 'REC',
        TellerTransaction::TYPE_CASH_WITHDRAWAL => 'RET',
    ];

    /** Any other cash document: « pièce de caisse ». */
    private const string FALLBACK_PREFIX = 'PCE';

    private const int COUNTER_WIDTH = 6;

    public function __construct(
        private readonly ReferenceNumberGenerator $references,
    ) {}

    /**
     * @param  string  $transactionType  One of TellerTransaction::TYPE_*.
     * @param  string|null  $agencyCode  Code guichet — the agency's own code.
     * @param  DateTimeInterface  $bookedOn  The accounting date, not the wall
     *                                       clock: an operation booked on the
     *                                       last day of the month belongs to
     *                                       that month's series even if it is
     *                                       keyed after midnight.
     */
    public function generate(string $transactionType, ?string $agencyCode, DateTimeInterface $bookedOn): string
    {
        $type = self::TYPE_PREFIXES[$transactionType] ?? self::FALLBACK_PREFIX;
        $guichet = 'G'.str_pad($this->normaliseAgencyCode($agencyCode), 3, '0', STR_PAD_LEFT);
        $period = $bookedOn->format('Y-m');

        $series = "{$type}-{$guichet}-{$period}";

        return $this->references->reserveSeries(
            key: 'cash_voucher.'.$series,
            prefix: $series.'-',
            padding: self::COUNTER_WIDTH,
        );
    }

    /**
     * Agency codes are already numeric strings ("001"), but nothing stops one
     * being recorded as "AG-1" or left blank. Keep the digits so the guichet is
     * always well formed; a code longer than the field is truncated at its
     * significant end, not at its leading zeros.
     */
    private function normaliseAgencyCode(?string $agencyCode): string
    {
        $digits = preg_replace('/\D/', '', $agencyCode ?? '') ?? '';

        return $digits === '' ? '0' : substr($digits, -3);
    }
}
