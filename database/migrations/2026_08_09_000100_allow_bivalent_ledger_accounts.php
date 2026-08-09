<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a ledger account have no imposed normal balance side.
 *
 * The accounting team's answer of 2026-08-09 (Plan des Comptes HF 2026) marks
 * eight accounts as *bivalents*: 45 and 47 (comptes de liaison, comptes de
 * régularisation), 52, 56, 94, 97, 98, 99. Their instruction:
 *
 *   « créer ces 8 comptes SANS sens imposé (pas de contrôle de cohérence
 *     débit/crédit dessus) ; le système doit accepter des écritures aussi bien
 *     au débit qu'au crédit sur chacun d'eux, et calculer le signe du solde
 *     (D ou C) selon la position réelle à chaque arrêté »
 *
 * A liaison account between two agencies is the clearest case: the same account
 * receives a transfer out on one day and a transfer in on another, so neither
 * side is "normal" for it.
 *
 * NULL expresses exactly that, and needs no new column: the side was only ever
 * read to decide which way round to subtract when presenting a balance. Nothing
 * validates an entry against it, so the "no blocking" half of the instruction
 * already held before this change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->string('normal_balance_side', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reversible only while no account actually uses the freedom this granted.
        // A bivalent account has no side to fall back to, and picking one for it
        // would invert the presented sign of every balance it carries — 45 and 47
        // are liaison and régularisation accounts, so that is real money reported
        // backwards, not a cosmetic default.
        //
        // Postgres would refuse the column change anyway, but with a bare NOT NULL
        // violation naming neither the accounts nor the remedy. Fail first, and
        // say what has to happen: decide a side for each of these accounts (which
        // is an accounting decision, not a schema one) before reverting.
        $bivalent = DB::table('ledger_accounts')
            ->whereNull('normal_balance_side')
            ->pluck('code')
            ->all();

        if ($bivalent !== []) {
            throw new RuntimeException(sprintf(
                'Cannot revert: %d ledger account(s) have no imposed balance side (%s). '
                .'Reverting would force a side on them and flip the reported sign of their balances. '
                .'Assign each an explicit debit or credit side first, then roll back.',
                count($bivalent),
                implode(', ', array_slice($bivalent, 0, 10)).(count($bivalent) > 10 ? ', …' : '')
            ));
        }

        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->string('normal_balance_side', 6)->nullable(false)->change();
        });
    }
};
