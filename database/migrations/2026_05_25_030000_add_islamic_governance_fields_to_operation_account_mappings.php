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
        Schema::table('operation_account_mappings', function (Blueprint $table): void {
            $table->foreignId('agency_id')->nullable()->after('operation_code_id')->constrained('agencies')->nullOnDelete();
            $table->date('effective_from')->nullable()->after('currency');
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->string('approval_status', 32)->default('draft')->after('status')->index();
            $table->foreignId('accounting_owner_user_id')->nullable()->after('approval_status')->constrained('users')->nullOnDelete();
            $table->boolean('sharia_approval_required')->default(false)->after('accounting_owner_user_id');
            $table->string('sharia_approval_status', 32)->default('not_required')->after('sharia_approval_required');
            $table->foreignId('approved_by_user_id')->nullable()->after('sharia_approval_status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');

            $table->index(['operation_code_id', 'agency_id', 'currency'], 'op_map_lookup_scope_idx');
            $table->index(['approval_status', 'effective_from', 'effective_to'], 'op_map_approval_window_idx');
        });

        $today = now()->toDateString();

        DB::table('operation_account_mappings')->whereNull('effective_from')->update([
            'effective_from' => $today,
        ]);

        DB::statement(
            "UPDATE operation_account_mappings
             SET approval_status = CASE
                 WHEN status = 'active' THEN 'approved'
                 WHEN status = 'archived' THEN 'archived'
                 ELSE 'draft'
             END
             WHERE approval_status IS NULL OR approval_status = '' OR approval_status = 'draft'"
        );

        DB::statement(
            "UPDATE operation_account_mappings
             SET sharia_approval_status = CASE
                 WHEN sharia_approval_required = TRUE THEN 'pending'
                 ELSE 'not_required'
             END
             WHERE sharia_approval_status IS NULL OR sharia_approval_status = ''"
        );

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE operation_account_mappings
                 ADD CONSTRAINT op_map_effective_window_chk
                 CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to > effective_from)'
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE operation_account_mappings DROP CONSTRAINT IF EXISTS op_map_effective_window_chk');
        }

        Schema::table('operation_account_mappings', function (Blueprint $table): void {
            $table->dropIndex('op_map_lookup_scope_idx');
            $table->dropIndex('op_map_approval_window_idx');

            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn('approved_at');
            $table->dropColumn('sharia_approval_status');
            $table->dropColumn('sharia_approval_required');
            $table->dropConstrainedForeignId('accounting_owner_user_id');
            $table->dropColumn('approval_status');
            $table->dropColumn('effective_to');
            $table->dropColumn('effective_from');
            $table->dropConstrainedForeignId('agency_id');
        });
    }
};
