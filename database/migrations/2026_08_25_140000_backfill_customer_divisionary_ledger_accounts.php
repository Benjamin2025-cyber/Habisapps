<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Points existing customer accounts at a divisionary coded with the client's
 * number, the way newly opened ones already are.
 *
 * « Nous sollicitions que le numéro du client soit directement son compte
 * comptable. » Opening the divisionary only on `store()` would make that true
 * for accounts opened from now on and false for every account already on the
 * books — the accountant would still have to look up which kind they are
 * holding, which is the exact chore the rule exists to remove.
 *
 * Safe to re-point: a customer account's balance is summed over
 * `journal_lines.customer_account_id`, not over its ledger account, so history
 * stays attached to the account. Lines already posted keep referencing the
 * control, which is where they belong — the control is the parent, so the
 * subtree still totals correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customer_accounts')
            ->join('clients', 'clients.id', '=', 'customer_accounts.client_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'customer_accounts.ledger_account_id')
            ->whereNotNull('customer_accounts.ledger_account_id')
            ->orderBy('customer_accounts.id')
            ->select([
                'customer_accounts.id',
                'customer_accounts.agency_id',
                'customer_accounts.account_number',
                'customer_accounts.ledger_account_id AS control_id',
                'clients.client_reference',
                'clients.first_name',
                'clients.last_name',
                'ledger_accounts.code AS control_code',
            ])
            ->chunkById(100, function (iterable $rows): void {
                foreach ($rows as $row) {
                    $this->repoint($row);
                }
            }, 'customer_accounts.id', 'id');
    }

    /**
     * Irreversible by design: the divisionaries are ordinary chart rows that may
     * have been posted to by the time this is rolled back, and the original
     * control is still their parent, so there is nothing to restore.
     */
    public function down(): void {}

    private function repoint(object $row): void
    {
        $reference = is_string($row->client_reference) ? $row->client_reference : '';
        if ($reference === '') {
            return;
        }

        // Already a divisionary of this client — opened by the workflow.
        $controlCode = is_string($row->control_code) ? $row->control_code : '';
        if ($controlCode === $reference || str_starts_with($controlCode, $reference.'.')) {
            return;
        }

        $controlId = (int) $row->control_id;
        $agencyId = (int) $row->agency_id;

        $code = $reference;
        $taken = DB::table('ledger_accounts')->where('agency_id', $agencyId)->where('code', $code)->first(['id', 'parent_account_id']);
        if ($taken !== null && (int) $taken->parent_account_id === $controlId) {
            DB::table('customer_accounts')->where('id', $row->id)->update(['ledger_account_id' => (int) $taken->id]);

            return;
        }

        if ($taken !== null) {
            // The bare number is held under another control for this client;
            // this account embeds its own number, as the workflow does.
            $code = $reference.'.'.$row->account_number;
            $suffixed = DB::table('ledger_accounts')->where('agency_id', $agencyId)->where('code', $code)->first(['id']);
            if ($suffixed !== null) {
                DB::table('customer_accounts')->where('id', $row->id)->update(['ledger_account_id' => (int) $suffixed->id]);

                return;
            }
        }

        $control = DB::table('ledger_accounts')->where('id', $controlId)
            ->first(['account_class', 'account_type', 'normal_balance_side']);
        if ($control === null) {
            return;
        }

        $divisionaryId = DB::table('ledger_accounts')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $agencyId,
            'code' => $code,
            'name' => $this->accountName($row),
            'account_class' => $control->account_class,
            'account_type' => $control->account_type,
            'is_postable' => true,
            'parent_account_id' => $controlId,
            'normal_balance_side' => $control->normal_balance_side,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customer_accounts')->where('id', $row->id)->update(['ledger_account_id' => $divisionaryId]);
    }

    private function accountName(object $row): string
    {
        $name = 'Client '.$row->client_reference;
        $last = trim(is_string($row->last_name) ? $row->last_name : '');
        $first = trim(is_string($row->first_name) ? $row->first_name : '');
        if ($last !== '' || $first !== '') {
            $name .= ' – '.strtoupper($last).($first !== '' ? ' '.ucfirst($first) : '');
        }

        return $name;
    }
};
