<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dossier fee is a percentage of the principal, with no floor.
 *
 * « Le montant des frais de dossier doit être en pourcentage et sans plancher. »
 *
 * It was a fixed amount: a 500 000 loan and a 5 000 000 loan were charged the same
 * fee. A percentage was already computable, but only through a `dossier_fee_rate`
 * buried in the product's `rules` JSON — never a field, never on the form. So the
 * only thing an operator could configure was the fixed amount.
 *
 * `floor_amount_minor` goes with it. It was stored, validated and shown on the form
 * as « Montant plancher », and read by nothing: a floor could be set, saved and seen
 * to persist while never affecting a single fee. Dead configuration is worse than no
 * configuration — someone sets it, expects it to bite, and reports the fee as wrong.
 *
 * No back-compat shim: nothing is in production yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            // Same shape as the other rates on this product (interest, tax,
            // insurance), so a percentage reads and validates the same way
            // wherever it appears.
            $table->decimal('fee_rate', 12, 6)->nullable()->after('insurance_rate');
        });

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropColumn(['fee_amount_minor', 'floor_amount_minor']);
        });
    }

    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->bigInteger('fee_amount_minor')->nullable()->after('insurance_rate');
            $table->bigInteger('floor_amount_minor')->nullable()->after('fee_amount_minor');
        });

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropColumn('fee_rate');
        });
    }
};
