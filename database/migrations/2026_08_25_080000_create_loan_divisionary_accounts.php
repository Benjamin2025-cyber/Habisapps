<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Divisionary (per-loan) GL accounts, opened automatically at mise en place.
 *
 * The accounting team's rule: « Il n'y a pas de compte comptable par défaut car
 * chaque ligne de crédit entraîne automatiquement la création de plusieurs
 * comptes lors de la mise en place. » Each dossier therefore carries its own
 * balance-sheet accounts under the mapped control accounts — principal
 * receivable under the loan_principal_disbursement parent, guarantee held under
 * the loan_setup_guarantee_deposit parent — while income and VAT legs, penalties
 * included, stay on their shared accounts. PCEMF class 7 is not subdivided per
 * borrower.
 *
 * Dropping loan_products.ledger_account_id removes the "default account" the
 * rule rejects; operation-account mappings become the only source of control
 * accounts, which is already how every other posting leg resolves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            // Constrained like every other ledger reference in this schema
            // (account_products, customer_accounts): a chart account that is
            // deleted must not leave a loan pointing at a recycled id.
            $table->foreignId('loan_receivable_account_id')->nullable()->after('insurance_amount_minor')
                ->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('guarantee_held_account_id')->nullable()->after('loan_receivable_account_id')
                ->constrained('ledger_accounts')->nullOnDelete();
        });

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropForeign(['ledger_account_id']);
            $table->dropColumn('ledger_account_id');
        });
    }

    /**
     * Structural only: the divisionary accounts themselves are ordinary chart
     * rows and are left in place, and the product's former default account is
     * not restored. Reversing this migration puts the schema back, not the data.
     */
    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->foreignId('ledger_account_id')->nullable()
                ->constrained('ledger_accounts')->nullOnDelete();
        });

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropForeign(['loan_receivable_account_id']);
            $table->dropForeign(['guarantee_held_account_id']);
            $table->dropColumn([
                'loan_receivable_account_id',
                'guarantee_held_account_id',
            ]);
        });
    }
};
