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
        Schema::create('hr_payroll_formula_sets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('jurisdiction', 32)->default('cm')->index();
            $table->string('currency', 3)->default('XAF');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->foreignId('source_regulatory_source_id')->nullable()->constrained('regulatory_sources')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version'], 'uniq_hr_payroll_formula_code_version');
        });

        DB::statement(<<<'SQL'
ALTER TABLE hr_payroll_formula_sets ADD CONSTRAINT hr_payroll_formula_sets_status_valid
  CHECK (status IN ('draft', 'active', 'superseded', 'archived'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE hr_payroll_formula_sets ADD CONSTRAINT hr_payroll_formula_sets_source_required
  CHECK (source_regulatory_source_id IS NOT NULL)
SQL);

        Schema::create('hr_employee_agency_history', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('reason', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('hr_payroll_formula_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hr_payroll_formula_set_id')->constrained('hr_payroll_formula_sets')->cascadeOnDelete();
            $table->string('branch', 32);
            $table->string('sector', 32)->nullable();
            $table->string('payer', 16);
            $table->decimal('rate', 8, 4);
            $table->bigInteger('ceiling_minor')->nullable();
            $table->string('basis', 32)->default('gross_salary');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['hr_payroll_formula_set_id', 'branch', 'sector', 'payer'], 'idx_hr_payroll_formula_rates_axis');
        });

        DB::statement(<<<'SQL'
ALTER TABLE hr_payroll_formula_rates ADD CONSTRAINT hr_payroll_formula_rates_payer_valid
  CHECK (payer IN ('employer', 'employee'))
SQL);
        DB::statement(<<<'SQL'
ALTER TABLE hr_payroll_formula_rates ADD CONSTRAINT hr_payroll_formula_rates_rate_non_negative
  CHECK (rate >= 0 AND (ceiling_minor IS NULL OR ceiling_minor >= 0))
SQL);

        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->foreignId('hr_payroll_formula_set_id')->nullable()->after('agency_id')->constrained('hr_payroll_formula_sets')->nullOnDelete();
            $table->json('formula_snapshot')->nullable()->after('hr_payroll_formula_set_id');
            $table->foreignId('correction_of_run_id')->nullable()->after('formula_snapshot')->constrained('hr_payroll_runs')->nullOnDelete();
            $table->foreignId('reversal_of_run_id')->nullable()->after('correction_of_run_id')->constrained('hr_payroll_runs')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->after('reversal_of_run_id')->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->after('reversal_of_run_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
        });

        Schema::table('hr_contracts', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('contract_number');
            $table->foreignId('predecessor_contract_id')->nullable()->after('version')->constrained('hr_contracts')->nullOnDelete();
        });

        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->foreignId('requested_by_user_id')->nullable()->after('reason')->constrained('users')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
ALTER TABLE hr_leave_requests ADD CONSTRAINT hr_leave_requests_status_valid
  CHECK (status IN ('pending', 'approved', 'rejected', 'cancelled'))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE hr_leave_requests DROP CONSTRAINT IF EXISTS hr_leave_requests_status_valid');
        Schema::table('hr_leave_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by_user_id');
        });

        Schema::table('hr_contracts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('predecessor_contract_id');
            $table->dropColumn('version');
        });

        Schema::table('hr_payroll_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn('approved_at');
            $table->dropConstrainedForeignId('created_by_user_id');
            $table->dropConstrainedForeignId('reversal_of_run_id');
            $table->dropConstrainedForeignId('correction_of_run_id');
            $table->dropColumn('formula_snapshot');
            $table->dropConstrainedForeignId('hr_payroll_formula_set_id');
        });

        Schema::dropIfExists('hr_employee_agency_history');

        DB::statement('ALTER TABLE hr_payroll_formula_rates DROP CONSTRAINT IF EXISTS hr_payroll_formula_rates_rate_non_negative');
        DB::statement('ALTER TABLE hr_payroll_formula_rates DROP CONSTRAINT IF EXISTS hr_payroll_formula_rates_payer_valid');
        Schema::dropIfExists('hr_payroll_formula_rates');

        DB::statement('ALTER TABLE hr_payroll_formula_sets DROP CONSTRAINT IF EXISTS hr_payroll_formula_sets_source_required');
        DB::statement('ALTER TABLE hr_payroll_formula_sets DROP CONSTRAINT IF EXISTS hr_payroll_formula_sets_status_valid');
        Schema::dropIfExists('hr_payroll_formula_sets');
    }
};
