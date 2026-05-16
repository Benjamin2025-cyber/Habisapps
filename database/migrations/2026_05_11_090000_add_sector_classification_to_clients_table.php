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
        DB::statement('ALTER TABLE sub_sectors ADD CONSTRAINT sub_sectors_id_sector_unique UNIQUE (id, sector_id)');

        Schema::table('clients', function (Blueprint $table): void {
            $table->foreignId('sector_id')->nullable()->after('collection_agent_id')->constrained('sectors')->nullOnDelete();
            $table->foreignId('sub_sector_id')->nullable()->after('sector_id')->constrained('sub_sectors')->nullOnDelete();
        });

        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_sub_sector_requires_sector CHECK (sub_sector_id IS NULL OR sector_id IS NOT NULL)');
        DB::statement('ALTER TABLE clients ADD CONSTRAINT clients_sub_sector_matches_sector FOREIGN KEY (sub_sector_id, sector_id) REFERENCES sub_sectors (id, sector_id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_sub_sector_requires_sector CHECK (sub_sector_id IS NULL OR sector_id IS NOT NULL)');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_sub_sector_matches_sector FOREIGN KEY (sub_sector_id, sector_id) REFERENCES sub_sectors (id, sector_id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_sub_sector_matches_sector');
        DB::statement('ALTER TABLE loans DROP CONSTRAINT IF EXISTS loans_sub_sector_requires_sector');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_sub_sector_matches_sector');
        DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_sub_sector_requires_sector');
        DB::statement('ALTER TABLE sub_sectors DROP CONSTRAINT IF EXISTS sub_sectors_id_sector_unique');

        Schema::table('clients', function (Blueprint $table): void {
            $table->dropForeign(['sub_sector_id']);
            $table->dropForeign(['sector_id']);
            $table->dropColumn(['sub_sector_id', 'sector_id']);
        });
    }
};
