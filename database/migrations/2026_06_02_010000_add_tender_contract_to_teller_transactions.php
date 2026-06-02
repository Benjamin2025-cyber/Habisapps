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
        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->string('payment_method', 16)->default('cash')->after('operation_code')->index();
            $table->unsignedBigInteger('cash_amount_minor')->default(0)->after('payment_method');
            $table->unsignedBigInteger('cheque_amount_minor')->default(0)->after('cash_amount_minor');
            $table->unsignedBigInteger('transfer_amount_minor')->default(0)->after('cheque_amount_minor');
            $table->string('channel', 32)->nullable()->after('transfer_amount_minor')->index();
            $table->string('external_reference', 128)->nullable()->after('channel');
            $table->string('fee_policy_key', 64)->nullable()->after('external_reference');
            $table->boolean('fees_applied')->default(false)->after('fee_policy_key');
            $table->unsignedBigInteger('fee_amount_minor')->default(0)->after('fees_applied');
            $table->boolean('notify_customer')->default(false)->after('fee_amount_minor');
            $table->json('notification_channels')->nullable()->after('notify_customer');
            $table->string('notification_status', 32)->default('not_requested')->after('notification_channels');
        });

        Schema::create('teller_transaction_tenders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('teller_transaction_id')->constrained('teller_transactions')->cascadeOnDelete();
            $table->string('method', 16)->index();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);
            $table->string('status', 32)->default('posted')->index();
            $table->string('channel', 32)->nullable();
            $table->string('external_reference', 128)->nullable();
            $table->string('cheque_number', 64)->nullable();
            $table->string('cheque_bank_name', 128)->nullable();
            $table->date('cheque_issue_date')->nullable();
            $table->foreignId('debit_ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->foreignId('credit_ledger_account_id')->nullable()->constrained('ledger_accounts')->nullOnDelete();
            $table->json('ledger_mapping_evidence')->nullable();
            $table->json('denomination_counts')->nullable();
            $table->timestamps();

            $table->unique(['teller_transaction_id', 'method'], 'teller_transaction_tenders_method_unique');
        });

        DB::statement("ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_payment_method_check CHECK (payment_method IN ('cash', 'cheque', 'transfer', 'mixed'))");
        DB::statement("ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_notification_status_check CHECK (notification_status IN ('not_requested', 'queued', 'failed'))");
        DB::statement("ALTER TABLE teller_transaction_tenders ADD CONSTRAINT teller_transaction_tenders_method_check CHECK (method IN ('cash', 'cheque', 'transfer'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE teller_transaction_tenders DROP CONSTRAINT IF EXISTS teller_transaction_tenders_method_check');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_notification_status_check');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_payment_method_check');

        Schema::dropIfExists('teller_transaction_tenders');

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropColumn([
                'payment_method',
                'cash_amount_minor',
                'cheque_amount_minor',
                'transfer_amount_minor',
                'channel',
                'external_reference',
                'fee_policy_key',
                'fees_applied',
                'fee_amount_minor',
                'notify_customer',
                'notification_channels',
                'notification_status',
            ]);
        });
    }
};
