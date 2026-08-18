<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Three booleans on the account product were written and read by nothing. They
 * are dropped rather than wired, because the PCEMF places what they describe
 * somewhere else.
 *
 * `is_recovery_account` duplicates `account_family`, which already carries the
 * value `recovery` — and a second copy of a fact is the copy that goes stale.
 *
 * `allows_recovery_debit` is a standing-order permission: leave to debit a
 * client's account for a repayment without them at the counter. Nothing in this
 * application collects a repayment automatically yet, so it authorised nothing.
 * It belongs with that feature, not ahead of it.
 *
 * `is_ordinary_savings` restates the deposit's regime, which the chart already
 * fixes by the account a product maps to: 35 régime spécial, 36 à terme, 37 à
 * vue. Ordinary savings is what those leave over, so the flag can only ever
 * agree with the mapping or contradict it.
 *
 * Recovery itself is not lost. In the chart it is a state of the receivable —
 * 331 créances impayées, 332 immobilisées, 333 à 335 douteuses — reached by
 * moving a loan's outstanding out of class 32, not by nominating a deposit
 * account in advance. That transfer is a feature this application does not have
 * yet: arrears are computed and never posted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_products', function (Blueprint $table): void {
            $table->dropColumn([
                'is_recovery_account',
                'allows_recovery_debit',
                'is_ordinary_savings',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('account_products', function (Blueprint $table): void {
            $table->boolean('allows_recovery_debit')->default(false);
            $table->boolean('is_recovery_account')->default(false);
            $table->boolean('is_ordinary_savings')->default(false);
        });
    }
};
