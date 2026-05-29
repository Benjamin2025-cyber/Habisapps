<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islamic_ijara_condition_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->foreignId('islamic_financed_asset_id')->constrained('islamic_financed_assets')->cascadeOnDelete();
            $table->ulid('evidence_document_public_id');
            $table->json('condition_snapshot');
            $table->unsignedBigInteger('reported_by_user_id')->nullable();
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamps();

            $table->index(['islamic_financing_id', 'reported_at'], 'if071_ijara_condition_financing_time_idx');
        });

        Schema::create('islamic_ijara_rental_schedule_lines', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->unsignedInteger('line_number');
            $table->date('due_on');
            $table->bigInteger('rental_amount_minor');
            $table->string('status', 32)->default('planned')->index();
            $table->bigInteger('paid_amount_minor')->default(0);
            $table->timestamps();

            $table->unique(['islamic_financing_id', 'line_number'], 'if071_ijara_rental_line_uq');
            $table->index(['islamic_financing_id', 'due_on'], 'if071_ijara_rental_due_idx');
        });

        Schema::create('islamic_ijara_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->string('event_type', 64)->index();
            $table->string('workflow_state', 32)->default('under_review')->index();
            $table->ulid('evidence_document_public_id')->nullable();
            $table->json('event_payload')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['islamic_financing_id', 'occurred_at'], 'if071_ijara_event_financing_time_idx');
        });

        Schema::create('islamic_ijara_accounting_posts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->string('event_type', 64)->index();
            $table->string('operation_code', 128)->index();
            $table->bigInteger('amount_minor')->default(0);
            $table->ulid('mapping_public_id');
            $table->json('post_payload')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('islamic_ijara_transfer_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->foreignId('islamic_financed_asset_id')->constrained('islamic_financed_assets')->cascadeOnDelete();
            $table->string('status', 32)->default('requested')->index();
            $table->bigInteger('residual_amount_minor');
            $table->bigInteger('waiver_amount_minor')->default(0);
            $table->bigInteger('net_settlement_amount_minor')->default(0);
            $table->ulid('transfer_document_public_id');
            $table->json('customer_acceptance');
            $table->json('exception_payload')->nullable();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->ulid('posted_mapping_public_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->unsignedBigInteger('posted_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['islamic_financing_id', 'status'], 'if072_ijara_transfer_financing_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_ijara_transfer_events');
        Schema::dropIfExists('islamic_ijara_accounting_posts');
        Schema::dropIfExists('islamic_ijara_events');
        Schema::dropIfExists('islamic_ijara_rental_schedule_lines');
        Schema::dropIfExists('islamic_ijara_condition_reports');
    }
};
