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
        Schema::create('regulatory_sources', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('authority', 32)->index();
            $table->string('reference', 191);
            $table->string('title');
            $table->date('effective_date')->nullable();
            $table->string('checksum', 128);
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('imported_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['authority', 'reference', 'effective_date'], 'uniq_regulatory_source_axis');
        });

        DB::statement(<<<'SQL'
ALTER TABLE regulatory_sources ADD CONSTRAINT regulatory_sources_authority_valid
  CHECK (authority IN ('cobac', 'beac', 'cima', 'ohada', 'cnps', 'aaoifi', 'other'))
SQL);

        Schema::table('emf_regulatory_accounts', function (Blueprint $table): void {
            $table->foreignId('regulatory_source_id')->nullable()->after('public_id')->constrained('regulatory_sources')->nullOnDelete();
        });

        Schema::table('report_definitions', function (Blueprint $table): void {
            $table->foreignId('regulatory_source_id')->nullable()->after('public_id')->constrained('regulatory_sources')->nullOnDelete();
            $table->unsignedInteger('version')->default(1)->after('code');
            $table->date('effective_from')->nullable()->after('status');
            $table->date('effective_to')->nullable()->after('effective_from');
        });

        DB::statement('ALTER TABLE report_definitions DROP CONSTRAINT IF EXISTS report_definitions_code_unique');
        DB::statement('ALTER TABLE report_definitions ADD CONSTRAINT report_definitions_code_version_unique UNIQUE (code, version)');

        Schema::table('report_runs', function (Blueprint $table): void {
            $table->string('review_status', 16)->default('pending')->after('status')->index();
            $table->foreignId('reviewed_by_user_id')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            $table->text('review_comments')->nullable()->after('reviewed_at');
            $table->timestamp('submitted_at')->nullable()->after('review_comments');
            $table->foreignId('submitted_by_user_id')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->string('submission_channel', 32)->nullable()->after('submitted_by_user_id');
            $table->string('submission_reference', 191)->nullable()->after('submission_channel');
            $table->json('source_version_snapshot')->nullable()->after('submission_reference');
        });

        DB::statement(<<<'SQL'
ALTER TABLE report_runs ADD CONSTRAINT report_runs_review_status_valid
  CHECK (review_status IN ('pending', 'approved', 'rejected'))
SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE report_runs DROP CONSTRAINT IF EXISTS report_runs_review_status_valid');
        Schema::table('report_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'review_status',
                'reviewed_by_user_id',
                'reviewed_at',
                'review_comments',
                'submitted_at',
                'submitted_by_user_id',
                'submission_channel',
                'submission_reference',
                'source_version_snapshot',
            ]);
        });

        DB::statement('ALTER TABLE report_definitions DROP CONSTRAINT IF EXISTS report_definitions_code_version_unique');
        DB::statement('ALTER TABLE report_definitions ADD CONSTRAINT report_definitions_code_unique UNIQUE (code)');

        Schema::table('report_definitions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('regulatory_source_id');
            $table->dropColumn(['version', 'effective_from', 'effective_to']);
        });

        Schema::table('emf_regulatory_accounts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('regulatory_source_id');
        });

        DB::statement('ALTER TABLE regulatory_sources DROP CONSTRAINT IF EXISTS regulatory_sources_authority_valid');
        Schema::dropIfExists('regulatory_sources');
    }
};
