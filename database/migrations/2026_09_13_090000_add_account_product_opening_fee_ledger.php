<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-product income account for the opening fee.
 *
 * The accounting team's wording is explicit: the account-opening income account
 * may carry « plusieurs sous-comptes selon les types de comptes proposés par la
 * structure ». The operation mapping resolves one credit ledger per agency and
 * currency, so on its own it sends the fee of every account type to the same
 * account — the one thing the sentence asks not to happen.
 *
 * The product therefore names its own sub-account, and the mapping stays as the
 * institution-wide default for the products that do not. Nullable, because a
 * structure running a single income account should not have to fill this in on
 * every product it owns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_products', function (Blueprint $table): void {
            $table->foreignId('opening_fee_ledger_account_id')
                ->nullable()
                ->after('opening_fee_minor')
                ->constrained('ledger_accounts')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('account_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('opening_fee_ledger_account_id');
        });
    }
};
