<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The opening fee an account owes, fixed when the account is opened.
 *
 * Reading the fee off the live product made the sweep retroactive, and in the
 * worst possible way. Every account already in the book carries
 * `opening_fee_collected_at = NULL`, which the sweep reads as "not yet
 * charged" — so the day the accounting team sets « Frais d'ouverture = 5 000 »
 * on the savings product, which is precisely what the new field is for, the
 * next deposit of every customer opened under it in the last three years takes
 * 5 000 out of their account. Nobody asked for that posting and nobody would
 * see it coming.
 *
 * A tariff applies from the day it is set, which is also how the conditions
 * tarifaires actually work: what a customer owes for opening an account is
 * what the tariff said on the day they opened it, not what it says today. The
 * account therefore carries its own figure, copied from the product at
 * opening, and the sweep reads that.
 *
 * Defaulting to 0 is what makes the backfill safe: every existing account owes
 * nothing, so switching the fee on bills only the accounts opened afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->bigInteger('opening_fee_due_minor')->default(0)->after('unavailable_amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->dropColumn('opening_fee_due_minor');
        });
    }
};
