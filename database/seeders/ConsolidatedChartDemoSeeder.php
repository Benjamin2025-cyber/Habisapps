<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AccountingDay;
use App\Models\Agency;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Builds the finished scenario of the consolidated chart-of-accounts guide: the
 * 571xxx tree, plus 100 posted in one agency and 40 in the other.
 *
 * Run explicitly with:
 *   php artisan db:seed --class=ConsolidatedChartDemoSeeder
 *
 * Purpose: the guide's §3 and §4 are roughly twenty minutes of form filling
 * before the first interesting assertion, and §1.5 requires a fresh database for
 * every run. That makes re-verification expensive. This seeder produces the same
 * end state in seconds so a tester can go straight to §5 (the numbers) and §6
 * (the refusals) — the parts where a regression actually shows.
 *
 * Follow §3–§4 by hand the first time, or whenever the account and entry forms
 * are themselves what changed. Nothing here exercises the UI.
 *
 * Entries are written directly rather than through the API, so the maker-checker
 * rule is not involved; the maker and reviewer columns are still filled with two
 * different users so the data looks like what the workflow would have produced.
 *
 * Test data, never called from DatabaseSeeder.
 */
final class ConsolidatedChartDemoSeeder extends Seeder
{
    private const string INSTITUTION_CODE = '571000';

    /** Debit amounts per agency, in minor units. 100,00 and 40,00 → 140,00 consolidated. */
    private const int PRIMARY_AMOUNT_MINOR = 10000;

    private const int SECOND_AMOUNT_MINOR = 4000;

    private const string CURRENCY = 'XAF';

    public function run(): void
    {
        if (app()->environment('production') && ! (bool) env('ALLOW_TEST_STAFF_SEEDING', false)) {
            throw new LogicException(
                'Consolidated-chart demo seeding is disabled in production. Set ALLOW_TEST_STAFF_SEEDING=true only on an intentionally isolated test installation.'
            );
        }

        $this->call(ConsolidatedChartBenchSeeder::class);

        DB::transaction(function (): void {
            $primary = Agency::query()->where('code', ConsolidatedChartBenchSeeder::PRIMARY_AGENCY_CODE)->firstOrFail();
            $second = Agency::query()->where('code', ConsolidatedChartBenchSeeder::SECOND_AGENCY_CODE)->firstOrFail();

            $maker = User::query()->where('email', ConsolidatedChartBenchSeeder::HEAD_OFFICE_EMAIL)->firstOrFail();
            $reviewer = User::query()->where('email', 'test.user.admin@example.test')->firstOrFail();

            // 571000 groups the two agency tills. Institution scope means no
            // agency and, by the ledger_accounts check constraint, never postable.
            $institution = $this->account(self::INSTITUTION_CODE, 'Caisse Globale', null, [
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
                'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
                'is_postable' => false,
            ]);

            $primaryCash = $this->account('571001', 'Caisse HABIS Test', $primary, [
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
                'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
                'parent_account_id' => $institution->id,
            ]);
            $secondCash = $this->account('571002', 'Caisse Cookbook', $second, [
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
                'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_DEBIT,
                'parent_account_id' => $institution->id,
            ]);

            // Counterparts: every entry needs two sides. No parent, so they stay
            // outside the consolidated subtree and cannot inflate its total.
            $primaryCounterpart = $this->account('571901', 'Contrepartie HABIS', $primary, [
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TIERS,
                'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_CREDIT,
            ]);
            $secondCounterpart = $this->account('571902', 'Contrepartie Cookbook', $second, [
                'account_class' => LedgerAccount::ACCOUNT_CLASS_TIERS,
                'normal_balance_side' => LedgerAccount::NORMAL_BALANCE_CREDIT,
            ]);

            $this->postedEntry('TEST-CONSO-A', $primary, $maker, $reviewer, $primaryCash, $primaryCounterpart, self::PRIMARY_AMOUNT_MINOR);
            $this->postedEntry('TEST-CONSO-B', $second, $maker, $reviewer, $secondCash, $secondCounterpart, self::SECOND_AMOUNT_MINOR);

            $this->report($institution);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function account(string $code, string $name, ?Agency $agency, array $attributes): LedgerAccount
    {
        $existing = LedgerAccount::query()
            ->where('code', $code)
            ->where('agency_id', $agency?->id)
            ->first();

        if ($existing instanceof LedgerAccount) {
            return $existing;
        }

        return LedgerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agency?->id,
            'code' => $code,
            'name' => $name,
            'status' => LedgerAccount::STATUS_ACTIVE,
            'is_postable' => true,
            ...$attributes,
        ]);
    }

    private function postedEntry(
        string $reference,
        Agency $agency,
        User $maker,
        User $reviewer,
        LedgerAccount $debit,
        LedgerAccount $credit,
        int $amountMinor,
    ): void {
        if (JournalEntry::query()->where('reference', $reference)->exists()) {
            return;
        }

        $day = AccountingDay::query()
            ->where('scope_type', AccountingDay::SCOPE_AGENCY)
            ->where('agency_id', $agency->id)
            ->where('status', AccountingDay::STATUS_OPEN)
            ->firstOrFail();

        // The database enforces the lifecycle with triggers, so this walks the
        // real path rather than inserting a posted row: lines are immutable once
        // an entry is posted (enforce_journal_line_immutability), and the status
        // may only move draft → submitted → approved → posted
        // (enforce_journal_entry_status_transitions).
        $entry = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => $reference,
            'business_date' => $day->business_date->toDateString(),
            'accounting_day_id' => $day->id,
            'agency_id' => $agency->id,
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => 'Consolidated chart demo — '.$agency->code,
            'created_by_user_id' => $maker->id,
        ]);

        foreach ([[$debit, $amountMinor, 0], [$credit, 0, $amountMinor]] as [$account, $debitMinor, $creditMinor]) {
            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'journal_entry_id' => $entry->id,
                'agency_id' => $agency->id,
                'ledger_account_id' => $account->id,
                'debit_minor' => $debitMinor,
                'credit_minor' => $creditMinor,
                'currency' => self::CURRENCY,
            ]);
        }

        $entry->update([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_by_user_id' => $maker->id,
            'submitted_at' => now(),
        ]);

        // reviewed_at and reviewed_by are required by
        // journal_entries_review_metadata_consistent at the approved step.
        $entry->update([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_by_user_id' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $entry->update([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_by_user_id' => $reviewer->id,
            'posted_at' => now(),
        ]);
    }

    private function report(LedgerAccount $institution): void
    {
        $expected = (self::PRIMARY_AMOUNT_MINOR + self::SECOND_AMOUNT_MINOR) / 100;

        $this->command->info('Consolidated chart demo ready.');
        $this->command->line('571000 Caisse Globale (institution, grouping)');
        $this->command->line('  ├── 571001 Caisse HABIS Test  — debit '.(self::PRIMARY_AMOUNT_MINOR / 100));
        $this->command->line('  └── 571002 Caisse Cookbook    — debit '.(self::SECOND_AMOUNT_MINOR / 100));
        $this->command->line(sprintf('Expected consolidated balance of 571000: %s %s', $expected, self::CURRENCY));
        $this->command->line('Account public id: '.$institution->public_id);
        $this->command->line('Next: guide §5 (the numbers) and §6 (the refusals). §3–§4 are already done.');
    }
}
