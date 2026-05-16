<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->foreignId('customer_account_id')->nullable()->after('client_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->json('operation_types')->nullable()->after('mandate_type');
            $table->unsignedBigInteger('max_amount_minor')->nullable()->after('operation_types');
            $table->string('limit_currency', 3)->nullable()->after('max_amount_minor');

            $table->index(['customer_account_id', 'status'], 'client_proxies_account_status_index');
        });

        DB::statement('ALTER TABLE client_proxies ADD CONSTRAINT client_proxies_account_agency_foreign FOREIGN KEY (customer_account_id, agency_id) REFERENCES customer_accounts (id, agency_id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE client_proxies ADD CONSTRAINT client_proxies_limit_currency_required CHECK (max_amount_minor IS NULL OR limit_currency IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE client_proxies DROP CONSTRAINT IF EXISTS client_proxies_limit_currency_required');
        DB::statement('ALTER TABLE client_proxies DROP CONSTRAINT IF EXISTS client_proxies_account_agency_foreign');

        Schema::table('client_proxies', function (Blueprint $table): void {
            $table->dropIndex('client_proxies_account_status_index');
            $table->dropForeign(['customer_account_id']);
            $table->dropColumn([
                'customer_account_id',
                'operation_types',
                'max_amount_minor',
                'limit_currency',
            ]);
        });
    }
};
