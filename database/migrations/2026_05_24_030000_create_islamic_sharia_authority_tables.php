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
        Schema::create('islamic_sharia_authorities', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name', 191);
            $table->string('authority_type', 32)->index();
            $table->string('jurisdiction', 64)->index();
            $table->json('mandate_scope');
            $table->text('mandate_summary');
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
ALTER TABLE islamic_sharia_authorities ADD CONSTRAINT islamic_sharia_authorities_authority_type_valid
  CHECK (authority_type IN ('board', 'committee', 'advisor_panel'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_sharia_authorities ADD CONSTRAINT islamic_sharia_authorities_status_valid
  CHECK (status IN ('draft', 'active', 'suspended', 'revoked', 'retired'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_sharia_authorities ADD CONSTRAINT islamic_sharia_authorities_dates_valid
  CHECK (expiry_date IS NULL OR expiry_date > effective_date)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_sharia_authorities ADD CONSTRAINT islamic_sharia_authorities_activation_fields_valid
  CHECK (
    status <> 'active'
    OR (activated_by_user_id IS NOT NULL AND activated_at IS NOT NULL)
  )
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_sharia_authorities ADD CONSTRAINT islamic_sharia_authorities_retirement_fields_valid
  CHECK (
    status <> 'retired'
    OR (retired_by_user_id IS NOT NULL AND retired_at IS NOT NULL AND retirement_reason IS NOT NULL AND retirement_reason <> '')
  )
SQL);

        Schema::create('islamic_sharia_authority_members', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_sharia_authority_id')->constrained('islamic_sharia_authorities')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('member_role', 32)->index();
            $table->json('scope')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['islamic_sharia_authority_id', 'user_id', 'member_role', 'starts_on'], 'uniq_islamic_sharia_member_role_window');
            $table->index(['user_id', 'status'], 'idx_islamic_sharia_member_user_status');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_sharia_authority_members ADD CONSTRAINT islamic_sharia_authority_members_role_valid
  CHECK (member_role IN ('chair', 'reviewer', 'approver', 'observer', 'administrator'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_sharia_authority_members ADD CONSTRAINT islamic_sharia_authority_members_status_valid
  CHECK (status IN ('active', 'suspended', 'revoked', 'expired'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_sharia_authority_members ADD CONSTRAINT islamic_sharia_authority_members_dates_valid
  CHECK (ends_on IS NULL OR ends_on > starts_on)
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE islamic_sharia_authority_members DROP CONSTRAINT IF EXISTS islamic_sharia_authority_members_dates_valid');
        DB::statement('ALTER TABLE islamic_sharia_authority_members DROP CONSTRAINT IF EXISTS islamic_sharia_authority_members_status_valid');
        DB::statement('ALTER TABLE islamic_sharia_authority_members DROP CONSTRAINT IF EXISTS islamic_sharia_authority_members_role_valid');
        Schema::dropIfExists('islamic_sharia_authority_members');

        DB::statement('ALTER TABLE islamic_sharia_authorities DROP CONSTRAINT IF EXISTS islamic_sharia_authorities_retirement_fields_valid');
        DB::statement('ALTER TABLE islamic_sharia_authorities DROP CONSTRAINT IF EXISTS islamic_sharia_authorities_activation_fields_valid');
        DB::statement('ALTER TABLE islamic_sharia_authorities DROP CONSTRAINT IF EXISTS islamic_sharia_authorities_dates_valid');
        DB::statement('ALTER TABLE islamic_sharia_authorities DROP CONSTRAINT IF EXISTS islamic_sharia_authorities_status_valid');
        DB::statement('ALTER TABLE islamic_sharia_authorities DROP CONSTRAINT IF EXISTS islamic_sharia_authorities_authority_type_valid');

        Schema::dropIfExists('islamic_sharia_authorities');
    }
};
