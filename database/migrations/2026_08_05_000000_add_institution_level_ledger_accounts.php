<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated chart of accounts (PCEMF): the institution owns grouping
 * accounts and each agency owns the detail accounts posted to underneath them.
 *
 * Institution-level accounts carry no agency. The composite foreign key
 * journal_lines (ledger_account_id, agency_id) -> ledger_accounts (id, agency_id)
 * therefore already makes them impossible to post to, since journal_lines.agency_id
 * is NOT NULL and can never match a NULL agency: grouping accounts aggregate,
 * they never receive entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ledger_accounts ALTER COLUMN agency_id DROP NOT NULL');

        // UNIQUE (agency_id, code) leaves institution codes unguarded because
        // Postgres treats NULL agency_id values as distinct, which would allow
        // two institution-level 571000 rows. Split it into partial indexes:
        // codes unique within an agency, and unique across the institution.
        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_agency_code_unique');
        DB::statement('CREATE UNIQUE INDEX uniq_agency_ledger_account_code ON ledger_accounts (agency_id, code) WHERE agency_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX uniq_institution_ledger_account_code ON ledger_accounts (code) WHERE agency_id IS NULL');

        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->boolean('is_postable')->default(true)->after('account_type');
        });

        // Grouping accounts hold a consolidated balance, never movements. The
        // flag also covers agency-level grouping accounts, which the composite
        // foreign key cannot protect on its own.
        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_institution_not_postable CHECK (agency_id IS NOT NULL OR is_postable = false)');
    }

    public function down(): void
    {
        $institutionAccounts = DB::table('ledger_accounts')->whereNull('agency_id')->count();
        if ($institutionAccounts > 0) {
            throw new RuntimeException(
                'Cannot restore the agency-only chart of accounts: '.$institutionAccounts.' institution-level ledger account(s) exist. '
                .'Re-assign or archive them before rolling this migration back.'
            );
        }

        DB::statement('ALTER TABLE ledger_accounts DROP CONSTRAINT IF EXISTS ledger_accounts_institution_not_postable');

        Schema::table('ledger_accounts', function (Blueprint $table): void {
            $table->dropColumn('is_postable');
        });

        DB::statement('DROP INDEX IF EXISTS uniq_institution_ledger_account_code');
        DB::statement('DROP INDEX IF EXISTS uniq_agency_ledger_account_code');
        DB::statement('ALTER TABLE ledger_accounts ADD CONSTRAINT ledger_accounts_agency_code_unique UNIQUE (agency_id, code)');
        DB::statement('ALTER TABLE ledger_accounts ALTER COLUMN agency_id SET NOT NULL');
    }
};
