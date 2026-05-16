<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emf_ledger_account_mappings', function ($table): void {
            $table->ulid('public_id')->nullable()->after('id');
        });

        DB::table('emf_ledger_account_mappings')
            ->whereNull('public_id')
            ->orderBy('id')
            ->chunkById(100, function ($records): void {
                foreach ($records as $record) {
                    DB::table('emf_ledger_account_mappings')
                        ->where('id', $record->id)
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });

        DB::statement('ALTER TABLE emf_ledger_account_mappings ALTER COLUMN public_id SET NOT NULL');
        DB::statement('ALTER TABLE emf_ledger_account_mappings ADD CONSTRAINT emf_ledger_account_mappings_public_id_unique UNIQUE (public_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emf_ledger_account_mappings DROP CONSTRAINT IF EXISTS emf_ledger_account_mappings_public_id_unique');

        Schema::table('emf_ledger_account_mappings', function ($table): void {
            $table->dropColumn('public_id');
        });
    }
};
