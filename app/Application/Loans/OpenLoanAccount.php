<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Application\Accounts\OpenCustomerLedgerAccount;
use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Support\Accounts\CustomerAccountNumberGenerator;
use App\Support\Loans\LoanTermLedgerResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * « Le compte d'amortissement du prêt, des impayés, de virement et de
 * recouvrement, est le même pour un crédit donné, il est créé automatiquement
 * lors de la mise en place » — CORRECTIONS HABISLOAN 04/09/2026.
 *
 * So: one customer account per loan, opened by the system, filling all four
 * links. They were four separate pickers before, which is four chances to
 * choose four different accounts for a thing the accounting team treats as
 * one.
 *
 * The account's control is chosen from the loan's term — 3282, 3181 or 3081 —
 * and its number follows the ten-digit nomenclature, so
 * `3282001001` reads as "first court-terme credit account of agency 001"
 * straight off the statement.
 */
final class OpenLoanAccount
{
    public function __construct(
        private readonly LoanTermLedgerResolver $termLedger,
        private readonly CustomerAccountNumberGenerator $accountNumbers,
        private readonly OpenCustomerLedgerAccount $openCustomerLedgerAccount,
    ) {}

    /**
     * Opens the loan's account and links it, unless the caller already chose
     * the accounts by hand — an existing file being corrected keeps whatever
     * it was reconciled against.
     *
     * Returns null when nothing was opened, either because the loan is already
     * linked or because the agency's chart has no account for the term. A
     * missing control account is not fatal here: the loan is still a valid
     * application, and `LoanSetupLedgerAccountSeeder` backfills the chart.
     */
    public function openFor(Loan $loan, LoanProduct $product, Client $client): ?CustomerAccount
    {
        if ($this->alreadyLinked($loan)) {
            return null;
        }

        $installments = $loan->number_of_installments;
        if (! is_int($installments) || $installments < 1) {
            return null;
        }

        $agencyId = $loan->agency_id;

        $base = CarbonImmutable::parse($loan->applied_on ?? now()->toDateString())->startOfDay();
        $control = $this->termLedger->resolve($product, $installments, $base, $agencyId);
        if (! $control instanceof LedgerAccount) {
            return null;
        }

        $accountNumber = $this->accountNumbers->generate($control->code, $loan->agency?->code);

        // The client's own divisionary under the control, exactly as a counter
        // account is opened, so postings, balances and reconciliation read this
        // account no differently from any other.
        $ledgerAccount = $this->openCustomerLedgerAccount->open($client, $control, $agencyId, $accountNumber);

        $account = CustomerAccount::query()->create([
            'public_id' => (string) Str::ulid(),
            'client_id' => $client->id,
            'agency_id' => $agencyId,
            'ledger_account_id' => $ledgerAccount->id,
            'account_number' => $accountNumber,
            'account_title' => $this->title($loan, $product),
            'account_type' => CustomerAccount::TYPE_LOAN,
            'currency' => $loan->currency,
            // A loan account is not opened over a counter, so it carries no
            // « frais d'ouverture »: the fees of a mise en place are the dossier
            // fee and the guarantee deposit, which have their own accounts.
            'opening_fee_due_minor' => 0,
            'opened_on' => $base->toDateString(),
            'status' => CustomerAccount::STATUS_ACTIVE,
        ]);

        $loan->forceFill([
            'amortization_account_id' => $account->id,
            'unpaid_account_id' => $account->id,
            'recovery_account_id' => $account->id,
            'transfer_account_id' => $account->id,
        ]);

        return $account;
    }

    /**
     * Any one of the four already set means the file was linked deliberately.
     */
    private function alreadyLinked(Loan $loan): bool
    {
        return $loan->amortization_account_id !== null
            || $loan->unpaid_account_id !== null
            || $loan->recovery_account_id !== null
            || $loan->transfer_account_id !== null;
    }

    private function title(Loan $loan, LoanProduct $product): string
    {
        return trim(sprintf('%s — %s', $product->name, $loan->loan_number), ' —');
    }
}
