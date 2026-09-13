<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OperationCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The account-posting operations the application resolves *by name*.
 *
 * `account_opening_fee` is the credit leg of the automatic opening-fee sweep:
 * the first transaction on a customer account posts « frais d'ouverture » to
 * the income account the institution maps here. The debit leg is the customer's
 * own ledger, which the workflow knows already, so the mapping only needs a
 * credit.
 *
 * WHICH INCOME ACCOUNT — the question the feedback left open.
 *
 * The accounting team's note asked for « 7611 — Produits sur opérations
 * d'ouverture de comptes, pouvant avoir plusieurs sous-comptes selon les types
 * de comptes ». That account is not in the chart this application loads
 * (database/data/pcemf-chart.json, taken from the institution's own manuel de
 * procédures comptables), and the root it would sit under is the wrong one:
 * `76` is SUBVENTIONS D'EXPLOITATION ET D'ÉQUILIBRE — money received from a
 * donor or the State, not money billed at the counter. A fee charged to a
 * customer is a commission. Posting it under 76 would leave the net result
 * right while putting the detail line, and the COBAC regulatory mapping that
 * hangs off it, in the wrong place.
 *
 * The chart already carries the correct home, postable and seeded per agency:
 *
 *     720 — Commissions de tenue de compte   (root 72, PRODUITS SUR OPÉRATIONS DIVERSES)
 *
 * An opening fee is the first of the account-keeping commissions, so `720` is
 * what the institution should map this code to. The "one sub-account per
 * account type" half of the request needs no chart change either: each account
 * product can name its own income account
 * (`account_products.opening_fee_ledger_account_id`), so a structure can run
 * everything through 720 today and split it later without migrating anything.
 *
 * Deliberately left as guidance and not seeded as a mapping — see below.
 *
 * Same rationale as LoanOperationCodeSeeder: only the codes are fixed by the
 * application; the mappings stay the institution's chart decision. Wired into
 * DatabaseSeeder so an existing install picks the code up on re-run — without
 * it, an account product with an opening fee would block every first deposit
 * behind a mapping that the catalogue had no way to offer.
 */
final class AccountOperationCodeSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, label: string, direction: string}>
     */
    private const array CODES = [
        ['code' => 'account_opening_fee', 'label' => "Frais d'ouverture de compte", 'direction' => 'credit'],
    ];

    public function run(): void
    {
        foreach (self::CODES as $definition) {
            /*
             * Keyed on the code, and deliberately not an updateOrInsert: that
             * would rewrite `public_id` with a fresh ULID on every re-run,
             * changing the identity of a row other records and screens address
             * by it, and would reset an institution's own label and status
             * along with it. Only the two fields the application actually
             * resolves on — module and direction — are repaired; identity and
             * the institution's wording are left alone.
             */
            $exists = DB::table('operation_codes')->where('code', $definition['code'])->exists();
            if ($exists) {
                DB::table('operation_codes')
                    ->where('code', $definition['code'])
                    ->update([
                        'module' => 'accounting',
                        'direction' => $definition['direction'],
                        'updated_at' => now(),
                    ]);

                continue;
            }

            DB::table('operation_codes')->insert([
                'public_id' => (string) Str::ulid(),
                'code' => $definition['code'],
                'label' => $definition['label'],
                'module' => 'accounting',
                'direction' => $definition['direction'],
                'status' => OperationCode::STATUS_ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
