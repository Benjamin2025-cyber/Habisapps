<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->decimal('dossier_fee_tax_rate', 12, 6)->nullable()->default('19.25')->after('fee_rate');
        });

        DB::table('loan_products')->update([
            'interest_policy_key' => 'loan_interest_method',
            'penalty_policy_key' => 'penalties_and_arrears',
            'repayment_allocation_policy_key' => 'repayment_allocation_order',
            'fee_policy_key' => 'fees_taxes_insurance',
            'tax_policy_key' => 'fees_taxes_insurance',
            'insurance_policy_key' => 'fees_taxes_insurance',
            'guarantee_deposit_policy_key' => 'fees_taxes_insurance',
        ]);

        DB::table('loan_products')
            ->select(['id', 'rules'])
            ->orderBy('id')
            ->get()
            ->each(function (object $productRow): void {
                $rules = is_string($productRow->rules)
                    ? json_decode($productRow->rules, true, 512, JSON_THROW_ON_ERROR)
                    : [];

                DB::table('loan_products')
                    ->where('id', $productRow->id)
                    ->update([
                        'rules' => json_encode(array_replace_recursive(
                            is_array($rules) ? $rules : [],
                            [
                                'formula_policies' => [
                                    'rounding_policy_key' => 'xaf_rounding',
                                    'schedule_policy_key' => 'loan_installment_amount',
                                    'reporting_policy_key' => 'portfolio_reporting_metrics',
                                ],
                            ],
                        ), JSON_THROW_ON_ERROR),
                    ]);
            });

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropColumn([
                'penalty_formula_type',
                'penalty_formula_base',
                'penalty_value_type',
                'penalty_value',
            ]);
        });
    }

    /**
     * Structural only. The legacy penalty columns come back empty and the data
     * mutations above are not reversed, so a down/up cycle loses every
     * product's legacy penalty configuration. Restore from a backup rather
     * than round-tripping this migration.
     */
    public function down(): void
    {
        Schema::table('loan_products', function (Blueprint $table): void {
            $table->string('penalty_formula_type', 64)->nullable()->after('guarantee_deposit_value');
            $table->string('penalty_formula_base', 64)->nullable()->after('penalty_formula_type');
            $table->string('penalty_value_type', 32)->nullable()->after('penalty_formula_base');
            $table->decimal('penalty_value', 18, 6)->nullable()->after('penalty_value_type');
        });

        Schema::table('loan_products', function (Blueprint $table): void {
            $table->dropColumn('dossier_fee_tax_rate');
        });
    }
};
