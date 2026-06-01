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
        Schema::create('accounting_days', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('scope_type', 16)->default('agency');
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->restrictOnDelete();
            $table->date('business_date');
            $table->timestamp('calendar_opened_at')->nullable();
            $table->timestamp('calendar_closed_at')->nullable();
            $table->string('status', 32)->default('open')->index();
            $table->boolean('is_holiday')->default(false);
            $table->string('holiday_name')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reopened_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('opening_batch_run_id')->nullable()->constrained('batch_runs')->nullOnDelete();
            $table->foreignId('closing_batch_run_id')->nullable()->constrained('batch_runs')->nullOnDelete();
            $table->json('close_summary_payload')->nullable();
            $table->text('close_failure_reason')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->string('origin', 32)->default('manual');
            $table->unsignedInteger('write_lock_version')->default(0);
            $table->timestamps();

            $table->index('business_date');
            $table->index('agency_id');
            $table->index('calendar_opened_at');
            $table->index('calendar_closed_at');
        });

        // Scope/agency coherence: agency scope must carry an agency, institution scope must not.
        DB::statement("ALTER TABLE accounting_days ADD CONSTRAINT accounting_days_scope_type_allowed CHECK (scope_type IN ('institution', 'agency'))");
        DB::statement("ALTER TABLE accounting_days ADD CONSTRAINT accounting_days_scope_agency_consistent CHECK ((scope_type = 'agency' AND agency_id IS NOT NULL) OR (scope_type = 'institution' AND agency_id IS NULL))");
        DB::statement("ALTER TABLE accounting_days ADD CONSTRAINT accounting_days_status_allowed CHECK (status IN ('planned', 'open', 'closing', 'closed', 'reopened', 'cancelled'))");

        // One accounting day per scope and business date.
        DB::statement("CREATE UNIQUE INDEX uniq_accounting_day_agency_date ON accounting_days (agency_id, business_date) WHERE scope_type = 'agency'");
        DB::statement("CREATE UNIQUE INDEX uniq_accounting_day_institution_date ON accounting_days (business_date) WHERE scope_type = 'institution'");

        // Only one active (open/closing/reopened) accounting day per scope.
        DB::statement("CREATE UNIQUE INDEX uniq_accounting_day_active_per_agency ON accounting_days (agency_id) WHERE scope_type = 'agency' AND status IN ('open', 'closing', 'reopened')");
        DB::statement("CREATE UNIQUE INDEX uniq_accounting_day_active_institution ON accounting_days ((scope_type)) WHERE scope_type = 'institution' AND status IN ('open', 'closing', 'reopened')");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_accounting_day_active_institution');
        DB::statement('DROP INDEX IF EXISTS uniq_accounting_day_active_per_agency');
        DB::statement('DROP INDEX IF EXISTS uniq_accounting_day_institution_date');
        DB::statement('DROP INDEX IF EXISTS uniq_accounting_day_agency_date');

        Schema::dropIfExists('accounting_days');
    }
};
