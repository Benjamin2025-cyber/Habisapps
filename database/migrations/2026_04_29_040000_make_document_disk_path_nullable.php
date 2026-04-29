<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents ALTER COLUMN disk DROP NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN path DROP NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN original_name DROP NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN mime_type DROP NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN size_bytes DROP NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN checksum_sha256 DROP NOT NULL');
    }

    public function down(): void
    {
        DB::table('documents')
            ->whereNull('disk')
            ->update(['disk' => 'local']);

        DB::table('documents')
            ->whereNull('path')
            ->update([
                'path' => DB::raw("concat('legacy-unavailable-', public_id)"),
            ]);

        DB::table('documents')
            ->whereNull('original_name')
            ->update(['original_name' => 'legacy-unavailable']);

        DB::table('documents')
            ->whereNull('mime_type')
            ->update(['mime_type' => 'application/octet-stream']);

        DB::table('documents')
            ->whereNull('size_bytes')
            ->update(['size_bytes' => 0]);

        DB::table('documents')
            ->whereNull('checksum_sha256')
            ->update(['checksum_sha256' => str_repeat('0', 64)]);

        DB::statement('ALTER TABLE documents ALTER COLUMN checksum_sha256 SET NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN size_bytes SET NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN mime_type SET NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN original_name SET NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN path SET NOT NULL');
        DB::statement('ALTER TABLE documents ALTER COLUMN disk SET NOT NULL');
    }
};
