<?php

declare(strict_types=1);

namespace App\Application\Insurance;

use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Support\AccountingDay\AccountingDayGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class InsuranceAccountingService
{
    public function __construct(
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    public function productForPremiumCollection(object $subscription): object
    {
        $product = DB::table('insurance_products')
            ->where('id', $this->rowInt($subscription, 'insurance_product_id'))
            ->first();
        if (! is_object($product)) {
            throw new InvalidArgumentException('Insurance product is invalid.');
        }
        if ($this->rowNullableString($product, 'business_model') === null) {
            throw new InvalidArgumentException('Insurance product business model must be configured before premium collection.');
        }

        return $product;
    }

    /**
     * @return list<array{split_type:string, amount_minor:int, ledger_account_id:int, rule_version_split_id:?int}>
     */
    public function createPremiumSplitCreditLines(
        JournalEntry $journalEntry,
        object $assessment,
        object $subscription,
        object $product,
        int $amountMinor,
        string $currency,
    ): array {
        $agencyId = $this->rowInt($subscription, 'agency_id');
        $defaultCreditLedgerId = $this->premiumCollectionCreditLedgerId($agencyId, $currency);

        $configuredSplits = [];
        $ruleVersionId = $this->rowNullableInt($assessment, 'rule_version_id');
        if ($ruleVersionId !== null) {
            $configuredSplits = DB::table('insurance_product_rule_version_splits')
                ->where('insurance_product_rule_version_id', $ruleVersionId)
                ->get()
                ->all();
        }

        $splitRows = [];
        $configuredTotal = 0;
        foreach ($configuredSplits as $split) {
            $splitAmount = $this->computeSplitAmount($amountMinor, $split);
            if ($splitAmount <= 0) {
                continue;
            }
            $configuredTotal += $splitAmount;
            if ($configuredTotal > $amountMinor) {
                throw new InvalidArgumentException('Configured insurance premium splits exceed the collected premium amount.');
            }

            $ledgerAccountId = $this->rowNullableInt($split, 'ledger_account_id') ?? $defaultCreditLedgerId;
            $this->assertActiveAgencyLedger($ledgerAccountId, $agencyId);
            $splitRows[] = [
                'split_type' => $this->rowString($split, 'split_type'),
                'amount_minor' => $splitAmount,
                'ledger_account_id' => $ledgerAccountId,
                'rule_version_split_id' => $this->rowInt($split, 'id'),
            ];
        }

        $remainder = $amountMinor - $configuredTotal;
        if ($remainder > 0) {
            $fallbackSplitType = $this->rowString($product, 'business_model') === 'risk_carrier'
                ? 'institution_income'
                : 'insurer_payable';
            $splitRows[] = [
                'split_type' => $fallbackSplitType,
                'amount_minor' => $remainder,
                'ledger_account_id' => $defaultCreditLedgerId,
                'rule_version_split_id' => null,
            ];
        }

        foreach ($splitRows as $splitRow) {
            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $agencyId,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $splitRow['ledger_account_id'],
                'customer_account_id' => null,
                'loan_id' => null,
                'debit_minor' => 0,
                'credit_minor' => $splitRow['amount_minor'],
                'currency' => $currency,
                'line_memo' => 'Insurance premium '.$splitRow['split_type'],
            ]);
        }

        return $splitRows;
    }

    /**
     * @param  list<array{split_type:string, amount_minor:int, ledger_account_id:int, rule_version_split_id:?int}>  $splits
     */
    public function storePremiumPaymentSplits(int $paymentId, array $splits): void
    {
        foreach ($splits as $split) {
            DB::table('insurance_premium_payment_splits')->insert([
                'public_id' => (string) Str::ulid(),
                'insurance_premium_payment_id' => $paymentId,
                'insurance_product_rule_version_split_id' => $split['rule_version_split_id'],
                'split_type' => $split['split_type'],
                'amount_minor' => $split['amount_minor'],
                'ledger_account_id' => $split['ledger_account_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function postCancellationRefundIfRequired(object $cancellation, object $subscription, User $actor): ?JournalEntry
    {
        if ($this->rowString($cancellation, 'refund_treatment') === 'none') {
            return null;
        }

        $amountMinor = $this->rowNullableInt($cancellation, 'refund_amount_minor');
        $customerAccountId = $this->rowNullableInt($cancellation, 'refund_customer_account_id');
        if ($amountMinor === null || $amountMinor <= 0 || $customerAccountId === null) {
            throw new InvalidArgumentException('Approved refund cancellation requires refund amount and customer account.');
        }

        $customerAccount = CustomerAccount::query()
            ->with(['ledgerAccount'])
            ->whereKey($customerAccountId)
            ->first();
        if (! $customerAccount instanceof CustomerAccount
            || $customerAccount->status !== CustomerAccount::STATUS_ACTIVE
            || $customerAccount->client_id !== $this->rowInt($subscription, 'client_id')
            || $customerAccount->agency_id !== $this->rowInt($subscription, 'agency_id')
            || $customerAccount->currency !== $this->rowString($subscription, 'currency')) {
            throw new InvalidArgumentException('Refund account must be active and match the cancelled subscription.');
        }

        $customerLedger = $customerAccount->ledgerAccount;
        if (! $customerLedger instanceof LedgerAccount || $customerLedger->status !== LedgerAccount::STATUS_ACTIVE) {
            throw new InvalidArgumentException('Refund customer account ledger must be active.');
        }

        [$debitLedgerId, $creditLedgerId] = $this->operationLedgers(
            'insurance_premium_refund',
            $this->rowInt($subscription, 'agency_id'),
            $this->rowString($subscription, 'currency'),
        );
        if ($creditLedgerId !== $customerLedger->id) {
            throw new InvalidArgumentException('Refund operation credit ledger must match the customer account ledger.');
        }

        $accountingDay = $this->accountingDayGuard->assertCanRegister(
            $actor,
            'insurance.cancellation',
            $this->rowInt($subscription, 'agency_id'),
        );

        $journalEntry = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'IRF-'.Str::upper(Str::random(10)),
            'business_date' => $accountingDay->business_date->toDateString(),
            'posted_at' => null,
            'agency_id' => $this->rowInt($subscription, 'agency_id'),
            'source_module' => 'insurance',
            'source_type' => 'insurance_cancellation_refund',
            'source_public_id' => $this->rowString($cancellation, 'public_id'),
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => 'Insurance cancellation refund',
            'created_by_user_id' => $actor->id,
            'posted_by_user_id' => null,
            'idempotency_key' => 'insurance-cancellation-refund:'.$this->rowString($cancellation, 'public_id'),
            'accounting_day_id' => $accountingDay->id,
        ]);

        JournalLine::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $this->rowInt($subscription, 'agency_id'),
            'journal_entry_id' => $journalEntry->id,
            'ledger_account_id' => $debitLedgerId,
            'customer_account_id' => null,
            'loan_id' => null,
            'debit_minor' => $amountMinor,
            'credit_minor' => 0,
            'currency' => $this->rowString($subscription, 'currency'),
            'line_memo' => 'Insurance cancellation refund debit',
        ]);

        JournalLine::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $this->rowInt($subscription, 'agency_id'),
            'journal_entry_id' => $journalEntry->id,
            'ledger_account_id' => $creditLedgerId,
            'customer_account_id' => $customerAccount->id,
            'loan_id' => null,
            'debit_minor' => 0,
            'credit_minor' => $amountMinor,
            'currency' => $this->rowString($subscription, 'currency'),
            'line_memo' => 'Insurance cancellation refund credited to client account',
        ]);

        $this->postSystemJournal($journalEntry, $actor);

        return $journalEntry;
    }

    /**
     * @param  Collection<int, \stdClass>  $items
     */
    public function postRemittanceBatchJournal(object $batch, Collection $items, User $actor): JournalEntry
    {
        $partner = DB::table('insurance_partners')
            ->where('id', $this->rowInt($batch, 'insurance_partner_id'))
            ->first(['ledger_account_id']);
        $partnerLedgerId = is_object($partner) ? $this->rowNullableInt($partner, 'ledger_account_id') : null;
        if ($partnerLedgerId === null) {
            throw new InvalidArgumentException('Insurance partner ledger account is required for remittance posting.');
        }
        $this->assertActiveAgencyLedger($partnerLedgerId, $this->rowInt($batch, 'agency_id'));

        $payableByLedger = [];
        foreach ($items as $item) {
            if ($this->rowString($item, 'split_type') !== 'insurer_payable') {
                continue;
            }
            $ledgerAccountId = $this->rowInt($item, 'ledger_account_id');
            $payableByLedger[$ledgerAccountId] = ($payableByLedger[$ledgerAccountId] ?? 0) + $this->rowInt($item, 'amount_minor');
        }

        $totalMinor = array_sum($payableByLedger);
        if ($totalMinor <= 0) {
            throw new InvalidArgumentException('Remittance approval requires a positive insurer payable amount.');
        }

        $accountingDay = $this->accountingDayGuard->assertCanRegister(
            $actor,
            'insurance.remittance',
            $this->rowInt($batch, 'agency_id'),
        );

        $journalEntry = JournalEntry::query()->create([
            'public_id' => (string) Str::ulid(),
            'reference' => 'IRM-'.Str::upper(Str::random(10)),
            'business_date' => $accountingDay->business_date->toDateString(),
            'posted_at' => null,
            'agency_id' => $this->rowInt($batch, 'agency_id'),
            'source_module' => 'insurance',
            'source_type' => 'insurance_remittance_batch',
            'source_public_id' => $this->rowString($batch, 'public_id'),
            'status' => JournalEntry::STATUS_DRAFT,
            'description' => 'Insurance partner remittance approval',
            'created_by_user_id' => $actor->id,
            'posted_by_user_id' => null,
            'idempotency_key' => 'insurance-remittance:'.$this->rowString($batch, 'public_id'),
            'accounting_day_id' => $accountingDay->id,
        ]);

        foreach ($payableByLedger as $ledgerAccountId => $amountMinor) {
            $this->assertActiveAgencyLedger($ledgerAccountId, $this->rowInt($batch, 'agency_id'));
            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $this->rowInt($batch, 'agency_id'),
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $ledgerAccountId,
                'customer_account_id' => null,
                'loan_id' => null,
                'debit_minor' => $amountMinor,
                'credit_minor' => 0,
                'currency' => $this->rowString($batch, 'currency'),
                'line_memo' => 'Insurance remittance payable cleared',
            ]);
        }

        JournalLine::query()->create([
            'public_id' => (string) Str::ulid(),
            'agency_id' => $this->rowInt($batch, 'agency_id'),
            'journal_entry_id' => $journalEntry->id,
            'ledger_account_id' => $partnerLedgerId,
            'customer_account_id' => null,
            'loan_id' => null,
            'debit_minor' => 0,
            'credit_minor' => $totalMinor,
            'currency' => $this->rowString($batch, 'currency'),
            'line_memo' => 'Insurance remittance payable to partner',
        ]);

        $this->postSystemJournal($journalEntry, $actor);

        return $journalEntry;
    }

    public function postSystemJournal(JournalEntry $journalEntry, User $actor): void
    {
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_SUBMITTED,
            'submitted_at' => now(),
            'submitted_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_APPROVED,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $actor->id,
        ])->save();
        $journalEntry->forceFill([
            'status' => JournalEntry::STATUS_POSTED,
            'posted_at' => now(),
            'posted_by_user_id' => $actor->id,
        ])->save();
    }

    private function premiumCollectionCreditLedgerId(int $agencyId, string $currency): int
    {
        $operationCode = 'insurance_premium_collection';
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'insurance')
            ->where('operation_codes.status', 'active')
            ->where('operation_account_mappings.status', 'active')
            ->where(function ($query) use ($currency): void {
                $query->whereNull('operation_account_mappings.currency')
                    ->orWhere('operation_account_mappings.currency', $currency);
            })
            ->where('ledger_accounts.agency_id', $agencyId)
            ->where('ledger_accounts.status', LedgerAccount::STATUS_ACTIVE)
            ->orderByRaw('operation_account_mappings.currency IS NULL')
            ->first(['operation_account_mappings.credit_ledger_account_id']);

        $ledgerAccountId = is_object($mapping) ? $mapping->credit_ledger_account_id : null;
        if (! is_int($ledgerAccountId)) {
            throw new InvalidArgumentException('Active credit ledger mapping is required for '.$operationCode.'.');
        }

        return $ledgerAccountId;
    }

    /**
     * @return array{0:int, 1:int}
     */
    private function operationLedgers(string $operationCode, int $agencyId, string $currency): array
    {
        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts as debit_ledgers', 'debit_ledgers.id', '=', 'operation_account_mappings.debit_ledger_account_id')
            ->join('ledger_accounts as credit_ledgers', 'credit_ledgers.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'insurance')
            ->where('operation_codes.status', 'active')
            ->where('operation_account_mappings.status', 'active')
            ->where('debit_ledgers.agency_id', $agencyId)
            ->where('debit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
            ->where('credit_ledgers.agency_id', $agencyId)
            ->where('credit_ledgers.status', LedgerAccount::STATUS_ACTIVE)
            ->where(function ($query) use ($currency): void {
                $query->whereNull('operation_account_mappings.currency')
                    ->orWhere('operation_account_mappings.currency', $currency);
            })
            ->orderByRaw('operation_account_mappings.currency IS NULL')
            ->first(['operation_account_mappings.debit_ledger_account_id', 'operation_account_mappings.credit_ledger_account_id']);

        $debitId = is_object($mapping) ? $mapping->debit_ledger_account_id : null;
        $creditId = is_object($mapping) ? $mapping->credit_ledger_account_id : null;
        if (! is_int($debitId) || ! is_int($creditId)) {
            throw new InvalidArgumentException('Active debit and credit ledger mappings are required for '.$operationCode.'.');
        }

        return [$debitId, $creditId];
    }

    private function assertActiveAgencyLedger(int $ledgerAccountId, int $agencyId): void
    {
        $exists = DB::table('ledger_accounts')
            ->where('id', $ledgerAccountId)
            ->where('agency_id', $agencyId)
            ->where('status', LedgerAccount::STATUS_ACTIVE)
            ->exists();
        if (! $exists) {
            throw new InvalidArgumentException('Insurance ledger must be active and agency-scoped.');
        }
    }

    private function computeSplitAmount(int $totalMinor, object $split): int
    {
        $calcType = $this->rowString($split, 'calculation_type');
        if ($calcType === 'fixed') {
            return $this->rowNullableInt($split, 'fixed_minor') ?? 0;
        }

        $rate = (float) ($this->rowNullableString($split, 'rate') ?? '0');

        return (int) round($totalMinor * $rate / 100);
    }

    private function rowString(object $row, string $key): string
    {
        $value = $this->rowValue($row, $key);

        return is_string($value) ? $value : (string) ($value ?? '');
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $value = $this->rowValue($row, $key);
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function rowInt(object $row, string $key): int
    {
        $value = $this->rowValue($row, $key);

        return is_int($value) ? $value : (int) ($value ?? 0);
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $value = $this->rowValue($row, $key);
        if ($value === null) {
            return null;
        }

        return is_int($value) ? $value : (int) $value;
    }

    private function rowValue(object $row, string $key): string|int|float|bool|null
    {
        $value = get_object_vars($row)[$key] ?? null;
        if ($value === null || is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        return null;
    }
}
