<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes batch-procedure execution priority from generic schedule_metadata
 * into a first-class, validated column (GHI-010D). Existing rows that carry an
 * integer `schedule_metadata.execution_priority` are backfilled into the new
 * column; `schedule_metadata` is left intact as a backward-compatibility source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('batch_procedures', function (Blueprint $table): void {
            $table->unsignedSmallInteger('execution_priority')->nullable()->after('schedule_type');
        });

        // Backfill from the legacy metadata key when it holds an integer.
        DB::statement(<<<'SQL'
            UPDATE batch_procedures
            SET execution_priority = (schedule_metadata->>'execution_priority')::smallint
            WHERE execution_priority IS NULL
              AND jsonb_exists(schedule_metadata::jsonb, 'execution_priority')
              AND (schedule_metadata->>'execution_priority') ~ '^[0-9]+$'
              AND (schedule_metadata->>'execution_priority')::bigint <= 65535
        SQL);
    }

    public function down(): void
    {
        Schema::table('batch_procedures', function (Blueprint $table): void {
            $table->dropColumn('execution_priority');
        });
    }
};
