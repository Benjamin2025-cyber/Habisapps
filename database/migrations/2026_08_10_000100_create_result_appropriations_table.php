<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Affectation du résultat — what the assemblée générale decided to do with the
 * result once the accounts were approved.
 *
 * The clôture leaves the result in 131 (bénéfice) or 132 (perte). It stays there
 * until the AG allocates it: réserve légale, autres réserves, report à nouveau,
 * dividendes. Without that step 131 accumulates one exercise on top of the next
 * and the balance sheet overstates "Bénéfice de l'exercice" from the second year
 * onward, while the réserves never grow.
 *
 * Only the decision is recorded here. The allocation itself is the journal entry's
 * lines, so it is not copied into a second table that could disagree with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_appropriations', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 40)->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();

            $table->smallInteger('fiscal_year');
            $table->char('currency', 3);

            /** 131 when a bénéfice was allocated, 132 when a perte was absorbed. */
            $table->string('source_account_code', 16);

            /** Always the magnitude; source_account_code carries the direction. */
            $table->bigInteger('amount_minor');

            /** Date of the assemblée générale that approved the accounts. */
            $table->date('decided_on');

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // A result is allocated once. Twice would empty 131 and then overdraw
            // it, crediting the réserves with money the exercise never earned.
            $table->unique(['agency_id', 'fiscal_year', 'currency'], 'uniq_result_appropriation_agency_year_currency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_appropriations');
    }
};
