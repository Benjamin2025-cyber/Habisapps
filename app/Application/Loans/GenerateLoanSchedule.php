<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanScheduleLine;
use App\Models\LoanScheduleSnapshot;
use App\Models\User;
use App\Support\Finance\FormulaEngineKey;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\FormulaPolicyRegistry;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class GenerateLoanSchedule
{
    private const string ENGINE_VERSION = 'stakeholder-flat-equal-v1';

    public function __construct(
        private readonly FormulaPolicyRegistry $formulaPolicyRegistry,
    ) {}

    public function handle(Loan $loan, User $actor, bool $replaceActive = false): LoanScheduleSnapshot
    {
        $this->requireApprovedFormulaPolicies();

        return DB::transaction(function () use ($loan, $actor, $replaceActive): LoanScheduleSnapshot {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();

            $lockedLoan = Loan::query()
                ->with('loanProduct')
                ->whereKey($loan->id)
                ->firstOrFail();

            if (! in_array($lockedLoan->status, [Loan::STATUS_APPROVED, Loan::STATUS_RESCHEDULED], true)) {
                throw new InvalidArgumentException('Schedule generation requires an approved or rescheduled loan.');
            }

            $product = $lockedLoan->loanProduct;
            if (! $product instanceof LoanProduct) {
                throw new InvalidArgumentException('Loan product is required before schedule generation.');
            }

            $policyHash = $this->policySnapshotHash($lockedLoan);
            if (! $replaceActive) {
                $existing = LoanScheduleSnapshot::query()
                    ->with('lines')
                    ->where('loan_id', $lockedLoan->id)
                    ->where('status', LoanScheduleSnapshot::STATUS_ACTIVE)
                    ->where('policy_snapshot_hash', $policyHash)
                    ->first();

                if ($existing instanceof LoanScheduleSnapshot) {
                    return $existing;
                }
            } else {
                LoanScheduleSnapshot::query()
                    ->where('loan_id', $lockedLoan->id)
                    ->where('status', LoanScheduleSnapshot::STATUS_ACTIVE)
                    ->update(['status' => LoanScheduleSnapshot::STATUS_SUPERSEDED]);
            }

            $installments = $this->installmentCount($lockedLoan);
            $principal = $lockedLoan->approved_principal_minor ?? $lockedLoan->requested_amount_minor;
            $appliedInterestRate = $lockedLoan->applied_interest_rate ?? $product->interest_rate ?? '0';
            $interest = $this->percentOf($principal, $appliedInterestRate, 'interest total');
            $fees = $this->installmentChargeAmount($product, 'fees', $lockedLoan->dossier_fees_minor ?? 0);
            $insurance = $this->installmentChargeAmount($product, 'insurance', $lockedLoan->insurance_amount_minor ?? 0);
            $tax = $this->installmentChargeAmount($product, 'tax', $lockedLoan->dossier_fees_tax_minor ?? 0);

            $componentShares = [
                'principal_minor' => $this->splitWithFinalResidual($principal, $installments),
                'interest_minor' => $this->splitWithFinalResidual($interest, $installments),
                'fees_minor' => $this->splitWithFinalResidual($fees, $installments),
                'insurance_minor' => $this->splitWithFinalResidual($insurance, $installments),
                'tax_minor' => $this->splitWithFinalResidual($tax, $installments),
            ];
            $this->assertComponentShares($componentShares, [
                'principal_minor' => $principal,
                'interest_minor' => $interest,
                'fees_minor' => $fees,
                'insurance_minor' => $insurance,
                'tax_minor' => $tax,
            ]);

            $snapshot = LoanScheduleSnapshot::query()->create([
                'public_id' => (string) Str::ulid(),
                'loan_id' => $lockedLoan->id,
                'formula_engine_key' => FormulaEngineKey::Installment->value,
                'formula_engine_version' => self::ENGINE_VERSION,
                'policy_snapshot_hash' => $policyHash,
                'generated_by_user_id' => $actor->id,
                'generated_at' => now(),
                'status' => LoanScheduleSnapshot::STATUS_ACTIVE,
            ]);

            $remainingPrincipal = $principal;
            $firstDueDate = $this->firstDueDate($lockedLoan, $product);
            for ($installment = 1; $installment <= $installments; $installment++) {
                $components = [];
                foreach ($componentShares as $component => $shares) {
                    $components[$component] = $shares[$installment - 1];
                }

                $remainingPrincipal -= $components['principal_minor'];
                LoanScheduleLine::query()->create([
                    'loan_schedule_snapshot_id' => $snapshot->id,
                    'installment_number' => $installment,
                    'due_date' => $firstDueDate->addMonthsNoOverflow($installment - 1)->toDateString(),
                    'principal_minor' => $components['principal_minor'],
                    'interest_minor' => $components['interest_minor'],
                    'fees_minor' => $components['fees_minor'],
                    'insurance_minor' => $components['insurance_minor'],
                    'tax_minor' => $components['tax_minor'],
                    'penalty_minor' => 0,
                    'capitalized_interest_minor' => 0,
                    'remaining_principal_minor' => max(0, $remainingPrincipal),
                    'total_installment_minor' => array_sum($components),
                    'currency' => $lockedLoan->currency,
                    'status' => LoanScheduleLine::STATUS_SCHEDULED,
                ]);
            }

            $snapshot = $snapshot->refresh()->load('lines');
            $this->assertPersistedScheduleTotals($snapshot, [
                'principal_minor' => $principal,
                'interest_minor' => $interest,
                'fees_minor' => $fees,
                'insurance_minor' => $insurance,
                'tax_minor' => $tax,
            ]);

            return $snapshot;
        });
    }

    private function requireApprovedFormulaPolicies(): void
    {
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::XafRounding);
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::LoanInterestMethod);
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::LoanInstallmentAmount);
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::FeesTaxesInsurance);
    }

    private function installmentCount(Loan $loan): int
    {
        $installments = $loan->number_of_installments;
        if (! is_int($installments) || $installments < 1) {
            throw new InvalidArgumentException('Loan must define a positive number of installments.');
        }

        return $installments;
    }

    private function firstDueDate(Loan $loan, LoanProduct $product): CarbonImmutable
    {
        if ($loan->first_installment_date !== null) {
            return CarbonImmutable::parse($loan->first_installment_date);
        }

        $base = $loan->approved_on !== null ? CarbonImmutable::parse($loan->approved_on) : CarbonImmutable::now();
        $dueDate = $base->addMonthNoOverflow();

        if ($product->due_date_day !== null) {
            $day = min($product->due_date_day, $dueDate->daysInMonth);

            return $dueDate->day($day);
        }

        return $dueDate;
    }

    private function installmentChargeAmount(LoanProduct $product, string $component, int $amountMinor): int
    {
        if ($amountMinor <= 0) {
            return 0;
        }

        $rules = $product->getAttribute('rules');
        $installmentCharges = is_array($rules) && is_array($rules['installment_charges'] ?? null)
            ? $rules['installment_charges']
            : [];
        $policy = $installmentCharges[$component] ?? null;

        if ($policy === true || $policy === 'financed' || $policy === 'periodic') {
            return $amountMinor;
        }

        return 0;
    }

    private function policySnapshotHash(Loan $loan): string
    {
        $payload = [
            'formula_policy_snapshot' => $loan->formula_policy_snapshot,
            'approved_principal_minor' => $loan->approved_principal_minor,
            'requested_amount_minor' => $loan->requested_amount_minor,
            'applied_interest_rate' => $loan->applied_interest_rate,
            'applied_tax_rate' => $loan->applied_tax_rate,
            'number_of_installments' => $loan->number_of_installments,
            'first_installment_date' => $this->dateForHash($loan->first_installment_date),
            'dossier_fees_minor' => $loan->dossier_fees_minor,
            'dossier_fees_tax_minor' => $loan->dossier_fees_tax_minor,
            'insurance_amount_minor' => $loan->insurance_amount_minor,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function percentOf(int $baseMinor, mixed $rate, string $label): int
    {
        if (! is_int($rate) && ! is_float($rate) && ! is_string($rate)) {
            throw new InvalidArgumentException(__('loans.schedule_rate_must_be_numeric', ['label' => $label]));
        }

        try {
            return BigDecimal::of((string) $baseMinor)
                ->multipliedBy(BigDecimal::of((string) $rate))
                ->dividedBy('100')
                ->toScale(0, RoundingMode::UNNECESSARY)
                ->toInt();
        } catch (MathException) {
            throw new InvalidArgumentException(__('loans.schedule_not_whole_minor_units', ['label' => $label]));
        }
    }

    private function dateForHash(mixed $date): ?string
    {
        if ($date instanceof DateTimeInterface) {
            return CarbonImmutable::instance($date)->toDateString();
        }

        if (is_string($date) && $date !== '') {
            return CarbonImmutable::parse($date)->toDateString();
        }

        return null;
    }

    /**
     * @return list<int>
     */
    private function splitWithFinalResidual(int $amountMinor, int $installments): array
    {
        $baseShare = intdiv($amountMinor, $installments);
        $shares = [];
        for ($index = 0; $index < $installments; $index++) {
            $shares[] = $baseShare;
        }

        $shares[$installments - 1] += $amountMinor - ($baseShare * $installments);

        return array_values($shares);
    }

    /**
     * @param  array<string, list<int>>  $shares
     * @param  array<string, int>  $expectedTotals
     */
    private function assertComponentShares(array $shares, array $expectedTotals): void
    {
        foreach ($expectedTotals as $component => $expectedTotal) {
            if (! isset($shares[$component]) || array_sum($shares[$component]) !== $expectedTotal) {
                throw new InvalidArgumentException(__('loans.schedule_component_shares_do_not_reconcile', ['component' => $component]));
            }
        }
    }

    /**
     * @param  array<string, int>  $expectedTotals
     */
    private function assertPersistedScheduleTotals(LoanScheduleSnapshot $snapshot, array $expectedTotals): void
    {
        foreach ($expectedTotals as $component => $expectedTotal) {
            $actual = $snapshot->lines->sum($component);
            if ($actual !== $expectedTotal) {
                throw new InvalidArgumentException(__('loans.schedule_persisted_totals_do_not_reconcile', ['component' => $component]));
            }
        }
    }
}
