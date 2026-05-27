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
        Schema::create('islamic_contract_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('family_code', 64);
            $table->string('language_code', 8);
            $table->string('template_code', 128);
            $table->unsignedInteger('version');
            $table->string('status', 32)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('fields_schema')->nullable();
            $table->json('commercial_terms_schema')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('legal_signoff_ref', 128)->nullable();
            $table->string('sharia_signoff_ref', 128)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['template_code', 'version', 'language_code'], 'islamic_contract_templates_template_version_language_unique');
            $table->index(['family_code', 'language_code', 'status'], 'islamic_contract_templates_family_language_status_idx');
        });
        DB::statement("ALTER TABLE islamic_contract_templates ADD CONSTRAINT islamic_contract_templates_status_check CHECK (status IN ('draft','submitted','approved','suspended','revoked','expired','retired','archived'))");
        DB::statement('ALTER TABLE islamic_contract_templates ADD CONSTRAINT islamic_contract_templates_date_window_check CHECK (effective_to IS NULL OR effective_to > effective_from)');

        Schema::create('islamic_contract_template_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->string('contract_subject_type', 64);
            $table->string('contract_subject_public_id', 26);
            $table->string('template_public_id', 26);
            $table->string('template_code', 128);
            $table->unsignedInteger('template_version');
            $table->string('language_code', 8);
            $table->json('template_snapshot');
            $table->json('resolved_terms_snapshot');
            $table->string('snapshot_hash', 128);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['contract_subject_type', 'contract_subject_public_id'], 'islamic_contract_template_snapshots_subject_idx');
            $table->index('template_public_id', 'islamic_contract_template_snapshots_template_public_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('islamic_contract_template_snapshots');
        Schema::dropIfExists('islamic_contract_templates');
    }
};
