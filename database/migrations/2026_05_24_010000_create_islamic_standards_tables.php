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
        Schema::create('islamic_standards', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('source', 64)->index();
            $table->string('reference', 128);
            $table->string('title', 255);
            $table->string('version', 64)->nullable();
            $table->date('publication_date')->nullable();
            $table->text('scope_summary');
            $table->string('owner_type', 32);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_role', 128)->nullable();
            $table->string('owner_department', 128)->nullable();
            $table->string('owner_committee', 128)->nullable();
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
            $table->foreignId('supersedes_standard_id')->nullable()->constrained('islamic_standards')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('activated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('retired_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_source_valid
  CHECK (source IN ('AAOIFI', 'IFSB', 'COBAC', 'CEMAC', 'INTERNAL', 'LEGAL_OPINION', 'SHARIA_DECISION', 'POLICY'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_owner_type_valid
  CHECK (owner_type IN ('user', 'role', 'department', 'committee'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_owner_required
  CHECK (
    (owner_type = 'user' AND owner_user_id IS NOT NULL AND owner_role IS NULL AND owner_department IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'role' AND owner_role IS NOT NULL AND owner_user_id IS NULL AND owner_department IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'department' AND owner_department IS NOT NULL AND owner_user_id IS NULL AND owner_role IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'committee' AND owner_committee IS NOT NULL AND owner_user_id IS NULL AND owner_role IS NULL AND owner_department IS NULL)
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_status_valid
  CHECK (status IN ('draft', 'active', 'expired', 'retired', 'superseded'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_dates_valid
  CHECK (expiry_date IS NULL OR expiry_date > effective_date)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_activation_fields_valid
  CHECK (
    status <> 'active'
    OR (activated_by_user_id IS NOT NULL AND activated_at IS NOT NULL)
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_standards ADD CONSTRAINT islamic_standards_retirement_fields_valid
  CHECK (
    status <> 'retired'
    OR (retired_by_user_id IS NOT NULL AND retired_at IS NOT NULL AND retirement_reason IS NOT NULL AND retirement_reason <> '')
  )
SQL);

        Schema::create('islamic_standard_links', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_standard_id')->constrained('islamic_standards')->cascadeOnDelete();
            $table->string('linkable_type', 64);
            $table->string('linkable_code', 128);
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['islamic_standard_id', 'linkable_type', 'linkable_code'], 'uniq_islamic_standard_link');
            $table->index(['linkable_type', 'linkable_code'], 'idx_islamic_standard_link_target');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_standard_links ADD CONSTRAINT islamic_standard_links_type_valid
  CHECK (linkable_type IN ('product_family', 'account_type', 'accounting_mapping', 'contract_template', 'screening_policy'))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE islamic_standard_links DROP CONSTRAINT IF EXISTS islamic_standard_links_type_valid');
        Schema::dropIfExists('islamic_standard_links');

        DB::statement('ALTER TABLE islamic_standards DROP CONSTRAINT IF EXISTS islamic_standards_retirement_fields_valid');
        DB::statement('ALTER TABLE islamic_standards DROP CONSTRAINT IF EXISTS islamic_standards_activation_fields_valid');
        DB::statement('ALTER TABLE islamic_standards DROP CONSTRAINT IF EXISTS islamic_standards_dates_valid');
        DB::statement('ALTER TABLE islamic_standards DROP CONSTRAINT IF EXISTS islamic_standards_status_valid');
        DB::statement('ALTER TABLE islamic_standards DROP CONSTRAINT IF EXISTS islamic_standards_owner_required');
        DB::statement('ALTER TABLE islamic_standards DROP CONSTRAINT IF EXISTS islamic_standards_owner_type_valid');
        DB::statement('ALTER TABLE islamic_standards DROP CONSTRAINT IF EXISTS islamic_standards_source_valid');

        Schema::dropIfExists('islamic_standards');
    }
};
