<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let one face value exist as both a banknote and a coin.
 *
 * `denominations` was UNIQUE (currency, value_minor), which identified a
 * denomination by what it is worth. In the CEMAC zone the 500 F circulates as
 * both a note (gamme 2020) and a coin (Type 2024), so that constraint forced a
 * teller receiving 500 F coins to record them on the 500 F note line — a cash
 * count that no longer describes what is physically in the drawer, and a
 * reconciliation that cannot be checked against it.
 *
 * The uniqueness that was worth having — no two rows for the same piece — is
 * kept by adding `type` to the key. UNIQUE (currency, code) is untouched, so
 * codes stay unambiguous either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('denominations', function (Blueprint $table): void {
            $table->dropUnique('denominations_currency_value_minor_unique');
            $table->unique(['currency', 'value_minor', 'type']);
        });
    }

    public function down(): void
    {
        // Only reversible while no value is held in two forms at once, which is
        // exactly what this migration exists to allow: rolling back with both a
        // 500 note and a 500 coin present would fail on the re-created index.
        Schema::table('denominations', function (Blueprint $table): void {
            $table->dropUnique('denominations_currency_value_minor_type_unique');
            $table->unique(['currency', 'value_minor']);
        });
    }
};
