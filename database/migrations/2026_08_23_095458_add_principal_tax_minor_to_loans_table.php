<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT on the credit itself gets its own column, separate from the VAT on the
 * dossier fee.
 *
 * The projection check constraint is rebuilt rather than left alone: it asserts
 * that every stored projection amount is non-negative, and a new amount column
 * that is not named in it is simply unguarded. Skipping the rebuild would also
 * make a freshly loaded schema and a migrated database disagree, since the
 * squashed dump carries the constraint in its final form.
 */
return new class extends Migration
{
    private const string PROJECTION_CHECK = 'loans_projection_amounts_non_negative';

    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->bigInteger('principal_tax_minor')->nullable()->after('dossier_fees_tax_minor');
        });

        $this->rebuildProjectionCheck(withPrincipalTax: true);
    }

    public function down(): void
    {
        $this->rebuildProjectionCheck(withPrincipalTax: false);

        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn('principal_tax_minor');
        });
    }

    private function rebuildProjectionCheck(bool $withPrincipalTax): void
    {
        $columns = [
            'dossier_fees_minor',
            'dossier_fees_tax_minor',
            ...($withPrincipalTax ? ['principal_tax_minor'] : []),
            'guarantee_deposit_amount_minor',
            'insurance_amount_minor',
            'outstanding_principal_minor',
            'installment_amount_minor',
            'total_unpaid_amount_minor',
            'due_amount_minor',
        ];

        $predicate = implode(' AND ', array_map(
            static fn (string $column): string => sprintf('(%1$s IS NULL OR %1$s >= 0)', $column),
            $columns,
        ));

        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS '.self::PROJECTION_CHECK);
        DB::statement(sprintf('ALTER TABLE loans ADD CONSTRAINT %s CHECK (%s)', self::PROJECTION_CHECK, $predicate));
    }
};
