<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_journal_entry_balance(target_entry_id BIGINT) RETURNS VOID AS $$
DECLARE
    entry_status TEXT;
    debit_total BIGINT;
    credit_total BIGINT;
BEGIN
    SELECT status INTO entry_status FROM journal_entries WHERE id = target_entry_id;
    IF entry_status IS NULL OR entry_status IN ('draft', 'cancelled', 'rejected') THEN
        RETURN;
    END IF;
    SELECT COALESCE(SUM(debit_minor), 0), COALESCE(SUM(credit_minor), 0)
      INTO debit_total, credit_total
      FROM journal_lines
      WHERE journal_entry_id = target_entry_id;
    IF debit_total <> credit_total THEN
        RAISE EXCEPTION 'Journal entry % is unbalanced: debit=% credit=%', target_entry_id, debit_total, credit_total
          USING ERRCODE = '23514';
    END IF;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_entries_balance_trigger_fn() RETURNS TRIGGER AS $$
BEGIN
    PERFORM enforce_journal_entry_balance(NEW.id);
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION journal_lines_balance_trigger_fn() RETURNS TRIGGER AS $$
DECLARE
    target_id BIGINT;
BEGIN
    IF TG_OP = 'DELETE' THEN
        target_id := OLD.journal_entry_id;
    ELSE
        target_id := NEW.journal_entry_id;
    END IF;
    PERFORM enforce_journal_entry_balance(target_id);
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement('DROP TRIGGER IF EXISTS journal_entries_balance_after_insert ON journal_entries');
        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER journal_entries_balance_after_insert
AFTER INSERT ON journal_entries
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION journal_entries_balance_trigger_fn();
SQL);

        DB::statement('DROP TRIGGER IF EXISTS journal_entries_balance_after_update ON journal_entries');
        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER journal_entries_balance_after_update
AFTER UPDATE ON journal_entries
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION journal_entries_balance_trigger_fn();
SQL);

        DB::statement('DROP TRIGGER IF EXISTS journal_lines_balance_after_change ON journal_lines');
        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER journal_lines_balance_after_change
AFTER INSERT OR UPDATE OR DELETE ON journal_lines
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION journal_lines_balance_trigger_fn();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_balance_after_change ON journal_lines');
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_balance_after_update ON journal_entries');
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_balance_after_insert ON journal_entries');
        DB::statement('DROP FUNCTION IF EXISTS journal_lines_balance_trigger_fn()');
        DB::statement('DROP FUNCTION IF EXISTS journal_entries_balance_trigger_fn()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_journal_entry_balance(BIGINT)');
    }
};
