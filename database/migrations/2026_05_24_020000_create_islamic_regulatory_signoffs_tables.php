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
        Schema::create('islamic_regulatory_signoffs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('jurisdiction', 64)->index();
            $table->string('regulator', 64)->index();
            $table->string('opinion_reference', 191);
            $table->text('opinion_summary');
            $table->string('approval_type', 32);
            $table->json('restrictions')->nullable();
            $table->text('accounting_implications')->nullable();
            $table->string('owner_type', 32);
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('owner_role', 128)->nullable();
            $table->string('owner_department', 128)->nullable();
            $table->string('owner_committee', 128)->nullable();
            $table->date('approved_on');
            $table->date('effective_date');
            $table->date('expiry_date')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->foreignId('document_id')->constrained('documents')->restrictOnDelete();
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
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_jurisdiction_valid
  CHECK (jurisdiction IN ('cameroon', 'cemac'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_regulator_valid
  CHECK (regulator IN ('cobac', 'beac', 'minfi', 'other'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_approval_type_valid
  CHECK (approval_type IN ('allow', 'allow_with_conditions', 'deny'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_status_valid
  CHECK (status IN ('draft', 'active', 'suspended', 'revoked', 'expired', 'retired'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_owner_type_valid
  CHECK (owner_type IN ('user', 'role', 'department', 'committee'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_owner_required
  CHECK (
    (owner_type = 'user' AND owner_user_id IS NOT NULL AND owner_role IS NULL AND owner_department IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'role' AND owner_role IS NOT NULL AND owner_user_id IS NULL AND owner_department IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'department' AND owner_department IS NOT NULL AND owner_user_id IS NULL AND owner_role IS NULL AND owner_committee IS NULL)
    OR (owner_type = 'committee' AND owner_committee IS NOT NULL AND owner_user_id IS NULL AND owner_role IS NULL AND owner_department IS NULL)
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_dates_valid
  CHECK (expiry_date IS NULL OR expiry_date > effective_date)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_activation_fields_valid
  CHECK (
    status <> 'active'
    OR (activated_by_user_id IS NOT NULL AND activated_at IS NOT NULL)
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_retirement_fields_valid
  CHECK (
    status <> 'retired'
    OR (retired_by_user_id IS NOT NULL AND retired_at IS NOT NULL AND retirement_reason IS NOT NULL AND retirement_reason <> '')
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoffs ADD CONSTRAINT islamic_regulatory_signoffs_deny_not_active
  CHECK (approval_type <> 'deny' OR status <> 'active')
SQL);

        Schema::create('islamic_regulatory_signoff_links', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_regulatory_signoff_id')->constrained('islamic_regulatory_signoffs')->cascadeOnDelete();
            $table->string('linkable_type', 64);
            $table->string('linkable_code', 128);
            $table->string('restriction_mode', 16)->default('allow');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['islamic_regulatory_signoff_id', 'linkable_type', 'linkable_code'], 'uniq_islamic_signoff_link');
            $table->index(['linkable_type', 'linkable_code'], 'idx_islamic_signoff_link_target');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoff_links ADD CONSTRAINT islamic_regulatory_signoff_links_type_valid
  CHECK (linkable_type IN ('product_family', 'account_type'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_regulatory_signoff_links ADD CONSTRAINT islamic_regulatory_signoff_links_restriction_mode_valid
  CHECK (restriction_mode IN ('allow', 'deny'))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE islamic_regulatory_signoff_links DROP CONSTRAINT IF EXISTS islamic_regulatory_signoff_links_restriction_mode_valid');
        DB::statement('ALTER TABLE islamic_regulatory_signoff_links DROP CONSTRAINT IF EXISTS islamic_regulatory_signoff_links_type_valid');
        Schema::dropIfExists('islamic_regulatory_signoff_links');

        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_deny_not_active');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_retirement_fields_valid');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_activation_fields_valid');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_dates_valid');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_owner_required');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_owner_type_valid');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_status_valid');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_approval_type_valid');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_regulator_valid');
        DB::statement('ALTER TABLE islamic_regulatory_signoffs DROP CONSTRAINT IF EXISTS islamic_regulatory_signoffs_jurisdiction_valid');

        Schema::dropIfExists('islamic_regulatory_signoffs');
    }
};
