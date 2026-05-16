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
        Schema::create('loan_disbursements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->unsignedBigInteger('loan_id');
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->unsignedBigInteger('transfer_account_id')->nullable();
            $table->string('disbursement_channel', 32);
            $table->bigInteger('principal_amount_minor');
            $table->string('currency', 3)->default('XAF');
            $table->string('status', 32)->default('posted')->index();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('loan_id');
            $table->index(['agency_id', 'status']);
        });

        DB::statement('ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES loans (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_transfer_account_agency_foreign FOREIGN KEY (transfer_account_id, agency_id) REFERENCES customer_accounts (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_principal_positive CHECK (principal_amount_minor > 0)');
        DB::statement("ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_status_allowed CHECK (status IN ('posted', 'reversed'))");
        DB::statement("ALTER TABLE loan_disbursements ADD CONSTRAINT loan_disbursements_channel_allowed CHECK (disbursement_channel IN ('transfer_account'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_disbursements');
    }
};
