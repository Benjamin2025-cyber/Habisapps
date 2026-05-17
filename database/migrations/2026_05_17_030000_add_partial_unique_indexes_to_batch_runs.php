<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX uniq_running_global_batch_run ON batch_runs (batch_procedure_id, business_date) WHERE agency_id IS NULL AND status = 'running'");
        DB::statement("CREATE UNIQUE INDEX uniq_running_agency_batch_run ON batch_runs (batch_procedure_id, agency_id, business_date) WHERE agency_id IS NOT NULL AND status = 'running'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_running_agency_batch_run');
        DB::statement('DROP INDEX IF EXISTS uniq_running_global_batch_run');
    }
};
