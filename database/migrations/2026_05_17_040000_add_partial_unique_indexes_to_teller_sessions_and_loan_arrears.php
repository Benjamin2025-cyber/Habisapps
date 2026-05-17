<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX uniq_open_teller_session_per_till ON teller_sessions (till_id) WHERE status = 'open'");
        DB::statement("CREATE UNIQUE INDEX uniq_open_teller_session_per_teller ON teller_sessions (teller_user_id) WHERE status = 'open'");

        DB::statement('CREATE UNIQUE INDEX uniq_loan_arrears_per_schedule_line ON loan_arrears (loan_schedule_line_id) WHERE loan_schedule_line_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS uniq_loan_arrears_per_schedule_line');
        DB::statement('DROP INDEX IF EXISTS uniq_open_teller_session_per_teller');
        DB::statement('DROP INDEX IF EXISTS uniq_open_teller_session_per_till');
    }
};
