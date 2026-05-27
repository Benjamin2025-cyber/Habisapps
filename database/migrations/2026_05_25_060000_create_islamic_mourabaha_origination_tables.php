<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islamic_mourabaha_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
            $table->foreignId('islamic_product_id')->nullable()->constrained('islamic_products')->nullOnDelete();
            $table->foreignId('islamic_financing_id')->nullable()->constrained('islamic_financings')->nullOnDelete();
            $table->string('request_status', 32)->default('draft');
            $table->string('asset_type', 64)->nullable();
            $table->text('asset_description')->nullable();
            $table->bigInteger('requested_purchase_cost_minor')->nullable();
            $table->string('supplier_name')->nullable();
            $table->json('request_context')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_mourabaha_supplier_quotes', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('mourabaha_request_id')->constrained('islamic_mourabaha_requests')->cascadeOnDelete();
            $table->string('supplier_name', 255);
            $table->bigInteger('quoted_purchase_cost_minor');
            $table->bigInteger('quoted_allowed_costs_minor')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->date('valid_until')->nullable();
            $table->boolean('is_selected')->default(false);
            $table->json('quote_context')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_mourabaha_purchase_approvals', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('mourabaha_request_id')->constrained('islamic_mourabaha_requests')->cascadeOnDelete();
            $table->foreignId('supplier_quote_id')->nullable()->constrained('islamic_mourabaha_supplier_quotes')->nullOnDelete();
            $table->string('decision', 32);
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->json('decision_context')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_mourabaha_purchase_evidences', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->foreignId('mourabaha_request_id')->nullable()->constrained('islamic_mourabaha_requests')->nullOnDelete();
            $table->string('evidence_type', 64)->default('supplier_invoice');
            $table->string('document_public_id')->nullable();
            $table->string('institution_control_status', 32)->default('pending');
            $table->json('evidence_context')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_mourabaha_cost_evidences', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->string('cost_type', 64);
            $table->bigInteger('amount_minor');
            $table->string('document_public_id')->nullable();
            $table->json('evidence_context')->nullable();
            $table->timestamps();
        });

        Schema::create('islamic_mourabaha_contract_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('islamic_financing_id')->constrained('islamic_financings')->cascadeOnDelete();
            $table->json('snapshot_payload');
            $table->string('snapshot_hash', 64);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_mourabaha_contract_snapshots');
        Schema::dropIfExists('islamic_mourabaha_cost_evidences');
        Schema::dropIfExists('islamic_mourabaha_purchase_evidences');
        Schema::dropIfExists('islamic_mourabaha_purchase_approvals');
        Schema::dropIfExists('islamic_mourabaha_supplier_quotes');
        Schema::dropIfExists('islamic_mourabaha_requests');
    }
};
