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
        Schema::create('islamic_screening_policies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('code', 64);
            $table->string('name', 191);
            $table->unsignedInteger('version')->default(1);
            $table->string('scope_type', 32);
            $table->string('scope_value', 128)->nullable();
            $table->string('status', 32)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version'], 'uniq_islamic_screening_policy_code_version');
            $table->index(['scope_type', 'scope_value', 'status'], 'idx_islamic_screening_policy_scope');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_screening_policies ADD CONSTRAINT islamic_screening_policies_status_valid
  CHECK (status IN ('draft', 'active', 'suspended', 'revoked', 'expired', 'archived'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_screening_policies ADD CONSTRAINT islamic_screening_policies_scope_type_valid
  CHECK (scope_type IN ('institution', 'agency', 'product_family'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_screening_policies ADD CONSTRAINT islamic_screening_policies_dates_valid
  CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to > effective_from)
SQL);

        Schema::create('islamic_screening_policy_rules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('policy_id')->constrained('islamic_screening_policies')->cascadeOnDelete();
            $table->string('rule_type', 64);
            $table->string('match_key', 128);
            $table->string('match_operator', 32)->default('equals');
            $table->string('risk_level', 16)->nullable();
            $table->string('action', 32);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'rule_type', 'match_key', 'priority'], 'uniq_islamic_screening_policy_rule_match');
            $table->index(['policy_id', 'is_active', 'priority'], 'idx_islamic_screening_policy_rule_active');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_screening_policy_rules ADD CONSTRAINT islamic_screening_policy_rules_type_valid
  CHECK (rule_type IN (
    'prohibited_sector',
    'restricted_sector',
    'prohibited_goods',
    'restricted_goods',
    'supplier_flag',
    'customer_business_flag',
    'source_of_funds_flag',
    'use_of_funds_flag',
    'escalation_rule'
  ))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_screening_policy_rules ADD CONSTRAINT islamic_screening_policy_rules_action_valid
  CHECK (action IN ('block', 'manual_review', 'allow_with_note'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_screening_policy_rules ADD CONSTRAINT islamic_screening_policy_rules_operator_valid
  CHECK (match_operator IN ('equals', 'contains', 'starts_with', 'regex'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_screening_policy_rules ADD CONSTRAINT islamic_screening_policy_rules_risk_valid
  CHECK (risk_level IS NULL OR risk_level IN ('low', 'medium', 'high', 'critical'))
SQL);

        Schema::create('islamic_screening_results', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('subject_type', 64);
            $table->string('subject_public_id', 64);
            $table->string('context_type', 64);
            $table->string('policy_public_id', 64);
            $table->unsignedInteger('policy_version');
            $table->json('policy_snapshot');
            $table->string('result', 32);
            $table->json('matched_rules')->nullable();
            $table->text('block_reason')->nullable();
            $table->string('review_case_public_id', 64)->nullable();
            $table->timestamp('evaluated_at');
            $table->foreignId('evaluated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['subject_type', 'subject_public_id', 'context_type'], 'idx_islamic_screening_results_subject');
            $table->index(['policy_public_id', 'policy_version'], 'idx_islamic_screening_results_policy');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_screening_results ADD CONSTRAINT islamic_screening_results_result_valid
  CHECK (result IN ('pass', 'fail', 'manual_review', 'expired', 'not_applicable'))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE islamic_screening_results DROP CONSTRAINT IF EXISTS islamic_screening_results_result_valid');
        Schema::dropIfExists('islamic_screening_results');

        DB::statement('ALTER TABLE islamic_screening_policy_rules DROP CONSTRAINT IF EXISTS islamic_screening_policy_rules_risk_valid');
        DB::statement('ALTER TABLE islamic_screening_policy_rules DROP CONSTRAINT IF EXISTS islamic_screening_policy_rules_operator_valid');
        DB::statement('ALTER TABLE islamic_screening_policy_rules DROP CONSTRAINT IF EXISTS islamic_screening_policy_rules_action_valid');
        DB::statement('ALTER TABLE islamic_screening_policy_rules DROP CONSTRAINT IF EXISTS islamic_screening_policy_rules_type_valid');
        Schema::dropIfExists('islamic_screening_policy_rules');

        DB::statement('ALTER TABLE islamic_screening_policies DROP CONSTRAINT IF EXISTS islamic_screening_policies_dates_valid');
        DB::statement('ALTER TABLE islamic_screening_policies DROP CONSTRAINT IF EXISTS islamic_screening_policies_scope_type_valid');
        DB::statement('ALTER TABLE islamic_screening_policies DROP CONSTRAINT IF EXISTS islamic_screening_policies_status_valid');
        Schema::dropIfExists('islamic_screening_policies');
    }
};
