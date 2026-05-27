<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islamic_salam_upfront_payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->foreignId('islamic_salam_goods_id')->nullable()->constrained('islamic_salam_goods')->nullOnDelete();
            $table->string('operation_code', 128);
            $table->ulid('mapping_public_id');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('posted');
            $table->string('idempotency_key', 128)->unique();
            $table->json('event_payload')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->index(['islamic_financing_id', 'status'], 'if081_salam_payment_fin_status_idx');
            $table->index(['islamic_financing_id', 'posted_at'], 'if081_salam_payment_fin_posted_idx');
        });

        Schema::create('islamic_salam_settlement_states', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_salam_goods_id')->unique()->constrained('islamic_salam_goods')->cascadeOnDelete();
            $table->string('status', 32)->default('open');
            $table->bigInteger('total_units');
            $table->bigInteger('delivered_units')->default(0);
            $table->bigInteger('outstanding_units')->default(0);
            $table->ulid('last_delivery_public_id')->nullable();
            $table->ulid('last_transition_public_id')->nullable();
            $table->json('state_snapshot')->nullable();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_salam_settlement_states');
        Schema::dropIfExists('islamic_salam_upfront_payments');
    }
};
