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
            $table->foreignId('customer_account_signature_id')->nullable()->after('initiator_proxy_id')->constrained('customer_account_signatures')->restrictOnDelete();
            $table->timestamp('signature_checked_at')->nullable()->after('customer_account_signature_id');
            $table->foreignId('signature_checked_by_user_id')->nullable()->after('signature_checked_at')->constrained('users')->nullOnDelete();
            $table->string('signature_verification_method', 32)->nullable()->after('signature_checked_by_user_id');

            $table->index(['customer_account_signature_id', 'status'], 'teller_transactions_signature_status_index');
        });

        DB::statement('ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_signature_agency_foreign FOREIGN KEY (customer_account_signature_id, agency_id) REFERENCES customer_account_signatures (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_signature_account_foreign FOREIGN KEY (customer_account_signature_id, customer_account_id) REFERENCES customer_account_signatures (id, customer_account_id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_signature_method_check CHECK (signature_verification_method IS NULL OR signature_verification_method IN ('visual_match', 'thumbprint_match', 'verified_proxy_mandate', 'exception_override'))");
        DB::statement('ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_signature_check_fields_check CHECK ((customer_account_signature_id IS NULL AND signature_checked_at IS NULL AND signature_checked_by_user_id IS NULL AND signature_verification_method IS NULL) OR (customer_account_signature_id IS NOT NULL AND signature_checked_at IS NOT NULL AND signature_checked_by_user_id IS NOT NULL AND signature_verification_method IS NOT NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_signature_check_fields_check');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_signature_method_check');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_signature_account_foreign');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_signature_agency_foreign');

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropIndex('teller_transactions_signature_status_index');
            $table->dropForeign(['customer_account_signature_id']);
            $table->dropForeign(['signature_checked_by_user_id']);
            $table->dropColumn([
                'customer_account_signature_id',
                'signature_checked_at',
                'signature_checked_by_user_id',
                'signature_verification_method',
            ]);
        });
    }
};
