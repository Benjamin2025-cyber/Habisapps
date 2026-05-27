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
        Schema::create('islamic_treatment_policies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('policy_code', 64);
            $table->unsignedInteger('version')->default(1);
            $table->string('scope_type', 32)->index(); // institution|agency|product_family|product
            $table->string('scope_value', 128)->nullable()->index();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->boolean('zakat_enabled')->default(false);
            $table->boolean('charity_treatment_enabled')->default(false);
            $table->boolean('non_compliant_income_treatment_enabled')->default(false);
            $table->string('purification_mode', 64)->nullable();
            $table->json('required_operation_codes')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_value', 'status'], 'islamic_treatment_policy_scope_idx');
        });

        Schema::create('islamic_treatment_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('policy_id')->constrained('islamic_treatment_policies')->restrictOnDelete();
            $table->string('event_type', 64)->index();
            $table->string('event_reference', 128)->nullable()->index();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->string('currency', 3)->default('XAF');
            $table->bigInteger('amount_minor');
            $table->string('source_subject_type', 64)->nullable();
            $table->string('source_subject_public_id', 64)->nullable()->index();
            $table->string('treatment_bucket', 64)->nullable();
            $table->string('operation_code', 128)->nullable();
            $table->string('mapping_reference', 255)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('status', 32)->default('draft')->index(); // draft|posted|blocked
            $table->text('blocked_reason')->nullable();
            $table->date('occurred_on');
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['policy_id', 'event_type', 'status'], 'islamic_treatment_event_policy_idx');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE islamic_treatment_policies
                 ADD CONSTRAINT islamic_treatment_policy_window_chk
                 CHECK (effective_to IS NULL OR effective_to > effective_from)'
            );
            DB::statement(
                'ALTER TABLE islamic_treatment_events
                 ADD CONSTRAINT islamic_treatment_event_amount_chk
                 CHECK (amount_minor > 0)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE islamic_treatment_events DROP CONSTRAINT IF EXISTS islamic_treatment_event_amount_chk');
            DB::statement('ALTER TABLE islamic_treatment_policies DROP CONSTRAINT IF EXISTS islamic_treatment_policy_window_chk');
        }

        Schema::dropIfExists('islamic_treatment_events');
        Schema::dropIfExists('islamic_treatment_policies');
    }
};
