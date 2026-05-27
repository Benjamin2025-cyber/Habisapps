<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islamic_mourabaha_receivable_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('islamic_treatment_policies')->nullOnDelete();
            $table->foreignId('source_event_id')->nullable()->constrained('islamic_mourabaha_receivable_events')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('event_type', 64)->index(); // collection|rebate|cancellation|default_treatment|reversal|correction
            $table->string('operation_code', 128);
            $table->string('currency', 3)->default('XAF');
            $table->bigInteger('amount_minor');
            $table->bigInteger('outstanding_before_minor');
            $table->bigInteger('outstanding_after_minor');
            $table->string('status', 32)->default('posted')->index(); // posted|reversed|blocked
            $table->json('event_snapshot')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['islamic_financing_id', 'event_type'], 'if062_event_financing_type_idx');
            $table->index(['islamic_financing_id', 'status', 'id'], 'if062_event_financing_status_idx');
        });

        Schema::create('islamic_mourabaha_receivable_allocations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('receivable_event_id')->constrained('islamic_mourabaha_receivable_events')->cascadeOnDelete();
            $table->foreignId('islamic_financing_installment_id')->constrained('islamic_financing_installments')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number');
            $table->bigInteger('allocated_minor');
            $table->timestamps();

            $table->index(['receivable_event_id', 'installment_number'], 'if062_alloc_event_installment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_mourabaha_receivable_allocations');
        Schema::dropIfExists('islamic_mourabaha_receivable_events');
    }
};
