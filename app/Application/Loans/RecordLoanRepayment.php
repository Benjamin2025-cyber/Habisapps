<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\CustomerAccount;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepayment;
use App\Models\LoanRepaymentAllocation;
use App\Models\LoanScheduleLine;
use App\Models\LoanScheduleSnapshot;
use App\Models\OperationAccountMapping;
use App\Models\OperationCode;
use App\Models\User;
use App\Support\Accounting\AccountingBalanceCalculator;
use App\Support\AccountingDay\AccountingDayGuard;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\FormulaPolicyRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RecordLoanRepayment
{
    /** @var array<string, string> */
    private const array COMPONENT_OPERATION_CODES = [
        LoanRepaymentAllocation::COMPONENT_PRINCIPAL => 'loan_repayment_principal',
        LoanRepaymentAllocation::COMPONENT_INTEREST => 'loan_repayment_interest',
        LoanRepaymentAllocation::COMPONENT_FEES => 'loan_repayment_fees',
        LoanRepaymentAllocation::COMPONENT_INSURANCE => 'loan_repayment_insurance',
        LoanRepaymentAllocation::COMPONENT_TAX => 'loan_repayment_tax',
        LoanRepaymentAllocation::COMPONENT_PENALTY => 'loan_repayment_penalty',
    ];

    public function __construct(
        private readonly FormulaPolicyRegistry $formulaPolicyRegistry,
        private readonly AccountingBalanceCalculator $balanceCalculator,
        private readonly AccountingDayGuard $accountingDayGuard,
    ) {}

    /**
     * @return array{loan: Loan, repayment: LoanRepayment, journal_entry: JournalEntry}
     */
    public function handle(Loan $loan, User $actor, int $amountMinor, string $customerAccountPublicId, ?string $paidOn = null, ?string $notes = null, ?string $futureInterestWaiverDate = null, int $futureInterestConcessionMinor = 0, ?string $futureInterestConcessionDate = null): array
    {
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::RepaymentAllocationOrder);

        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Repayment amount must be positive.');
        }

        return DB::transaction(function () use ($actor, $amountMinor, $customerAccountPublicId, $futureInterestConcessionDate, $futureInterestConcessionMinor, $futureInterestWaiverDate, $loan, $notes, $paidOn): array {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();

            $lockedLoan = Loan::query()
                ->with(['loanProduct'])
                ->whereKey($loan->id)
                ->firstOrFail();

            $customerAccount = CustomerAccount::query()->where('public_id', $customerAccountPublicId)->first();
            if (! $customerAccount instanceof CustomerAccount
                || $customerAccount->status !== CustomerAccount::STATUS_ACTIVE
                || $customerAccount->client_id !== $lockedLoan->client_id
                || $customerAccount->agency_id !== $lockedLoan->agency_id) {
                throw new InvalidArgumentException('Repayment account must be active and belong to the loan client and agency.');
            }

            if ($customerAccount->ledger_account_id === null) {
                throw new InvalidArgumentException('Repayment account ledger mapping is required before repayment.');
            }

            $customerLedger = LedgerAccount::query()->whereKey($customerAccount->ledger_account_id)->first();
            if (! $customerLedger instanceof LedgerAccount || $customerLedger->status !== LedgerAccount::STATUS_ACTIVE || $customerLedger->agency_id !== $lockedLoan->agency_id) {
                throw new InvalidArgumentException('Repayment account ledger account must be active and belong to the loan agency.');
            }

            if ($customerAccount->currency !== $lockedLoan->currency) {
                throw new InvalidArgumentException('Repayment account currency must match the loan currency.');
            }

            $postedAt = now();
            $accountingDay = $this->accountingDayGuard->resolveAccountingDay($actor, 'loan.repay', $lockedLoan->agency_id, $paidOn);
            $paidDate = (string) $accountingDay->business_date?->toDateString();
            $idempotencyKey = 'loan-repayment:'.hash('sha256', implode('|', [
                $lockedLoan->public_id,
                $customerAccount->public_id,
                $paidDate,
                (string) $amountMinor,
                (string) $notes,
            ]));

            $existingRepayment = LoanRepayment::query()
                ->with(['allocations.scheduleLine', 'customerAccount', 'postedBy', 'journalEntry.lines.ledgerAccount', 'journalEntry.lines.customerAccount'])
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingRepayment instanceof LoanRepayment) {
                $existingJournal = $existingRepayment->journalEntry;
                if (! $existingJournal instanceof JournalEntry) {
                    throw new InvalidArgumentException('Existing loan repayment is missing its journal entry.');
                }

                return [
                    'loan' => $lockedLoan->refresh(),
                    'repayment' => $existingRepayment,
                    'journal_entry' => $existingJournal,
                ];
            }

            if (! in_array($lockedLoan->status, [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED], true)) {
                throw new InvalidArgumentException('Only disbursed, active, or rescheduled loans can receive repayments.');
            }

            $product = $lockedLoan->loanProduct;
            if (! $product instanceof LoanProduct || $product->ledger_account_id === null) {
                throw new InvalidArgumentException('Loan product ledger mapping is required before repayment.');
            }

            $loanLedger = LedgerAccount::query()->whereKey($product->ledger_account_id)->first();
            if (! $loanLedger instanceof LedgerAccount || $loanLedger->status !== LedgerAccount::STATUS_ACTIVE || $loanLedger->agency_id !== $lockedLoan->agency_id) {
                throw new InvalidArgumentException('Loan product ledger account must be active and belong to the loan agency.');
            }

            $allocations = $this->allocate($lockedLoan, $amountMinor, $futureInterestWaiverDate, $futureInterestConcessionMinor, $futureInterestConcessionDate);
            $allocatedTotal = array_sum(array_column($allocations, 'amount_minor'));
            if ($allocatedTotal <= 0) {
                throw new InvalidArgumentException('There is no scheduled amount available for repayment allocation.');
            }

            DB::table('customer_accounts')->where('id', $customerAccount->id)->lockForUpdate()->first();
            $lockedCustomerAccount = CustomerAccount::query()->whereKey($customerAccount->id)->firstOrFail();
            $availableBalance = $this->balanceCalculator->availableForCustomerAccount($lockedCustomerAccount, $lockedLoan->currency)['available_balance_minor'];
            if ($allocatedTotal > $availableBalance) {
                throw new InvalidArgumentException('Repayment amount exceeds the customer account available balance.');
            }

            $componentCredits = $this->componentCredits($allocations, $lockedLoan->agency_id, $lockedLoan->currency);
            $reference = 'LR-'.$lockedLoan->loan_number.'-'.Str::upper(Str::random(8));

            $journalEntry = JournalEntry::query()->create([
                'public_id' => (string) Str::ulid(),
                'reference' => $reference,
                'business_date' => $paidDate,
                'accounting_day_id' => $accountingDay->id,
                'posted_at' => null,
                'agency_id' => $lockedLoan->agency_id,
                'source_module' => 'credit_loans',
                'source_type' => 'loan_repayment',
                'source_public_id' => $lockedLoan->public_id,
                'status' => JournalEntry::STATUS_DRAFT,
                'description' => $notes ?? 'Loan repayment '.$lockedLoan->loan_number,
                'created_by_user_id' => $actor->id,
                'posted_by_user_id' => null,
                'idempotency_key' => $idempotencyKey,
            ]);

            JournalLine::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $lockedLoan->agency_id,
                'journal_entry_id' => $journalEntry->id,
                'ledger_account_id' => $customerLedger->id,
                'customer_account_id' => $customerAccount->id,
                'loan_id' => $lockedLoan->id,
                'debit_minor' => $allocatedTotal,
                'credit_minor' => 0,
                'currency' => $lockedLoan->currency,
                'line_memo' => 'Loan repayment debited from customer account',
            ]);

            foreach ($componentCredits as $component => $credit) {
                JournalLine::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'agency_id' => $lockedLoan->agency_id,
                    'journal_entry_id' => $journalEntry->id,
                    'ledger_account_id' => $credit['ledger_account_id'],
                    'customer_account_id' => null,
                    'loan_id' => $lockedLoan->id,
                    'debit_minor' => 0,
                    'credit_minor' => $credit['amount_minor'],
                    'currency' => $lockedLoan->currency,
                    'line_memo' => 'Loan repayment allocated to '.$component,
                ]);
            }

            $this->postSystemJournal($journalEntry, $actor);

            $repayment = LoanRepayment::query()->create([
                'public_id' => (string) Str::ulid(),
                'agency_id' => $lockedLoan->agency_id,
                'loan_id' => $lockedLoan->id,
                'journal_entry_id' => $journalEntry->id,
                'customer_account_id' => $customerAccount->id,
                'received_amount_minor' => $amountMinor,
                'allocated_amount_minor' => $allocatedTotal,
                'overpayment_retained_minor' => $amountMinor - $allocatedTotal,
                'currency' => $lockedLoan->currency,
                'paid_on' => $paidDate,
                'status' => LoanRepayment::STATUS_POSTED,
                'posted_at' => $postedAt,
                'posted_by_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'metadata' => [
                    'allocation_order' => $this->allocationPolicyKey($lockedLoan),
                    'penalty_collection_order' => 'oldest_penalty_first_after_scheduled_dues',
                    'future_interest_waiver_date' => $futureInterestWaiverDate,
                    'future_interest_concession_minor' => $futureInterestConcessionMinor,
                    'future_interest_concession_date' => $futureInterestConcessionDate,
                    'notes' => $notes,
                ],
            ]);

            foreach ($allocations as $allocation) {
                LoanRepaymentAllocation::query()->create([
                    'loan_repayment_id' => $repayment->id,
                    'loan_schedule_line_id' => $allocation['loan_schedule_line_id'],
                    'component' => $allocation['component'],
                    'amount_minor' => $allocation['amount_minor'],
                    'currency' => $lockedLoan->currency,
                ]);
            }

            $lockedLoan->forceFill([
                'last_repayment_date' => $paidDate,
                'installments_repaid_count' => $this->paidInstallmentCount($lockedLoan),
                'next_repayment_date' => $this->nextRepaymentDate($lockedLoan),
            ])->save();

            return [
                'loan' => $lockedLoan->refresh(),
                'repayment' => $repayment->refresh()->loadMissing(['allocations.scheduleLine', 'customerAccount', 'postedBy']),
                'journal_entry' => $journalEntry->refresh()->loadMissing(['agency', 'lines.ledgerAccount', 'lines.customerAccount']),
            ];
        });
    }

    /**
     * @param  array<int, array{loan_schedule_line_id:int, component:string, amount_minor:int}>  $allocations
     * @return array<string, array{ledger_account_id:int, amount_minor:int}>
     */
    private function componentCredits(array $allocations, int $agencyId, string $currency): array
    {
        $componentAmounts = [];
        foreach ($allocations as $allocation) {
            $component = $allocation['component'];
            $componentAmounts[$component] = ($componentAmounts[$component] ?? 0) + $allocation['amount_minor'];
        }

        $credits = [];
        foreach ($componentAmounts as $component => $amountMinor) {
            if ($amountMinor <= 0) {
                continue;
            }

            $ledgerAccountId = $this->componentCreditLedgerAccountId($component, $agencyId, $currency);
            $credits[$component] = [
                'ledger_account_id' => $ledgerAccountId,
                'amount_minor' => $amountMinor,
            ];
        }

        return $credits;
    }

    private function componentCreditLedgerAccountId(string $component, int $agencyId, string $currency): int
    {
        $operationCode = self::COMPONENT_OPERATION_CODES[$component] ?? null;
        if ($operationCode === null) {
            throw new InvalidArgumentException('Unsupported loan repayment component: '.$component.'.');
        }

        $mapping = DB::table('operation_account_mappings')
            ->join('operation_codes', 'operation_codes.id', '=', 'operation_account_mappings.operation_code_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'operation_account_mappings.credit_ledger_account_id')
            ->where('operation_codes.code', $operationCode)
            ->where('operation_codes.module', 'loan')
            ->where('operation_codes.status', OperationCode::STATUS_ACTIVE)
            ->where('operation_account_mappings.status', OperationAccountMapping::STATUS_ACTIVE)
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

    public function outstandingAmount(Loan $loan, ?string $futureInterestWaiverDate = null, int $futureInterestConcessionMinor = 0, ?string $futureInterestConcessionDate = null): int
    {
        $open = 0;
        $interestConcessionRemaining = $futureInterestConcessionMinor;
        foreach ($this->activeScheduleLines($loan) as $line) {
            $open += $this->lineOpenAmount($line, $futureInterestWaiverDate, $interestConcessionRemaining, $futureInterestConcessionDate);
        }

        return $open;
    }

    public function openFutureInterestAmount(Loan $loan, string $paidDate): int
    {
        $open = 0;
        foreach ($this->activeScheduleLines($loan) as $line) {
            $dueDate = $this->formatDateOnly($line->getAttribute('due_date'));
            if ($dueDate === null || $dueDate <= $paidDate) {
                continue;
            }

            $open += $this->componentOpenAmount($line, LoanRepaymentAllocation::COMPONENT_INTEREST, 'interest_minor');
        }

        return $open;
    }

    public function openInterestAmount(Loan $loan): int
    {
        $open = 0;
        foreach ($this->activeScheduleLines($loan) as $line) {
            $open += $this->componentOpenAmount($line, LoanRepaymentAllocation::COMPONENT_INTEREST, 'interest_minor');
        }

        return $open;
    }

    public function paidInterestAmount(Loan $loan): int
    {
        $value = DB::table('loan_repayment_allocations')
            ->join('loan_repayments', 'loan_repayments.id', '=', 'loan_repayment_allocations.loan_repayment_id')
            ->where('loan_repayments.loan_id', $loan->id)
            ->where('loan_repayment_allocations.component', LoanRepaymentAllocation::COMPONENT_INTEREST)
            ->sum('loan_repayment_allocations.amount_minor');

        return is_int($value) ? $value : (int) $value;
    }

    /**
     * @return array<int, array{loan_schedule_line_id:int, component:string, amount_minor:int}>
     */
    private function allocate(Loan $loan, int $amountMinor, ?string $futureInterestWaiverDate = null, int $futureInterestConcessionMinor = 0, ?string $futureInterestConcessionDate = null): array
    {
        $remaining = $amountMinor;
        $allocations = [];
        $lines = $this->activeScheduleLines($loan);
        $interestConcessionRemaining = $futureInterestConcessionMinor;
        $components = $this->components();
        $orderedComponents = $this->allocationComponentOrder($loan);
        $penaltyComponent = LoanRepaymentAllocation::COMPONENT_PENALTY;
        $isEarlySettlement = $futureInterestWaiverDate !== null
            || $futureInterestConcessionMinor > 0
            || $futureInterestConcessionDate !== null;

        if ($isEarlySettlement) {
            foreach ($orderedComponents as $component) {
                $column = $components[$component] ?? null;
                if ($column === null) {
                    continue;
                }

                foreach ($lines as $line) {
                    if ($this->shouldWaiveFutureInterest($line, $component, $futureInterestWaiverDate)) {
                        continue;
                    }

                    $remaining = $this->allocateComponent(
                        $allocations,
                        $remaining,
                        $line,
                        $component,
                        $column,
                        $futureInterestConcessionDate,
                        $interestConcessionRemaining
                    );

                    if ($remaining === 0) {
                        return $allocations;
                    }
                }
            }

            return $allocations;
        }

        $scheduledComponents = array_values(array_filter(
            $orderedComponents,
            static fn (string $component): bool => $component !== $penaltyComponent
        ));

        foreach ($lines as $line) {
            foreach ($scheduledComponents as $component) {
                $column = $components[$component] ?? null;
                if ($column === null || $this->shouldWaiveFutureInterest($line, $component, $futureInterestWaiverDate)) {
                    continue;
                }

                $remaining = $this->allocateComponent(
                    $allocations,
                    $remaining,
                    $line,
                    $component,
                    $column,
                    $futureInterestConcessionDate,
                    $interestConcessionRemaining
                );

                if ($remaining === 0) {
                    return $allocations;
                }
            }
        }

        if (in_array($penaltyComponent, $orderedComponents, true)) {
            foreach ($lines as $line) {
                $remaining = $this->allocateComponent(
                    $allocations,
                    $remaining,
                    $line,
                    $penaltyComponent,
                    $components[$penaltyComponent],
                    $futureInterestConcessionDate,
                    $interestConcessionRemaining
                );

                if ($remaining === 0) {
                    return $allocations;
                }
            }
        }

        return $allocations;
    }

    /**
     * @param  array<int, array{loan_schedule_line_id:int, component:string, amount_minor:int}>  $allocations
     */
    private function allocateComponent(
        array &$allocations,
        int $remaining,
        LoanScheduleLine $line,
        string $component,
        string $column,
        ?string $futureInterestConcessionDate,
        int &$interestConcessionRemaining
    ): int {
        $due = $this->componentDue($line, $column);
        if ($due <= 0) {
            return $remaining;
        }

        $paid = $this->alreadyAllocated($line->id, $component);
        $open = max(0, $due - $paid);
        $open = $this->applyFutureInterestConcession($line, $component, $open, $futureInterestConcessionDate, $interestConcessionRemaining);
        if ($open === 0) {
            return $remaining;
        }

        $amount = min($remaining, $open);
        if ($amount <= 0) {
            return $remaining;
        }

        $allocations[] = [
            'loan_schedule_line_id' => $line->id,
            'component' => $component,
            'amount_minor' => $amount,
        ];

        return $remaining - $amount;
    }

    /**
     * @return array<int, LoanScheduleLine>
     */
    private function activeScheduleLines(Loan $loan): array
    {
        $snapshot = LoanScheduleSnapshot::query()
            ->where('loan_id', $loan->id)
            ->where('status', LoanScheduleSnapshot::STATUS_ACTIVE)
            ->first();
        if (! $snapshot instanceof LoanScheduleSnapshot) {
            throw new InvalidArgumentException('An active repayment schedule is required before repayment.');
        }

        return LoanScheduleLine::query()
            ->where('loan_schedule_snapshot_id', $snapshot->id)
            ->get()
            ->sortBy([
                ['due_date', 'asc'],
                ['installment_number', 'asc'],
            ])
            ->values()
            ->all();
    }

    private function alreadyAllocated(int $lineId, string $component): int
    {
        $value = DB::table('loan_repayment_allocations')
            ->where('loan_schedule_line_id', $lineId)
            ->where('component', $component)
            ->sum('amount_minor');

        return is_int($value) ? $value : (int) $value;
    }

    private function paidInstallmentCount(Loan $loan): int
    {
        $count = 0;
        foreach ($this->activeScheduleLines($loan) as $line) {
            if ($this->lineOpenAmount($line) === 0) {
                $count++;
            }
        }

        return $count;
    }

    private function nextRepaymentDate(Loan $loan): ?string
    {
        foreach ($this->activeScheduleLines($loan) as $line) {
            if ($this->lineOpenAmount($line) > 0) {
                return $this->formatDateOnly($line->getAttribute('due_date'));
            }
        }

        return null;
    }

    private function lineOpenAmount(LoanScheduleLine $line, ?string $futureInterestWaiverDate = null, int &$interestConcessionRemaining = 0, ?string $futureInterestConcessionDate = null): int
    {
        $open = 0;
        foreach ($this->components() as $component => $column) {
            if ($this->shouldWaiveFutureInterest($line, $component, $futureInterestWaiverDate)) {
                continue;
            }

            $dueAmount = $this->componentDue($line, $column);
            if ($dueAmount > 0) {
                $componentOpen = max(0, $dueAmount - $this->alreadyAllocated($line->id, $component));
                $open += $this->applyFutureInterestConcession($line, $component, $componentOpen, $futureInterestConcessionDate, $interestConcessionRemaining);
            }
        }

        return $open;
    }

    private function componentOpenAmount(LoanScheduleLine $line, string $component, string $column): int
    {
        $due = $this->componentDue($line, $column);
        if ($due <= 0) {
            return 0;
        }

        return max(0, $due - $this->alreadyAllocated($line->id, $component));
    }

    private function componentDue(LoanScheduleLine $line, string $column): int
    {
        return match ($column) {
            'principal_minor' => $line->principal_minor,
            'interest_minor' => $line->interest_minor,
            'fees_minor' => $line->fees_minor,
            'insurance_minor' => $line->insurance_minor,
            'tax_minor' => $line->tax_minor,
            'penalty_minor' => $line->penalty_minor,
            default => 0,
        };
    }

    private function shouldWaiveFutureInterest(LoanScheduleLine $line, string $component, ?string $futureInterestWaiverDate): bool
    {
        if ($futureInterestWaiverDate === null || $component !== LoanRepaymentAllocation::COMPONENT_INTEREST) {
            return false;
        }

        $dueDate = $this->formatDateOnly($line->getAttribute('due_date'));

        return $dueDate !== null && $dueDate > $futureInterestWaiverDate;
    }

    private function applyFutureInterestConcession(LoanScheduleLine $line, string $component, int $open, ?string $futureInterestConcessionDate, int &$interestConcessionRemaining): int
    {
        if ($open <= 0 || $interestConcessionRemaining <= 0 || $component !== LoanRepaymentAllocation::COMPONENT_INTEREST) {
            return $open;
        }

        $dueDate = $this->formatDateOnly($line->getAttribute('due_date'));
        if ($futureInterestConcessionDate === null || $dueDate === null || $dueDate <= $futureInterestConcessionDate) {
            return $open;
        }

        $concession = min($open, $interestConcessionRemaining);
        $interestConcessionRemaining -= $concession;

        return $open - $concession;
    }

    /**
     * @return array<string, string>
     */
    private function components(): array
    {
        return [
            LoanRepaymentAllocation::COMPONENT_PRINCIPAL => 'principal_minor',
            LoanRepaymentAllocation::COMPONENT_INTEREST => 'interest_minor',
            LoanRepaymentAllocation::COMPONENT_FEES => 'fees_minor',
            LoanRepaymentAllocation::COMPONENT_INSURANCE => 'insurance_minor',
            LoanRepaymentAllocation::COMPONENT_TAX => 'tax_minor',
            LoanRepaymentAllocation::COMPONENT_PENALTY => 'penalty_minor',
        ];
    }

    /**
     * @return list<string>
     */
    private function allocationComponentOrder(Loan $loan): array
    {
        $snapshot = $loan->getAttribute('formula_policy_snapshot');
        $snapshot = is_array($snapshot) ? $snapshot : [];
        $productTerms = $snapshot['product_terms'] ?? [];
        $productTerms = is_array($productTerms) ? $productTerms : [];
        $productRules = $productTerms['rules'] ?? [];
        $productRules = is_array($productRules) ? $productRules : [];
        $rules = $productRules['repayment_allocation'] ?? null;
        $componentOrder = is_array($rules) ? ($rules['component_order'] ?? null) : null;

        if (! is_array($componentOrder)) {
            $componentOrder = config('formulas.policies.repayment_allocation_order.rule.component_order', []);
        }
        $componentOrder = is_array($componentOrder) ? $componentOrder : [];

        $known = array_keys($this->components());
        $order = [];
        foreach ($componentOrder as $component) {
            if (is_string($component) && in_array($component, $known, true) && ! in_array($component, $order, true)) {
                $order[] = $component;
            }
        }

        foreach ($known as $component) {
            if (! in_array($component, $order, true)) {
                $order[] = $component;
            }
        }

        return $order;
    }

    private function allocationPolicyKey(Loan $loan): string
    {
        return implode(',', $this->allocationComponentOrder($loan));
    }

    private function postSystemJournal(JournalEntry $journalEntry, User $actor): void
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

    private function formatDateOnly(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
