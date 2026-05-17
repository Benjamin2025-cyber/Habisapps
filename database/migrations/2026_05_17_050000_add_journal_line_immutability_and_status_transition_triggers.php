<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_journal_line_immutability() RETURNS TRIGGER AS $$
DECLARE
    parent_status TEXT;
    target_entry_id BIGINT;
BEGIN
    IF TG_OP = 'DELETE' THEN
        target_entry_id := OLD.journal_entry_id;
    ELSE
        target_entry_id := NEW.journal_entry_id;
    END IF;
    SELECT status INTO parent_status FROM journal_entries WHERE id = target_entry_id;
    IF parent_status <> 'draft' AND (
        TG_OP <> 'INSERT'
        OR EXISTS (
            SELECT 1 FROM journal_lines
            WHERE journal_entry_id = target_entry_id
            AND id <> COALESCE(NEW.id, -1)
            LIMIT 1
        )
    ) THEN
        RAISE EXCEPTION 'Journal lines under % entries are immutable (entry %)', parent_status, target_entry_id
          USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement('DROP TRIGGER IF EXISTS journal_lines_immutability_before_update_delete ON journal_lines');
        DB::statement(<<<'SQL'
CREATE TRIGGER journal_lines_immutability_before_update_delete
BEFORE INSERT OR UPDATE OR DELETE ON journal_lines
FOR EACH ROW
EXECUTE FUNCTION enforce_journal_line_immutability();
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_single_currency_journal_lines() RETURNS TRIGGER AS $$
DECLARE
    first_currency TEXT;
BEGIN
    SELECT currency INTO first_currency
      FROM journal_lines
      WHERE journal_entry_id = NEW.journal_entry_id
        AND id <> COALESCE(NEW.id, -1)
      ORDER BY id
      LIMIT 1;

    IF first_currency IS NOT NULL AND first_currency <> NEW.currency THEN
        RAISE EXCEPTION 'Journal entry % already uses currency %, cannot add %', NEW.journal_entry_id, first_currency, NEW.currency
          USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement('DROP TRIGGER IF EXISTS journal_lines_single_currency_before_insert_update ON journal_lines');
        DB::statement(<<<'SQL'
CREATE TRIGGER journal_lines_single_currency_before_insert_update
BEFORE INSERT OR UPDATE OF currency, journal_entry_id ON journal_lines
FOR EACH ROW
EXECUTE FUNCTION enforce_single_currency_journal_lines();
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_journal_entry_status_transitions() RETURNS TRIGGER AS $$
BEGIN
    IF OLD.status = NEW.status THEN
        RETURN NEW;
    END IF;
    IF OLD.status = 'draft' AND NEW.status NOT IN ('submitted', 'cancelled', 'archived') THEN
        RAISE EXCEPTION 'Draft journal entries can only be submitted, cancelled, or archived (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'submitted' AND NEW.status NOT IN ('approved', 'rejected', 'cancelled', 'archived') THEN
        RAISE EXCEPTION 'Submitted journal entries can only be approved, rejected, cancelled, or archived (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'posted' AND NEW.status <> 'reversed' THEN
        RAISE EXCEPTION 'Posted journal entries can only transition to reversed (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'reversed' THEN
        RAISE EXCEPTION 'Reversed journal entries are immutable (attempted transition to %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'cancelled' THEN
        RAISE EXCEPTION 'Cancelled journal entries are immutable (attempted transition to %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'archived' THEN
        RAISE EXCEPTION 'Archived journal entries are immutable (attempted transition to %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'rejected' AND NEW.status NOT IN ('cancelled', 'archived', 'draft') THEN
        RAISE EXCEPTION 'Rejected journal entries can only be cancelled, archived, or reworked to draft (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    IF OLD.status = 'approved' AND NEW.status NOT IN ('posted', 'rejected', 'archived') THEN
        RAISE EXCEPTION 'Approved journal entries can only be posted, rejected, or archived (attempted %)', NEW.status
          USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement('DROP TRIGGER IF EXISTS journal_entries_status_transitions_before_update ON journal_entries');
        DB::statement(<<<'SQL'
CREATE TRIGGER journal_entries_status_transitions_before_update
BEFORE UPDATE OF status ON journal_entries
FOR EACH ROW
EXECUTE FUNCTION enforce_journal_entry_status_transitions();
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS journal_entries_status_transitions_before_update ON journal_entries');
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_single_currency_before_insert_update ON journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS enforce_single_currency_journal_lines()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_journal_entry_status_transitions()');
        DB::statement('DROP TRIGGER IF EXISTS journal_lines_immutability_before_update_delete ON journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS enforce_journal_line_immutability()');
    }
};
