<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->string('initiator_type', 32)->nullable()->after('depositor_address');
            $table->foreignId('initiator_proxy_id')->nullable()->after('initiator_type')->constrained('client_proxies')->nullOnDelete();
        });

        DB::table('teller_transactions')
            ->whereNull('initiator_type')
            ->update(['initiator_type' => 'staff_on_behalf']);

        DB::statement("ALTER TABLE teller_transactions ALTER COLUMN initiator_type SET NOT NULL");
        DB::statement("ALTER TABLE teller_transactions ALTER COLUMN initiator_type SET DEFAULT 'staff_on_behalf'");
        DB::statement("ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_initiator_type_check CHECK (initiator_type IN ('holder', 'proxy', 'staff_on_behalf', 'system'))");
        DB::statement("ALTER TABLE teller_transactions ADD CONSTRAINT teller_transactions_proxy_initiator_link_check CHECK ((initiator_type = 'proxy') = (initiator_proxy_id IS NOT NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_proxy_initiator_link_check');
        DB::statement('ALTER TABLE teller_transactions DROP CONSTRAINT IF EXISTS teller_transactions_initiator_type_check');

        Schema::table('teller_transactions', function (Blueprint $table): void {
            $table->dropForeign(['initiator_proxy_id']);
            $table->dropColumn(['initiator_type', 'initiator_proxy_id']);
        });
    }
};
