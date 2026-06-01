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
        Schema::create('accounting_calendar_days', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('scope_type', 16)->default('agency');
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->restrictOnDelete();
            $table->date('calendar_date');
            $table->date('business_date')->nullable();
            $table->boolean('is_business_day')->default(true);
            $table->boolean('is_holiday')->default(false);
            $table->string('holiday_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('calendar_date');
            $table->index('agency_id');
        });

        DB::statement("ALTER TABLE accounting_calendar_days ADD CONSTRAINT accounting_calendar_days_scope_type_allowed CHECK (scope_type IN ('institution', 'agency'))");
        DB::statement("ALTER TABLE accounting_calendar_days ADD CONSTRAINT accounting_calendar_days_scope_agency_consistent CHECK ((scope_type = 'agency' AND agency_id IS NOT NULL) OR (scope_type = 'institution' AND agency_id IS NULL))");

        DB::statement("CREATE UNIQUE INDEX uniq_accounting_calendar_agency_date ON accounting_calendar_days (agency_id, calendar_date) WHERE scope_type = 'agency'");
        DB::statement("CREATE UNIQUE INDEX uniq_accounting_calendar_institution_date ON accounting_calendar_days (calendar_date) WHERE scope_type = 'institution'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_accounting_calendar_institution_date');
        DB::statement('DROP INDEX IF EXISTS uniq_accounting_calendar_agency_date');

        Schema::dropIfExists('accounting_calendar_days');
    }
};
