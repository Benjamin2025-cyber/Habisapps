<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One accounting date for the institution, with agency days beneath it.
 *
 * The two scopes were peers: an agency day and the institution day each carried
 * their own business_date with nothing relating them. So head office could sit on
 * 9 August while an agency was on the 7th, the institution day could close with
 * agencies still open, and there was no answer to "what date is the EMF on?" —
 * there were as many answers as agencies, plus one.
 *
 * That is not how this is done. Apache Fineract, built for microfinance, keeps a
 * single tenant-wide business date (m_business_date is unique on `type` alone —
 * there is no office column) and puts period locking per office instead. Finacle
 * is explicit about the hierarchy: the data centre cannot be closed for a date
 * until every branch is closed for that date. Oracle's branch EOD is a
 * prerequisite to advancing one system date, not a date of its own.
 *
 * So the agency day keeps its own open/close cycle — that part is normal — but it
 * runs *within* the institution's date:
 *
 *   1. An agency day may only be active on the institution's current date.
 *   2. The institution day may not close while an agency still has that date open.
 *
 * Enforced with triggers rather than in the workflow alone: seeders, the backfill
 * command and the test helpers all write these rows directly, and an invariant
 * this central should not depend on going through one code path.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (1) An active agency day requires the institution to be on the same date.
        //
        // Checked only when the row is or becomes active. Closing, cancelling or
        // back-filling an agency day is always allowed, otherwise a day could
        // never be tidied up after the institution moved on.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounting_days_agency_day_follows_institution()
            RETURNS trigger AS $$
            DECLARE
                institution_date date;
            BEGIN
                IF NEW.scope_type = 'agency'
                   AND NEW.status IN ('open', 'reopened', 'closing')
                   AND (TG_OP = 'INSERT' OR OLD.status IS DISTINCT FROM NEW.status
                        OR OLD.business_date IS DISTINCT FROM NEW.business_date) THEN

                    SELECT business_date INTO institution_date
                    FROM accounting_days
                    WHERE scope_type = 'institution'
                      AND status IN ('open', 'reopened', 'closing')
                    ORDER BY business_date DESC
                    LIMIT 1;

                    IF institution_date IS NULL THEN
                        RAISE EXCEPTION 'No institution accounting day is open, so agency day % cannot be opened', NEW.business_date
                            USING ERRCODE = 'check_violation';
                    END IF;

                    IF institution_date <> NEW.business_date THEN
                        RAISE EXCEPTION 'Agency day % does not match the institution date %', NEW.business_date, institution_date
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS accounting_days_agency_day_follows_institution ON accounting_days');
        DB::statement(<<<'SQL'
            CREATE TRIGGER accounting_days_agency_day_follows_institution
            BEFORE INSERT OR UPDATE ON accounting_days
            FOR EACH ROW EXECUTE FUNCTION accounting_days_agency_day_follows_institution();
        SQL);

        // (2) The institution day cannot close while an agency still has it open.
        //
        // Finacle's rule. Without it the institution can declare a date finished
        // while tills are still transacting into it, and the figures reported for
        // that date keep changing after they were drawn.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounting_days_institution_close_needs_agencies_closed()
            RETURNS trigger AS $$
            DECLARE
                open_agencies integer;
            BEGIN
                IF NEW.scope_type = 'institution'
                   AND NEW.status = 'closed'
                   AND OLD.status IS DISTINCT FROM 'closed' THEN

                    SELECT count(*) INTO open_agencies
                    FROM accounting_days
                    WHERE scope_type = 'agency'
                      AND business_date = NEW.business_date
                      AND status IN ('open', 'reopened', 'closing');

                    IF open_agencies > 0 THEN
                        RAISE EXCEPTION 'Cannot close the institution day % while % agency day(s) are still open on it', NEW.business_date, open_agencies
                            USING ERRCODE = 'check_violation';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS accounting_days_institution_close_needs_agencies_closed ON accounting_days');
        DB::statement(<<<'SQL'
            CREATE TRIGGER accounting_days_institution_close_needs_agencies_closed
            BEFORE UPDATE ON accounting_days
            FOR EACH ROW EXECUTE FUNCTION accounting_days_institution_close_needs_agencies_closed();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS accounting_days_agency_day_follows_institution ON accounting_days');
        DB::statement('DROP FUNCTION IF EXISTS accounting_days_agency_day_follows_institution()');
        DB::statement('DROP TRIGGER IF EXISTS accounting_days_institution_close_needs_agencies_closed ON accounting_days');
        DB::statement('DROP FUNCTION IF EXISTS accounting_days_institution_close_needs_agencies_closed()');
    }
};
