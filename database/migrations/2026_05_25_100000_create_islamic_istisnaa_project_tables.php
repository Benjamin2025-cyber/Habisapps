<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islamic_istisnaa_projects', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->nullable()->constrained('islamic_financings')->cascadeOnDelete();
            $table->string('project_specification', 2000);
            $table->string('contractor_reference', 128);
            $table->string('customer_reference', 128);
            $table->string('site_location', 255);
            $table->json('inspection_rules')->nullable();
            $table->json('acceptance_criteria')->nullable();
            $table->string('parallel_supplier_reference', 128)->nullable();
            $table->boolean('parallel_supplier_approved')->default(false);
            $table->string('status', 32)->default('draft')->index();
            $table->bigInteger('total_planned_amount_minor')->default(0);
            $table->bigInteger('total_paid_amount_minor')->default(0);
            $table->ulid('screening_result_public_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_istisnaa_milestones', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_istisnaa_project_id')->constrained('islamic_istisnaa_projects')->cascadeOnDelete();
            $table->string('milestone_code', 64);
            $table->string('title', 255);
            $table->bigInteger('planned_amount_minor');
            $table->bigInteger('paid_amount_minor')->default(0);
            $table->date('due_date');
            $table->json('inspection_requirement')->nullable();
            $table->string('inspection_status', 32)->default('pending')->index();
            $table->string('payment_status', 32)->default('unpaid')->index();
            $table->string('status', 32)->default('planned')->index();
            $table->timestamps();

            $table->unique(['islamic_istisnaa_project_id', 'milestone_code'], 'if042_milestone_project_code_uq');
        });

        Schema::create('islamic_istisnaa_inspections', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_istisnaa_milestone_id')->constrained('islamic_istisnaa_milestones')->cascadeOnDelete();
            $table->string('decision', 32);
            $table->ulid('evidence_document_public_id');
            $table->unsignedBigInteger('inspector_user_id')->nullable();
            $table->text('comments')->nullable();
            $table->timestamp('decided_at')->useCurrent();
            $table->timestamps();

            $table->index(['islamic_istisnaa_milestone_id', 'decided_at'], 'if042_inspection_milestone_time_idx');
        });

        Schema::create('islamic_istisnaa_payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_istisnaa_milestone_id')->constrained('islamic_istisnaa_milestones')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('idempotency_key', 128)->unique();
            $table->ulid('inspection_public_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->index(['islamic_istisnaa_milestone_id', 'posted_at'], 'if042_payment_milestone_time_idx');
        });

        Schema::create('islamic_istisnaa_variation_orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_istisnaa_project_id')->constrained('islamic_istisnaa_projects')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->string('target_public_id', 64);
            $table->json('before_snapshot');
            $table->json('after_snapshot');
            $table->text('reason');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('applied_at')->useCurrent();
            $table->timestamps();

            $table->index(['islamic_istisnaa_project_id', 'applied_at'], 'if042_variation_project_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_istisnaa_variation_orders');
        Schema::dropIfExists('islamic_istisnaa_payments');
        Schema::dropIfExists('islamic_istisnaa_inspections');
        Schema::dropIfExists('islamic_istisnaa_milestones');
        Schema::dropIfExists('islamic_istisnaa_projects');
    }
};
