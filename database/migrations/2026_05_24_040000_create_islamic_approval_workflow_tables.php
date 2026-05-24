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
        Schema::create('islamic_approval_workflows', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('subject_type', 64);
            $table->string('subject_public_id', 64);
            $table->string('current_state', 32)->default('draft');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->boolean('is_blocking')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->json('conditions')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subject_type', 'subject_public_id'], 'uniq_islamic_approval_workflow_subject');
            $table->index(['subject_type', 'current_state'], 'idx_islamic_approval_workflow_state');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_workflows ADD CONSTRAINT islamic_approval_workflows_subject_type_valid
  CHECK (subject_type IN (
    'islamic_product',
    'islamic_contract_template',
    'islamic_screening_policy',
    'islamic_exception',
    'islamic_mapping',
    'islamic_corrective_action'
  ))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_workflows ADD CONSTRAINT islamic_approval_workflows_state_valid
  CHECK (current_state IN ('draft', 'submitted', 'approved', 'rejected', 'suspended', 'revoked', 'expired', 'archived'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_workflows ADD CONSTRAINT islamic_approval_workflows_dates_valid
  CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to > effective_from)
SQL);

        Schema::create('islamic_approval_decisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('workflow_id')->constrained('islamic_approval_workflows')->cascadeOnDelete();
            $table->string('from_state', 32);
            $table->string('to_state', 32);
            $table->string('decision', 32);
            $table->text('decision_comments')->nullable();
            $table->json('conditions')->nullable();
            $table->foreignId('evidence_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('decided_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'decided_at'], 'idx_islamic_approval_decisions_workflow_time');
        });

        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_decisions ADD CONSTRAINT islamic_approval_decisions_from_state_valid
  CHECK (from_state IN ('draft', 'submitted', 'approved', 'rejected', 'suspended', 'revoked', 'expired', 'archived'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_decisions ADD CONSTRAINT islamic_approval_decisions_to_state_valid
  CHECK (to_state IN ('draft', 'submitted', 'approved', 'rejected', 'suspended', 'revoked', 'expired', 'archived'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_decisions ADD CONSTRAINT islamic_approval_decisions_decision_valid
  CHECK (decision IN ('submit', 'approve', 'reject', 'suspend', 'revoke', 'expire', 'archive', 'restore_to_draft'))
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_decisions ADD CONSTRAINT islamic_approval_decisions_dates_valid
  CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to > effective_from)
SQL);

        DB::statement(<<<'SQL'
ALTER TABLE islamic_approval_decisions ADD CONSTRAINT islamic_approval_decisions_conditional_approval_valid
  CHECK (
    decision <> 'approve'
    OR conditions IS NULL
    OR (
      effective_to IS NOT NULL
      OR (conditions->>'expires_on') IS NOT NULL
    )
  )
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE islamic_approval_decisions DROP CONSTRAINT IF EXISTS islamic_approval_decisions_conditional_approval_valid');
        DB::statement('ALTER TABLE islamic_approval_decisions DROP CONSTRAINT IF EXISTS islamic_approval_decisions_dates_valid');
        DB::statement('ALTER TABLE islamic_approval_decisions DROP CONSTRAINT IF EXISTS islamic_approval_decisions_decision_valid');
        DB::statement('ALTER TABLE islamic_approval_decisions DROP CONSTRAINT IF EXISTS islamic_approval_decisions_to_state_valid');
        DB::statement('ALTER TABLE islamic_approval_decisions DROP CONSTRAINT IF EXISTS islamic_approval_decisions_from_state_valid');
        Schema::dropIfExists('islamic_approval_decisions');

        DB::statement('ALTER TABLE islamic_approval_workflows DROP CONSTRAINT IF EXISTS islamic_approval_workflows_dates_valid');
        DB::statement('ALTER TABLE islamic_approval_workflows DROP CONSTRAINT IF EXISTS islamic_approval_workflows_state_valid');
        DB::statement('ALTER TABLE islamic_approval_workflows DROP CONSTRAINT IF EXISTS islamic_approval_workflows_subject_type_valid');
        Schema::dropIfExists('islamic_approval_workflows');
    }
};
