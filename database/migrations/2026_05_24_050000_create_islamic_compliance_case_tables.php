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
        Schema::create('islamic_compliance_cases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('subject_type', 64);
            $table->string('subject_public_id', 64);
            $table->string('reason_code', 64);
            $table->string('risk_level', 16);
            $table->string('checklist_version', 64);
            $table->foreignId('assigned_reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->string('status', 32)->default('open');
            $table->string('blocking_mode', 16)->default('hard');
            $table->string('latest_decision', 32)->nullable();
            $table->timestamp('latest_decided_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_public_id'], 'idx_islamic_compliance_case_subject');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_cases ADD CONSTRAINT islamic_compliance_cases_subject_type_valid
  CHECK (subject_type IN (
    'islamic_product',
    'islamic_customer',
    'islamic_asset',
    'islamic_goods',
    'islamic_project',
    'islamic_supplier',
    'islamic_account',
    'islamic_contract',
    'islamic_transaction'
  ))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_cases ADD CONSTRAINT islamic_compliance_cases_risk_level_valid
  CHECK (risk_level IN ('low', 'medium', 'high', 'critical'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_cases ADD CONSTRAINT islamic_compliance_cases_status_valid
  CHECK (status IN ('open', 'in_review', 'blocked', 'resolved', 'archived'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_cases ADD CONSTRAINT islamic_compliance_cases_blocking_mode_valid
  CHECK (blocking_mode IN ('hard', 'soft'))
SQL);

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX uniq_islamic_compliance_case_active_reason
ON islamic_compliance_cases (subject_type, subject_public_id, reason_code, status)
WHERE status IN ('open', 'in_review', 'blocked')
SQL);

        Schema::create('islamic_compliance_case_decisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('case_id')->constrained('islamic_compliance_cases')->cascadeOnDelete();
            $table->string('decision', 32);
            $table->text('decision_comments')->nullable();
            $table->json('conditions')->nullable();
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'decided_at'], 'idx_islamic_compliance_case_decisions_time');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_case_decisions ADD CONSTRAINT islamic_compliance_case_decisions_decision_valid
  CHECK (decision IN (
    'approved',
    'rejected',
    'needs_information',
    'conditionally_approved',
    'suspended',
    'corrective_action_required',
    'corrective_action_closed'
  ))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_case_decisions ADD CONSTRAINT islamic_compliance_case_decisions_dates_valid
  CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to > effective_from)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_case_decisions ADD CONSTRAINT islamic_compliance_case_decisions_conditional_approval_valid
  CHECK (
    decision <> 'conditionally_approved'
    OR (
      conditions IS NOT NULL
      AND conditions::text <> '{}'::text
      AND (
        effective_to IS NOT NULL
        OR (conditions->>'expires_on') IS NOT NULL
      )
    )
  )
SQL);

        Schema::create('islamic_compliance_case_blockers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('case_id')->constrained('islamic_compliance_cases')->cascadeOnDelete();
            $table->string('blocker_type', 64);
            $table->string('target_subject_type', 64);
            $table->string('target_subject_public_id', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamp('activated_at');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('release_reason')->nullable();
            $table->timestamps();

            $table->index(['blocker_type', 'target_subject_type', 'target_subject_public_id'], 'idx_islamic_compliance_case_blocker_target');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_compliance_case_blockers ADD CONSTRAINT islamic_compliance_case_blockers_type_valid
  CHECK (blocker_type IN (
    'product_activation',
    'contract_activation',
    'supplier_use',
    'asset_acceptance',
    'goods_acceptance',
    'project_approval',
    'account_pool_assignment',
    'transaction_authorization'
  ))
SQL);

        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX uniq_islamic_compliance_case_blocker_active
ON islamic_compliance_case_blockers (case_id, blocker_type, target_subject_type, target_subject_public_id)
WHERE is_active = true
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_islamic_compliance_case_blocker_active');
        DB::statement('ALTER TABLE islamic_compliance_case_blockers DROP CONSTRAINT IF EXISTS islamic_compliance_case_blockers_type_valid');
        Schema::dropIfExists('islamic_compliance_case_blockers');

        DB::statement('ALTER TABLE islamic_compliance_case_decisions DROP CONSTRAINT IF EXISTS islamic_compliance_case_decisions_conditional_approval_valid');
        DB::statement('ALTER TABLE islamic_compliance_case_decisions DROP CONSTRAINT IF EXISTS islamic_compliance_case_decisions_dates_valid');
        DB::statement('ALTER TABLE islamic_compliance_case_decisions DROP CONSTRAINT IF EXISTS islamic_compliance_case_decisions_decision_valid');
        Schema::dropIfExists('islamic_compliance_case_decisions');

        DB::statement('DROP INDEX IF EXISTS uniq_islamic_compliance_case_active_reason');
        DB::statement('ALTER TABLE islamic_compliance_cases DROP CONSTRAINT IF EXISTS islamic_compliance_cases_blocking_mode_valid');
        DB::statement('ALTER TABLE islamic_compliance_cases DROP CONSTRAINT IF EXISTS islamic_compliance_cases_status_valid');
        DB::statement('ALTER TABLE islamic_compliance_cases DROP CONSTRAINT IF EXISTS islamic_compliance_cases_risk_level_valid');
        DB::statement('ALTER TABLE islamic_compliance_cases DROP CONSTRAINT IF EXISTS islamic_compliance_cases_subject_type_valid');
        Schema::dropIfExists('islamic_compliance_cases');
    }
};
