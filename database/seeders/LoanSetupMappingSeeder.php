<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Agency;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two ledger mappings CORRECTIONS HABISLOAN 04/09/2026 specifies for a
 * mise en place:
 *
 *   - « Fonds de solidarité décès » → 4611, « le compte 4611 (à créer : Fonds
 *     de Solidarité Décès sur prêts à court terme) ».
 *   - « Le compte de dépôt de garantie — 3742...001 ». The installed mapping
 *     pointed at 18700 « Dépôts de garantie reçus hors réseau », which sits
 *     under 187 « Dépôts et cautionnements **non commerciaux** reçus » in
 *     class 18 AUTRES RESSOURCES PERMANENTES. A guarantee taken from a
 *     borrowing client is a commercial deposit and belongs in class 3 with the
 *     rest of the client's positions — 3742 « Dépôts de garantie clients ».
 *     Left on 18700 it reports client collateral as a permanent resource of
 *     the institution, which misstates the balance-sheet section and the
 *     maturity profile the prudential ratios read. OpenLoanAccounts was
 *     written expecting 3742; its own docblock says so.
 *
 * LoanOperationCodeSeeder deliberately leaves mappings manual, on the grounds
 * that which account an institution posts to is its own chart decision and
 * guessing one would be worse than asking. That reasoning does not apply here:
 * the accounting team named the account in writing. Seeding it is recording
 * their answer, not guessing at it.
 *
 * Insert-if-absent, never an update: an institution that has already mapped
 * this operation somewhere else has decided something, and a seeder that runs
 * on every deploy must not overrule it.
 *
 * Without this the operation appears on the disbursement-readiness screen as an
 * unmapped blocker — correct, but it would stop every disbursement until
 * someone mapped by hand an account we were already told the number of.
 */
final class LoanSetupMappingSeeder extends Seeder
{
    private const string OPERATION_CODE = 'loan_setup_death_fund';

    private const string LEDGER_CODE = '4611';

    private const string GUARANTEE_OPERATION_CODE = 'loan_setup_guarantee_deposit';

    private const string GUARANTEE_LEDGER_CODE = '3742';

    /** The installed default this seeder repairs, and nothing else. */
    private const string GUARANTEE_WRONG_LEDGER_CODE = '18700';

    public function run(): void
    {
        $this->mapDeathFund();
        $this->repointGuaranteeDeposit();
    }

    private function mapDeathFund(): void
    {
        $operationCodeId = DB::table('operation_codes')->where('code', self::OPERATION_CODE)->value('id');
        if (! is_int($operationCodeId)) {
            $this->command->warn('Operation code '.self::OPERATION_CODE.' is missing: run LoanOperationCodeSeeder first.');

            return;
        }

        $created = 0;
        foreach (Agency::query()->get(['id']) as $agency) {
            $ledgerAccountId = DB::table('ledger_accounts')
                ->where('agency_id', $agency->id)
                ->where('code', self::LEDGER_CODE)
                ->value('id');

            if (! is_int($ledgerAccountId)) {
                $this->command->warn(sprintf(
                    'Agency %d has no %s account: run LoanSetupLedgerAccountSeeder first.',
                    $agency->id,
                    self::LEDGER_CODE,
                ));

                continue;
            }

            $exists = DB::table('operation_account_mappings')
                ->where('operation_code_id', $operationCodeId)
                ->where('agency_id', $agency->id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('operation_account_mappings')->insert([
                'public_id' => (string) Str::ulid(),
                'operation_code_id' => $operationCodeId,
                'agency_id' => $agency->id,
                'credit_ledger_account_id' => $ledgerAccountId,
                'currency' => 'XAF',
                'status' => 'active',
                // Approved on seed: an unapproved mapping reads as a blocker on
                // the readiness screen, which is the state this seeder exists to
                // clear. The account it points at is the one the accounting team
                // specified, so there is nothing left for them to approve.
                'approval_status' => 'approved',
                'sharia_approval_required' => false,
                'sharia_approval_status' => 'not_required',
                'effective_from' => now()->toDateString(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        $this->command->info(sprintf('Death-fund mapping: %d agenc%s pointed at %s.', $created, $created === 1 ? 'y' : 'ies', self::LEDGER_CODE));
    }

    /**
     * Repoints the guarantee deposit at 3742 — but only where it still carries
     * the wrong default. An institution that deliberately mapped it somewhere
     * else has made a decision, and a seeder that runs on every deploy must
     * not overrule it; only the known-incorrect 18700 is repaired.
     */
    private function repointGuaranteeDeposit(): void
    {
        $operationCodeId = DB::table('operation_codes')->where('code', self::GUARANTEE_OPERATION_CODE)->value('id');
        if (! is_int($operationCodeId)) {
            return;
        }

        $repaired = 0;
        foreach (Agency::query()->get(['id']) as $agency) {
            $correct = DB::table('ledger_accounts')
                ->where('agency_id', $agency->id)
                ->where('code', self::GUARANTEE_LEDGER_CODE)
                ->value('id');

            $wrong = DB::table('ledger_accounts')
                ->where('agency_id', $agency->id)
                ->where('code', self::GUARANTEE_WRONG_LEDGER_CODE)
                ->value('id');

            if (! is_int($correct) || ! is_int($wrong)) {
                continue;
            }

            $repaired += DB::table('operation_account_mappings')
                ->where('operation_code_id', $operationCodeId)
                ->where('agency_id', $agency->id)
                ->where('credit_ledger_account_id', $wrong)
                ->update(['credit_ledger_account_id' => $correct, 'updated_at' => now()]);
        }

        if ($repaired > 0) {
            $this->command->info(sprintf(
                'Guarantee deposit: %d mapping%s moved from %s to %s.',
                $repaired,
                $repaired === 1 ? '' : 's',
                self::GUARANTEE_WRONG_LEDGER_CODE,
                self::GUARANTEE_LEDGER_CODE,
            ));
        }
    }
}
