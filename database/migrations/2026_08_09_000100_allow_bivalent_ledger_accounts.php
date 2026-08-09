<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        // Bivalent accounts have no side to fall back to, and inventing one
        // would silently flip the sign of their balance. Settle them first.
        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->string('normal_balance_side', 6)->nullable(false)->change();
        });
    }
};
