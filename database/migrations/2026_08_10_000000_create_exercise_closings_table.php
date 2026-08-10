<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clôture annuelle — the record of an exercise having been closed.
 *
 * The accounting team, 2026-08-10: « à la fin de l'exercice, le résultat du 87
 * doit être transféré dans le 131 s'il est positif (bénéfice) ou dans le 132 s'il
 * est négatif (perte) ». That transfer is a journal entry like any other; this
 * table is what stops it happening twice and records which entry did it.
 *
 * Per agency, because journal_entries.agency_id is NOT NULL and every agency
 * keeps its own 131/132 under the same codes. The institution's result is then
 * the consolidation of the agencies' result accounts, which is how every other
 * total in this chart reaches head office.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exercise_closings', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();

            // The calendar year the exercise *starts* in, which is how it is
            // named even when the fiscal year runs across two of them.
            $table->smallInteger('fiscal_year');
            $table->date('opens_on');
            $table->date('closes_on');
            $table->char('currency', 3);

            // The résultat net as it stood when the closing was drawn, kept so the
            // figure can be audited against the journal entry rather than
            // recomputed from a chart that may since have changed.
            $table->bigInteger('net_result_minor');
            $table->string('result_account_code', 16);

            // Nullable so the closing survives the entry being purged, and so the
            // row can be written in the same transaction as the entry it names.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One closing per agency, per exercise, per currency. This is the
            // guard that matters: closing twice would transfer the result twice
            // and leave classes 6 and 7 negative by the same amount.
            $table->unique(['agency_id', 'fiscal_year', 'currency'], 'uniq_exercise_closing_agency_year_currency');
            $table->index(['agency_id', 'closes_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exercise_closings');
    }
};
