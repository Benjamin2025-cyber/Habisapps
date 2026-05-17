<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_products', function (Blueprint $table): void {
            $table->boolean('allows_overdraft')->default(false)->after('is_ordinary_savings');
            $table->bigInteger('overdraft_limit_minor')->default(0)->after('allows_overdraft');
        });

        Schema::table('account_holds', function (Blueprint $table): void {
            $table->string('source_type', 64)->nullable()->after('reason_type');
            $table->string('source_public_id', 64)->nullable()->after('source_type');
            $table->timestamp('expires_at')->nullable()->after('placed_at');
            $table->string('release_reason', 255)->nullable()->after('released_by_user_id');
        });

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_customer_account_non_overdraft(target_account_id BIGINT, target_currency TEXT) RETURNS VOID AS $$
DECLARE
    balance_minor BIGINT;
    allows_overdraft BOOLEAN;
    overdraft_limit BIGINT;
BEGIN
    IF target_account_id IS NULL OR target_currency IS NULL THEN
        RETURN;
    END IF;

    SELECT COALESCE(ap.allows_overdraft, FALSE), COALESCE(ap.overdraft_limit_minor, 0)
      INTO allows_overdraft, overdraft_limit
      FROM customer_accounts ca
      LEFT JOIN account_products ap ON ap.id = ca.account_product_id
      WHERE ca.id = target_account_id;

    SELECT COALESCE(SUM(
        CASE
            WHEN la.normal_balance_side = 'debit' THEN jl.debit_minor - jl.credit_minor
            ELSE jl.credit_minor - jl.debit_minor
        END
    ), 0)
      INTO balance_minor
      FROM journal_lines jl
      JOIN journal_entries je ON je.id = jl.journal_entry_id
      JOIN ledger_accounts la ON la.id = jl.ledger_account_id
      WHERE je.status = 'posted'
        AND jl.customer_account_id = target_account_id
        AND jl.currency = target_currency;

    IF allows_overdraft IS NOT TRUE AND balance_minor < 0 THEN
        RAISE EXCEPTION 'Customer account % would be overdrawn: balance=%', target_account_id, balance_minor
          USING ERRCODE = '23514';
    END IF;

    IF allows_overdraft IS TRUE AND balance_minor < (0 - overdraft_limit) THEN
        RAISE EXCEPTION 'Customer account % overdraft limit exceeded: balance=% limit=%', target_account_id, balance_minor, overdraft_limit
          USING ERRCODE = '23514';
    END IF;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION customer_account_non_overdraft_line_trigger_fn() RETURNS TRIGGER AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM enforce_customer_account_non_overdraft(OLD.customer_account_id, OLD.currency);
        RETURN OLD;
    END IF;

    PERFORM enforce_customer_account_non_overdraft(NEW.customer_account_id, NEW.currency);
    IF TG_OP = 'UPDATE' AND (OLD.customer_account_id IS DISTINCT FROM NEW.customer_account_id OR OLD.currency IS DISTINCT FROM NEW.currency) THEN
        PERFORM enforce_customer_account_non_overdraft(OLD.customer_account_id, OLD.currency);
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION customer_account_non_overdraft_entry_trigger_fn() RETURNS TRIGGER AS $$
DECLARE
    line RECORD;
BEGIN
    IF NEW.status = 'posted' THEN
        FOR line IN
            SELECT DISTINCT customer_account_id, currency
            FROM journal_lines
            WHERE journal_entry_id = NEW.id
              AND customer_account_id IS NOT NULL
        LOOP
            PERFORM enforce_customer_account_non_overdraft(line.customer_account_id, line.currency);
        END LOOP;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);

        DB::statement('DROP TRIGGER IF EXISTS customer_account_non_overdraft_after_line_change ON journal_lines');
        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER customer_account_non_overdraft_after_line_change
AFTER INSERT OR UPDATE OR DELETE ON journal_lines
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION customer_account_non_overdraft_line_trigger_fn();
SQL);

        DB::statement('DROP TRIGGER IF EXISTS customer_account_non_overdraft_after_entry_status ON journal_entries');
        DB::statement(<<<'SQL'
CREATE CONSTRAINT TRIGGER customer_account_non_overdraft_after_entry_status
AFTER UPDATE OF status ON journal_entries
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION customer_account_non_overdraft_entry_trigger_fn();
SQL);

        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_operational_agency_required CHECK (agency_id IS NOT NULL OR source_module = 'institution')");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_operational_agency_required');
        DB::statement('DROP TRIGGER IF EXISTS customer_account_non_overdraft_after_entry_status ON journal_entries');
        DB::statement('DROP TRIGGER IF EXISTS customer_account_non_overdraft_after_line_change ON journal_lines');
        DB::statement('DROP FUNCTION IF EXISTS customer_account_non_overdraft_entry_trigger_fn()');
        DB::statement('DROP FUNCTION IF EXISTS customer_account_non_overdraft_line_trigger_fn()');
        DB::statement('DROP FUNCTION IF EXISTS enforce_customer_account_non_overdraft(BIGINT, TEXT)');

        Schema::table('account_holds', function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'source_public_id', 'expires_at', 'release_reason']);
        });

        Schema::table('account_products', function (Blueprint $table): void {
            $table->dropColumn(['allows_overdraft', 'overdraft_limit_minor']);
        });
    }
};
