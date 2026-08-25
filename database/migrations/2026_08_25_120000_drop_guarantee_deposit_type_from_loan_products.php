<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The guarantee deposit is a rate, never a franc amount.
 *
 * « La valeur du dossier de garantie est en pourcentage, pas en FCFA. »
 * `guarantee_deposit_value` is therefore always read as a percentage of the
 * granted principal, and the `percentage | fixed` discriminator that used to
 * sit beside it has nothing left to discriminate.
 *
 * Any product still carrying `fixed` had its value interpreted as whole minor
 * units; reading that same number as a percentage would be nonsense, so the
 * value is cleared rather than silently reinterpreted. Those products fall back
 * to no deposit until someone sets a rate, which fails safe: a deposit that is
 * not collected is recoverable, one collected at the wrong amount is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('loan_products')
            ->where('guarantee_deposit_type', 'fixed')
            ->update(['guarantee_deposit_value' => null]);

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropColumn('guarantee_deposit_type');
        });
    }

    /**
     * Structural only. Products whose fixed amount was cleared above do not get
     * it back, and every surviving row is a percentage, so the restored column
     * is left null rather than guessed at.
     */
    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->string('guarantee_deposit_type', 32)->nullable()->after('dossier_fee_tax_rate');
        });
    }
};
