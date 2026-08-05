<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identity of the institution itself — the legal entity that owns every agency.
 *
 * This is a profile, not a scoping parent: nothing gains an institution_id
 * foreign key. Institution scope stays encoded as `agency_id IS NULL`
 * (ledger_accounts, operation_account_mappings) and `scope_type = 'institution'`
 * (accounting_days), which is what an EMF's single-legal-entity structure means.
 *
 * What lives here is what only the institution can answer: the legal name,
 * approval and registration identifiers, and the head office that EMF/COBAC
 * returns and issued attestations must declare. An agency cannot stand in for
 * any of it.
 *
 * The table is a singleton. The primary key is not a sequence, so re-running the
 * seeder is idempotent and a second row is refused by the check constraint
 * rather than silently taking id 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('institution_profile', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->ulid('public_id')->unique();
            $table->string('legal_name')->nullable();
            $table->string('trade_name')->nullable();
            $table->string('legal_form', 64)->nullable();
            $table->string('emf_category', 32)->nullable();
            $table->string('supervisory_authority', 128)->nullable();
            $table->string('approval_number', 64)->nullable();
            $table->date('approval_date')->nullable();
            $table->string('registration_number', 64)->nullable();
            $table->string('tax_identification_number', 64)->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('po_box', 64)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('region', 128)->nullable();
            $table->string('country', 128)->nullable();
            $table->string('phone_number', 32)->nullable();
            $table->string('fax_number', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            // Declarative only. config/money.php stays the source of truth for
            // posting and reporting currency; this records what the institution
            // declares to its supervisor, and drives no conversion.
            $table->string('declared_reporting_currency', 3)->nullable();
            // Recorded for declaration purposes. accounting_days and
            // accounting_calendar_days remain authoritative for the calendar.
            $table->unsignedTinyInteger('fiscal_year_start_month')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE institution_profile ADD CONSTRAINT institution_profile_singleton CHECK (id = 1)');
        DB::statement('ALTER TABLE institution_profile ADD CONSTRAINT institution_profile_fiscal_year_start_month CHECK (fiscal_year_start_month IS NULL OR fiscal_year_start_month BETWEEN 1 AND 12)');
    }

    public function down(): void
    {
        Schema::dropIfExists('institution_profile');
    }
};
