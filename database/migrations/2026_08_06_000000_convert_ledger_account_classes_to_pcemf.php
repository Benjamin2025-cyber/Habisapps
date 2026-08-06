<?php

declare(strict_types=1);

use App\Models\LedgerAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Move ledger_accounts.account_class from the IFRS-style nature
 * (asset/liability/equity/revenue/expense) to the eight PCEMF classes, the
 * chart a Cameroonian EMF is required to keep.
 *
 * The class is the leading digit of the account code, so the code is the
 * authority for the backfill: 571001 is class 5 whatever it was previously
 * labelled. Codes that do not start with 1–8 keep a nature-based guess, which
 * only affects test fixtures and hand-made accounts — the real chart follows
 * the numbering.
 *
 * No constraint changes: account_class is a plain varchar(32) validated in the
 * request layer, so this migration only rewrites data.
 */
return new class extends Migration
{
    /**
     * Leading code digit → PCEMF class.
     */
    private const array CLASS_BY_CODE_DIGIT = [
        '1' => LedgerAccount::ACCOUNT_CLASS_CAPITAUX_PERMANENTS,
        '2' => LedgerAccount::ACCOUNT_CLASS_VALEURS_IMMOBILISEES,
        '3' => LedgerAccount::ACCOUNT_CLASS_OPERATIONS_CLIENTELE,
        '4' => LedgerAccount::ACCOUNT_CLASS_TIERS,
        '5' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
        '6' => LedgerAccount::ACCOUNT_CLASS_CHARGES,
        '7' => LedgerAccount::ACCOUNT_CLASS_PRODUITS,
        '8' => LedgerAccount::ACCOUNT_CLASS_HORS_BILAN,
    ];

    /**
     * Fallback for codes outside the PCEMF numbering. A former "asset" becomes
     * treasury because that is what the accounts carrying that label are in
     * practice — tills and cash — and it is the class the till check requires.
     */
    private const array CLASS_BY_FORMER_NATURE = [
        'asset' => LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE,
        'liability' => LedgerAccount::ACCOUNT_CLASS_OPERATIONS_CLIENTELE,
        'equity' => LedgerAccount::ACCOUNT_CLASS_CAPITAUX_PERMANENTS,
        'revenue' => LedgerAccount::ACCOUNT_CLASS_PRODUITS,
        'expense' => LedgerAccount::ACCOUNT_CLASS_CHARGES,
    ];

    public function up(): void
    {
        $formerValues = array_keys(self::CLASS_BY_FORMER_NATURE);

        foreach (self::CLASS_BY_CODE_DIGIT as $digit => $pcemfClass) {
            DB::table('ledger_accounts')
                ->whereIn('account_class', $formerValues)
                ->where('code', 'like', $digit.'%')
                ->update(['account_class' => $pcemfClass]);
        }

        foreach (self::CLASS_BY_FORMER_NATURE as $formerNature => $pcemfClass) {
            DB::table('ledger_accounts')
                ->where('account_class', $formerNature)
                ->update(['account_class' => $pcemfClass]);
        }
    }

    public function down(): void
    {
        // The mapping is one-way: several PCEMF classes collapse onto the same
        // former nature, and class 8 (hors bilan) had no equivalent at all.
        // Reversing therefore restores a usable value, not the original one.
        $reverse = [
            LedgerAccount::ACCOUNT_CLASS_CAPITAUX_PERMANENTS => 'equity',
            LedgerAccount::ACCOUNT_CLASS_VALEURS_IMMOBILISEES => 'asset',
            LedgerAccount::ACCOUNT_CLASS_OPERATIONS_CLIENTELE => 'liability',
            LedgerAccount::ACCOUNT_CLASS_TIERS => 'liability',
            LedgerAccount::ACCOUNT_CLASS_TRESORERIE_INTERBANCAIRE => 'asset',
            LedgerAccount::ACCOUNT_CLASS_CHARGES => 'expense',
            LedgerAccount::ACCOUNT_CLASS_PRODUITS => 'revenue',
            LedgerAccount::ACCOUNT_CLASS_HORS_BILAN => 'asset',
        ];

        foreach ($reverse as $pcemfClass => $formerNature) {
            DB::table('ledger_accounts')
                ->where('account_class', $pcemfClass)
                ->update(['account_class' => $formerNature]);
        }
    }
};
