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
        Schema::create('loan_repayments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('agency_id')->constrained('agencies')->restrictOnDelete();
            $table->unsignedBigInteger('loan_id');
            $table->foreignId('journal_entry_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->unsignedBigInteger('customer_account_id');
            $table->bigInteger('received_amount_minor');
            $table->bigInteger('allocated_amount_minor');
            $table->bigInteger('overpayment_retained_minor')->default(0);
            $table->string('currency', 3)->default('XAF');
            $table->date('paid_on')->index();
            $table->string('status', 32)->default('posted')->index();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['loan_id', 'paid_on']);
            $table->index(['agency_id', 'status']);
        });

        Schema::create('loan_repayment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_repayment_id')->constrained('loan_repayments')->cascadeOnDelete();
            $table->foreignId('loan_schedule_line_id')->constrained('loan_schedule_lines')->restrictOnDelete();
            $table->string('component', 32);
            $table->bigInteger('amount_minor');
            $table->string('currency', 3)->default('XAF');
            $table->timestamps();

            $table->index(['loan_schedule_line_id', 'component']);
        });

        DB::statement('ALTER TABLE loan_repayments ADD CONSTRAINT loan_repayments_loan_agency_foreign FOREIGN KEY (loan_id, agency_id) REFERENCES loans (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_repayments ADD CONSTRAINT loan_repayments_customer_account_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES customer_accounts (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE loan_repayments ADD CONSTRAINT loan_repayments_amounts_non_negative CHECK (received_amount_minor > 0 AND allocated_amount_minor >= 0 AND overpayment_retained_minor >= 0 AND received_amount_minor >= allocated_amount_minor)');
        DB::statement("ALTER TABLE loan_repayments ADD CONSTRAINT loan_repayments_status_allowed CHECK (status IN ('posted', 'reversed'))");
        DB::statement('ALTER TABLE loan_repayment_allocations ADD CONSTRAINT loan_repayment_allocations_amount_positive CHECK (amount_minor > 0)');
        DB::statement("ALTER TABLE loan_repayment_allocations ADD CONSTRAINT loan_repayment_allocations_component_allowed CHECK (component IN ('principal', 'interest', 'fees', 'insurance', 'tax', 'penalty'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_repayment_allocations');
        Schema::dropIfExists('loan_repayments');
    }
};
