<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The accounting team reviewed the loan form: « le code de l'activité financée
 * … ne semble pas nécessaire étant donné qu'on aura déjà sélectionné le secteur
 * et le sous-secteur correspondants ». Nothing reads it — classification lives
 * on sector_id/sub_sector_id, which the reporting uses — so the free-text code
 * goes away rather than staying as a second, competing activity descriptor.
 *
 * The periodicity and total-duration fields stay: they are no longer inputs at
 * all, but derived values the workflow writes (product duration unit for the
 * periodicity; application date through last installment for the total), so
 * existing rows keep theirs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn('financed_activity_code');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->string('financed_activity_code', 64)->nullable()->after('purpose');
        });
    }
};
