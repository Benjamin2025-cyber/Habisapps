<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Support\Accounting\AgencyLedgerMappingResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opens the divisionary GL accounts of a loan at mise en place.
 *
 * « Il n'y a pas de compte comptable par défaut car chaque ligne de crédit
 * entraîne automatiquement la création de plusieurs comptes lors de la mise en
 * place. » Nothing is picked on a form: each dossier gets its own balance-sheet
 * accounts, parented under the control accounts the institution already mapped
 * for the operation in question:
 *
 *   - principal receivable   under the loan_principal_disbursement debit leg
 *                            (class 32 client credits, e.g. 3261);
 *   - guarantee deposit held under the loan_setup_guarantee_deposit credit leg
 *                            (e.g. 3742 dépôts de garantie clients).
 *
 * Both are balance-sheet positions the institution genuinely carries per
 * dossier. Income and VAT legs — interest, fees, penalties, VAT — stay on their
 * shared mapped accounts: PCEMF class 7 is not subdivided per borrower, and
 * minting one revenue account per loan would leave an EMF with thousands of
 * them and an unreadable income statement. Journal lines carry loan_id, which is
 * what per-dossier income analysis actually reads.
 *
 * Penalties in particular have no receivable to open: they are recognised in the
 * ledger only when collected (see docs/domain/loan-lifecycle.md), so between
 * assessment and collection there is nothing to carry.
 *
 * Divisionaries consolidate into their parents through the ordinary account
 * hierarchy, so portfolio balances by control account keep working unchanged.
 *
 * The action is idempotent and safe to call repeatedly (setup assessment,
 * disbursement): a column already set is never re-derived, and callers hold the
 * loan row lock so concurrent runs serialize.
 */
final class OpenLoanAccounts
{
    /**
     * Divisionary column → [operation code, mapping leg] whose resolved account
     * parents the divisionary.
     */
    private const array DIVISIONARIES = [
        'loan_receivable_account_id' => ['loan_principal_disbursement', AgencyLedgerMappingResolver::LEG_DEBIT],
        'guarantee_held_account_id' => ['loan_setup_guarantee_deposit', AgencyLedgerMappingResolver::LEG_CREDIT],
    ];

    private const array LABELS = [
        'loan_receivable_account_id' => 'Crédit client ',
        'guarantee_held_account_id' => 'Dépôt de garantie crédit ',
    ];

    public function __construct(
        private readonly AgencyLedgerMappingResolver $mappingResolver,
    ) {}

    public function ensure(Loan $loan): Loan
    {
        $pending = array_filter(
            array_keys(self::DIVISIONARIES),
            fn (string $column): bool => ! is_int($loan->getAttribute($column)),
        );
        if ($pending === []) {
            return $loan;
        }

        $currency = $loan->currency !== '' ? $loan->currency : 'XAF';
        $changed = false;

        foreach ($pending as $column) {
            /** @var string $operationCode */
            $operationCode = self::DIVISIONARIES[$column][0];
            /** @var string $leg */
            $leg = self::DIVISIONARIES[$column][1];

            $resolution = $this->mappingResolver->resolve($operationCode, 'loan', $loan->agency_id, $currency, $leg);
            $status = $resolution['status'];
            if ($status !== AgencyLedgerMappingResolver::READY && $status !== AgencyLedgerMappingResolver::OVERLAPPING) {
                // No usable parent yet. Postings fail closed on the same mapping,
                // so nothing can be misfiled in the meantime; configuring the
                // mapping later lets the next ensure() call open the account.
                continue;
            }

            $ledgerId = $leg === AgencyLedgerMappingResolver::LEG_DEBIT
                ? $resolution['debit_ledger_account_id']
                : $resolution['credit_ledger_account_id'];
            if (! is_int($ledgerId)) {
                continue;
            }

            $parent = LedgerAccount::query()->whereKey($ledgerId)->first();
            if (! $parent instanceof LedgerAccount
                || $parent->status !== LedgerAccount::STATUS_ACTIVE
                || $parent->agency_id !== $loan->agency_id) {
                continue;
            }

            $loan->setAttribute($column, $this->openDivisionary($loan, $parent, self::LABELS[$column]));
            $changed = true;
        }

        if ($changed) {
            $loan->save();
        }

        return $loan;
    }

    /**
     * The divisionary code is `<parent code>.<loan number>` — unique per agency
     * like every ledger code, readable as "child of this control account", and
     * stable across retries because it derives from immutable identifiers.
     */
    private function openDivisionary(Loan $loan, LedgerAccount $parent, string $labelPrefix): int
    {
        $code = $this->divisionaryCode($parent, $loan->loan_number);

        // Adopt first: a repeat ensure() call whose earlier loan update was
        // lost (or a racing twin) finds the account here instead of colliding
        // on insert.
        $existing = LedgerAccount::query()
            ->where('agency_id', $loan->agency_id)
            ->where('code', $code)
            ->first();
        if ($existing instanceof LedgerAccount) {
            return $existing->id;
        }

        try {
            $divisionary = LedgerAccount::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $loan->agency_id,
                'code' => $code,
                'name' => $labelPrefix.$loan->loan_number,
                'account_class' => $parent->account_class,
                'account_type' => $parent->account_type,
                'is_postable' => true,
                'parent_account_id' => $parent->id,
                'normal_balance_side' => $parent->normal_balance_side,
                'status' => LedgerAccount::STATUS_ACTIVE,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // Postgres has aborted the surrounding statement, so the adoption
            // lookup runs inside a nested transaction — Laravel issues a
            // savepoint and rolls back to it, leaving the outer transaction
            // usable. Same caller re-racing itself is prevented by the loan
            // row lock; reaching this point means the account was created
            // between the pre-select and the insert.
            $adopted = DB::transaction(function () use ($loan, $code): ?LedgerAccount {
                return LedgerAccount::query()
                    ->where('agency_id', $loan->agency_id)
                    ->where('code', $code)
                    ->first();
            });
            if (! $adopted instanceof LedgerAccount) {
                throw $exception;
            }

            return $adopted->id;
        }

        return $divisionary->id;
    }

    /**
     * Chart codes that fill most of their column leave no room for a readable
     * dossier suffix, so past the column limit the code falls back to a stable
     * digest of (parent code, loan number) — uniqueness stops depending on how
     * much room the parent code happened to leave.
     */
    private function divisionaryCode(LedgerAccount $parent, string $loanNumber): string
    {
        $code = $parent->code.'.'.$loanNumber;
        if (strlen($code) <= 64) {
            return $code;
        }

        return 'D'.substr(sha1($parent->code.'|'.$loanNumber), 0, 20);
    }
}
