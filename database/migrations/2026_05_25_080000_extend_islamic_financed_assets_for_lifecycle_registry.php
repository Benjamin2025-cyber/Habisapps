<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('islamic_financed_assets', function (Blueprint $table): void {
            $table->string('lifecycle_status', 32)->default('requested')->after('ownership_status')->index();
            $table->string('condition_status', 64)->nullable()->after('lifecycle_status');
            $table->string('asset_category', 64)->nullable()->after('asset_type');
            $table->string('supplier_name', 255)->nullable()->after('description');
            $table->string('supplier_reference', 128)->nullable()->after('supplier_name');
            $table->bigInteger('acquisition_cost_minor')->nullable()->after('purchase_amount_minor');
            $table->string('location', 255)->nullable()->after('currency');
            $table->json('document_bundle')->nullable()->after('location');
            $table->string('customer_request_ref', 128)->nullable()->after('document_bundle');
            $table->ulid('screening_result_public_id')->nullable()->after('customer_request_ref');

            $table->index(['islamic_financing_id', 'lifecycle_status'], 'if040_asset_financing_status_idx');
        });

        Schema::create('islamic_financed_asset_transitions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('islamic_financed_asset_id')->constrained('islamic_financed_assets')->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason_code', 64)->nullable();
            $table->text('reason_note')->nullable();
            $table->string('product_family', 64)->nullable();
            $table->ulid('screening_result_public_id')->nullable();
            $table->ulid('compliance_case_public_id')->nullable();
            $table->json('evidence_refs')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamp('transitioned_at')->useCurrent();
            $table->timestamps();

            $table->index(['islamic_financed_asset_id', 'transitioned_at'], 'if040_transition_asset_time_idx');
            $table->index(['islamic_financed_asset_id', 'to_status'], 'if040_transition_asset_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_financed_asset_transitions');

        Schema::table('islamic_financed_assets', function (Blueprint $table): void {
            $table->dropIndex('if040_asset_financing_status_idx');
            $table->dropColumn([
                'lifecycle_status',
                'condition_status',
                'asset_category',
                'supplier_name',
                'supplier_reference',
                'acquisition_cost_minor',
                'location',
                'document_bundle',
                'customer_request_ref',
                'screening_result_public_id',
            ]);
        });
    }
};
