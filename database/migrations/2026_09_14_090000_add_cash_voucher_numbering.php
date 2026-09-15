<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash voucher numbering — « la numérotation des pièces de caisse doit
 * respecter la nomenclature suivante : le type de document + le code du
 * guichet + année en cours + mois en cours + numéro de reçu à 6 chiffres »
 * (CORRECTIONS HABISLOAN 14/09/2026, deuxième préoccupation).
 *
 * The 6-digit counter itself is drawn from the existing `reference_sequences`
 * table by ReferenceNumberGenerator, so nothing new is needed for it.
 *
 * This migration adds the depositor's identity-document reference, which the
 * same document asks to appear on the receipt and is « saisi manuellement lors de
 * l'opération au guichet ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->string('depositor_id_reference', 100)->nullable()->after('depositor_address');
        });
    }

    public function down(): void
    {
        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropColumn('depositor_id_reference');
        });
    }
};
