<?php

declare(strict_types=1);

namespace App\Support\Accounts;

use App\Support\References\ReferenceNumberGenerator;

/**
 * « Les numéros de comptes doivent représenter les numéros comptables auxquels
 * ils correspondent » — CORRECTIONS HABISLOAN 14/09/2026, troisième
 * préoccupation, and the same rule stated for loan accounts in the 04/09
 * document:
 *
 *     4200011001              3282001001
 *     │   │  └── code agence  │   │  └── code agence
 *     │   └───── n° d'ordre   │   └───── n° d'ordre du prêt
 *     └───────── compte       └───────── compte comptable
 *
 * Ten digits: four for the general-ledger account, three for the order number
 * within that account at that agency, three for the agency. Their own example
 * is the employee account 4200011001 — « un sous compte de la classe 4, compte
 * général 42 intitulé PERSONNEL » — which is where the right-padding rule comes
 * from: 42 occupies the field as 4200, its own root sub-account.
 *
 * The order number is drawn from the shared reference sequence, so it is
 * locked and incremented exactly like every other numbered series and two
 * accounts opened at once on the same ledger cannot collide.
 */
final class CustomerAccountNumberGenerator
{
    private const int LEDGER_WIDTH = 4;

    private const int ORDER_WIDTH = 3;

    private const int AGENCY_WIDTH = 3;

    public function __construct(
        private readonly ReferenceNumberGenerator $references,
    ) {}

    /**
     * @param  string  $ledgerCode  The general-ledger account the customer
     *                              account posts to, e.g. `42`, `3282`.
     * @param  string|null  $agencyCode  The agency's own code, e.g. `001`.
     */
    public function generate(string $ledgerCode, ?string $agencyCode): string
    {
        $ledger = $this->ledgerField($ledgerCode);
        $agency = $this->agencyField($agencyCode);

        // reserveSeries appends the counter to the prefix, which is exactly the
        // first seven digits; the agency completes the number.
        return $this->references->reserveSeries(
            key: "customer_account.{$ledger}.{$agency}",
            prefix: $ledger,
            padding: self::ORDER_WIDTH,
        ).$agency;
    }

    /**
     * Four digits. A shorter code is right-padded, because in a hierarchical
     * chart `42` and `4200` are the same account — padding left would turn
     * PERSONNEL into `0042` and place it in class 0, which does not exist.
     */
    private function ledgerField(string $ledgerCode): string
    {
        $digits = preg_replace('/\D/', '', $ledgerCode) ?? '';

        if ($digits === '') {
            return str_repeat('0', self::LEDGER_WIDTH);
        }

        return substr(str_pad($digits, self::LEDGER_WIDTH, '0', STR_PAD_RIGHT), 0, self::LEDGER_WIDTH);
    }

    /**
     * Three digits, left-padded: agency 1 is `001`, and a longer code keeps its
     * significant end rather than its leading zeros.
     */
    private function agencyField(?string $agencyCode): string
    {
        $digits = preg_replace('/\D/', '', $agencyCode ?? '') ?? '';

        if ($digits === '') {
            return str_repeat('0', self::AGENCY_WIDTH);
        }

        return substr(str_pad($digits, self::AGENCY_WIDTH, '0', STR_PAD_LEFT), -self::AGENCY_WIDTH);
    }
}
