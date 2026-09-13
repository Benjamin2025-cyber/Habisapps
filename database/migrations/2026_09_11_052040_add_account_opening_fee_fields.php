<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account opening fees — « frais d'ouverture de compte ».
 *
 * The accounting team asked that the first transaction on an account sweep the
 * account's opening fee straight to the income account, without anyone posting
 * it by hand. Two columns make that possible:
 *
 * - account_products.opening_fee_minor: the fee the product charges. A fee is
 *   financial data, not a free-form rule, so it gets its own column rather than
 *   a key inside `rules` — the penalty-formula feedback taught that hidden
 *   config keys nobody validates are worse than columns nobody guesses.
 * - customer_accounts.opening_fee_collected_at: the "already taken" marker.
 *   Detection by "no prior transaction" alone would re-charge the fee after the
 *   first deposit is reversed, and two concurrent first transactions could both
 *   see no history. The stamp is set inside the same transaction that posts the
 *   fee, under a row lock, so it is both idempotent and reversal-proof: the fee
 *   is owed for opening the account, not per transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_products', function (Blueprint $table): void {
            $table->bigInteger('opening_fee_minor')->default(0)->after('minimum_balance_minor');
        });

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->timestamp('opening_fee_collected_at')->nullable()->after('unavailable_amount_minor');
        });
    }

    public function down(): void
    {
        Schema::table('account_products', function (Blueprint $table): void {
            $table->dropColumn('opening_fee_minor');
        });

        Schema::table('customer_accounts', function (Blueprint $table): void {
            $table->dropColumn('opening_fee_collected_at');
        });
    }
};
