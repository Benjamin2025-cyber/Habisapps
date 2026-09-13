<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A reversal is a posted compensation entry while the source journal
        // is marked `reversed`.  Both entries remain part of the customer
        // balance; excluding the source at this point made the deferred
        // non-overdraft trigger see only the compensation debit and reject a
        // perfectly balanced reversal.
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
      WHERE je.status IN ('posted', 'reversed')
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
    }

    public function down(): void
    {
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
    }
};
