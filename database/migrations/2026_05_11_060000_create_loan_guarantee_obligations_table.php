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
        DB::statement('ALTER TABLE client_guarantors ADD CONSTRAINT client_guarantors_id_agency_unique UNIQUE (id, agency_id)');

        Schema::create('loan_guarantee_obligations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->unsignedBigInteger('loan_id');
            $table->unsignedBigInteger('client_guarantor_id');
            $table->string('obligation_type', 64)->default('personal_guarantee');
            $table->bigInteger('obligation_amount_minor')->nullable();
            $table->decimal('obligation_percentage', 9, 6)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('release_condition', 128)->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->json('guarantor_identity_snapshot')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index(['loan_id', 'status']);
            $table->index(['client_guarantor_id', 'status']);
        });

        DB::statement('ALTER TABLE loan_guarantee_obligations ADD CONSTRAINT loan_guarantee_obligations_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES loans (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_guarantee_obligations ADD CONSTRAINT loan_guarantee_obligations_guarantor_agency_foreign FOREIGN KEY (client_guarantor_id, agency_id) REFERENCES client_guarantors (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_guarantee_obligations ADD CONSTRAINT loan_guarantee_obligations_document_agency_foreign FOREIGN KEY (document_id, agency_id) REFERENCES documents (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_guarantee_obligations ADD CONSTRAINT loan_guarantee_obligations_amount_non_negative CHECK (obligation_amount_minor IS NULL OR obligation_amount_minor >= 0)');
        DB::statement('ALTER TABLE loan_guarantee_obligations ADD CONSTRAINT loan_guarantee_obligations_percentage_valid CHECK (obligation_percentage IS NULL OR (obligation_percentage >= 0 AND obligation_percentage <= 100))');
        DB::statement("ALTER TABLE loan_guarantee_obligations ADD CONSTRAINT loan_guarantee_obligations_release_consistent CHECK ((status <> 'released' AND released_at IS NULL) OR (status = 'released' AND released_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_guarantee_obligations');

        DB::statement('ALTER TABLE client_guarantors DROP CONSTRAINT IF EXISTS client_guarantors_id_agency_unique');
    }
};
