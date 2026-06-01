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
        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->foreignId('accounting_day_id')->nullable()->after('agency_id')->constrained('accounting_days')->nullOnDelete();
            $table->index(['accounting_day_id', 'status']);
        });

        // Database-level invariant: a journal entry may only transition INTO the
        // posted state when its linked accounting day is open or reopened.
        // Backfill (which sets accounting_day_id on already-posted rows without
        // changing status) is exempt because OLD.status is already 'posted'.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entries_guard_closed_day_posting()
            RETURNS trigger AS $$
            DECLARE
                day_status text;
            BEGIN
                IF NEW.status = 'posted'
                   AND (TG_OP = 'INSERT' OR OLD.status IS DISTINCT FROM 'posted')
                   AND NEW.accounting_day_id IS NOT NULL THEN
                    SELECT status INTO day_status FROM accounting_days WHERE id = NEW.accounting_day_id;
                    IF day_status IS NOT NULL AND day_status NOT IN ('open', 'reopened') THEN
                        RAISE EXCEPTION 'Cannot post journal entry % into accounting day with status %', NEW.reference, day_status
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS journal_entries_guard_closed_day_posting ON journal_entries');
        DB::statement(<<<'SQL'
            CREATE TRIGGER journal_entries_guard_closed_day_posting
            BEFORE INSERT OR UPDATE ON journal_entries
            FOR EACH ROW EXECUTE FUNCTION journal_entries_guard_closed_day_posting();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_guard_closed_day_posting ON journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS journal_entries_guard_closed_day_posting()');

        Schema::table('journal_entries', function (Blueprint $table): void {
            $table->dropForeign(['accounting_day_id']);
            $table->dropIndex(['accounting_day_id', 'status']);
            $table->dropColumn('accounting_day_id');
        });
    }
};
