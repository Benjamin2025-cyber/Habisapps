<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islamic_salam_goods', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->nullable()->constrained('islamic_financings')->cascadeOnDelete();
            $table->string('goods_category', 64)->index();
            $table->string('quality_spec', 1000);
            $table->bigInteger('quantity_units');
            $table->string('quantity_unit', 32);
            $table->date('delivery_date');
            $table->string('delivery_place', 255);
            $table->string('counterparty_reference', 128)->nullable();
            $table->json('inspection_requirements')->nullable();
            $table->json('acceptance_rules')->nullable();
            $table->string('status', 32)->default('specified')->index();
            $table->bigInteger('delivered_units')->default(0);
            $table->string('inventory_reference', 128)->nullable();
            $table->string('settlement_reference', 128)->nullable();
            $table->ulid('screening_result_public_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['islamic_financing_id', 'status'], 'if041_goods_financing_status_idx');
        });

        Schema::create('islamic_salam_goods_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_salam_goods_id')->constrained('islamic_salam_goods')->cascadeOnDelete();
            $table->bigInteger('delivered_units');
            $table->date('delivered_on');
            $table->string('delivery_evidence', 128);
            $table->string('inventory_reference', 128)->nullable();
            $table->string('settlement_reference', 128)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamps();

            $table->index(['islamic_salam_goods_id', 'delivered_on'], 'if041_delivery_goods_date_idx');
        });

        Schema::create('islamic_salam_goods_transitions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_salam_goods_id')->constrained('islamic_salam_goods')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason_code', 64)->nullable();
            $table->text('reason_note')->nullable();
            $table->ulid('screening_result_public_id')->nullable();
            $table->ulid('compliance_case_public_id')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('transitioned_at')->useCurrent();
            $table->timestamps();

            $table->index(['islamic_salam_goods_id', 'transitioned_at'], 'if041_transition_goods_time_idx');
            $table->index(['islamic_salam_goods_id', 'to_status'], 'if041_transition_goods_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_salam_goods_transitions');
        Schema::dropIfExists('islamic_salam_goods_deliveries');
        Schema::dropIfExists('islamic_salam_goods');
    }
};
