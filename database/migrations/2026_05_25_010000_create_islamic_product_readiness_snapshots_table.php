<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('islamic_product_readiness_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('islamic_product_id')->constrained('islamic_products')->cascadeOnDelete();
            $table->string('review_public_id', 26)->nullable();
            $table->string('family_code', 64);
            $table->string('checklist_template_version', 64);
            $table->json('gate_results');
            $table->string('snapshot_hash', 128);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('evaluated_at');
            $table->timestamps();

            $table->index(['islamic_product_id', 'id']);
            $table->index('review_public_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_product_readiness_snapshots');
    }
};
