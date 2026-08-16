<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\OperationCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The four loan posting operations the application resolves *by name* — see
 * OperationAccountMappingController::READINESS_OPERATIONS, which names each one
 * as a string constant and reports it as a disbursement blocker when missing.
 *
 * They were never seeded, so a fresh institution had an empty operation-code
 * catalogue and no way to disburse a loan: the mapping form had nothing to pick,
 * and the codes had to be typed by hand with spelling that matched the source
 * exactly or the mapping silently resolved to nothing.
 *
 * The mappings themselves stay manual — which ledger account an institution
 * debits is its own chart decision, and guessing one would be worse than asking.
 * Only the codes are fixed by the application.
 */
final class LoanOperationCodeSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, label: string, direction: string}>
     */
    private const array CODES = [
        ['code' => 'loan_principal_disbursement', 'label' => 'Décaissement du principal', 'direction' => 'debit'],
        ['code' => 'loan_setup_dossier_fee', 'label' => 'Frais de dossier', 'direction' => 'credit'],
        ['code' => 'loan_setup_tax', 'label' => 'Taxe sur frais de dossier', 'direction' => 'credit'],
        ['code' => 'loan_setup_guarantee_deposit', 'label' => 'Dépôt de garantie', 'direction' => 'credit'],
    ];

    public function run(): void
    {
        foreach (self::CODES as $definition) {
            // Keyed on the code, so re-running leaves an institution's own label
            // and status alone rather than resetting them.
            DB::table('operation_codes')->updateOrInsert(
                ['code' => $definition['code']],
                [
                    'public_id' => (string) Str::ulid(),
                    'label' => $definition['label'],
                    'module' => 'loan',
                    'direction' => $definition['direction'],
                    'status' => OperationCode::STATUS_ACTIVE,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}
