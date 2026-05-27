<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islamic_partnerships', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financing_id')->nullable()->constrained('islamic_financings')->cascadeOnDelete();
            $table->string('partnership_type', 32)->index(); // moudaraba | moucharaka
            $table->json('governance_rights')->nullable();
            $table->string('reporting_cadence', 32);
            $table->json('loss_rules')->nullable();
            $table->json('exit_terms')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->bigInteger('expected_total_capital_minor')->default(0);
            $table->bigInteger('contributed_total_capital_minor')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_partnership_partners', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_partnership_id')->constrained('islamic_partnerships')->cascadeOnDelete();
            $table->string('partner_role', 32); // capital_provider | entrepreneur | joint_partner
            $table->string('partner_reference', 128);
            $table->decimal('profit_share_ratio', 8, 6);
            $table->decimal('loss_share_ratio', 8, 6)->nullable();
            $table->bigInteger('expected_contribution_minor')->default(0);
            $table->timestamps();

            $table->unique(['islamic_partnership_id', 'partner_reference'], 'if043_partner_partnership_ref_uq');
        });

        Schema::create('islamic_partnership_contributions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_partnership_id')->constrained('islamic_partnerships')->cascadeOnDelete();
            $table->foreignId('islamic_partnership_partner_id')->constrained('islamic_partnership_partners')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->date('contributed_on');
            $table->ulid('evidence_document_public_id');
            $table->timestamps();
        });

        Schema::create('islamic_partnership_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_partnership_id')->constrained('islamic_partnerships')->cascadeOnDelete();
            $table->string('period_code', 64);
            $table->bigInteger('distributable_profit_minor')->default(0);
            $table->ulid('evidence_document_public_id');
            $table->string('approval_status', 32)->default('approved')->index();
            $table->timestamp('reported_at')->useCurrent();
            $table->timestamps();

            $table->unique(['islamic_partnership_id', 'period_code'], 'if043_report_partnership_period_uq');
        });

        Schema::create('islamic_partnership_profit_declarations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_partnership_id')->constrained('islamic_partnerships')->cascadeOnDelete();
            $table->foreignId('islamic_partnership_report_id')->constrained('islamic_partnership_reports')->cascadeOnDelete();
            $table->string('period_code', 64);
            $table->bigInteger('amount_minor');
            $table->timestamp('declared_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('islamic_partnership_losses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_partnership_id')->constrained('islamic_partnerships')->cascadeOnDelete();
            $table->string('loss_type', 32); // ordinary | misconduct
            $table->bigInteger('amount_minor');
            $table->ulid('evidence_document_public_id');
            $table->text('description')->nullable();
            $table->boolean('blocks_distribution')->default(false);
            $table->timestamp('recorded_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('islamic_partnership_valuations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_partnership_id')->constrained('islamic_partnerships')->cascadeOnDelete();
            $table->string('valuation_method', 64);
            $table->bigInteger('valuation_amount_minor');
            $table->date('valuation_date');
            $table->date('validity_until');
            $table->ulid('evidence_document_public_id');
            $table->string('approval_status', 32)->default('approved')->index();
            $table->timestamps();
        });

        Schema::create('islamic_partnership_buyouts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_partnership_id')->constrained('islamic_partnerships')->cascadeOnDelete();
            $table->foreignId('islamic_partnership_partner_id')->constrained('islamic_partnership_partners')->cascadeOnDelete();
            $table->foreignId('islamic_partnership_valuation_id')->constrained('islamic_partnership_valuations')->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('idempotency_key', 128)->unique();
            $table->timestamp('executed_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_partnership_buyouts');
        Schema::dropIfExists('islamic_partnership_valuations');
        Schema::dropIfExists('islamic_partnership_losses');
        Schema::dropIfExists('islamic_partnership_profit_declarations');
        Schema::dropIfExists('islamic_partnership_reports');
        Schema::dropIfExists('islamic_partnership_contributions');
        Schema::dropIfExists('islamic_partnership_partners');
        Schema::dropIfExists('islamic_partnerships');
    }
};
