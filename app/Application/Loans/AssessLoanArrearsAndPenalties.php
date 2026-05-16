<?php

declare(strict_types=1);

namespace App\Application\Loans;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\LoanRepaymentAllocation;
use App\Models\LoanScheduleLine;
use App\Models\LoanScheduleSnapshot;
use App\Support\Finance\FormulaPolicyKey;
use App\Support\Finance\FormulaPolicyRegistry;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class AssessLoanArrearsAndPenalties
{
    public function __construct(
        private readonly FormulaPolicyRegistry $formulaPolicyRegistry,
    ) {}

    /**
     * @return array{loan: Loan, assessed_penalty_minor:int, arrears: array<int, array<string, mixed>>}
     */
    public function handle(Loan $loan, string $asOfDate): array
    {
        $this->formulaPolicyRegistry->requireApproved(FormulaPolicyKey::PenaltiesAndArrears);

        $asOf = CarbonImmutable::parse($asOfDate)->startOfDay();

        return DB::transaction(function () use ($asOf, $loan): array {
            DB::table('loans')->where('id', $loan->id)->lockForUpdate()->first();

            $lockedLoan = Loan::query()
                ->with(['loanProduct'])
                ->whereKey($loan->id)
                ->firstOrFail();

            if (! in_array($lockedLoan->status, [Loan::STATUS_DISBURSED, Loan::STATUS_ACTIVE, Loan::STATUS_RESCHEDULED], true)) {
                throw new InvalidArgumentException('Only disbursed, active, or rescheduled loans can be assessed for arrears.');
            }

            $snapshot = LoanScheduleSnapshot::query()
                ->where('loan_id', $lockedLoan->id)
                ->where('status', LoanScheduleSnapshot::STATUS_ACTIVE)
                ->first();
            if (! $snapshot instanceof LoanScheduleSnapshot) {
                throw new InvalidArgumentException('An active repayment schedule is required before arrears assessment.');
            }

            $assessedPenaltyMinor = 0;
            $arrears = [];
            $graceDays = $this->graceDays($lockedLoan);

            $lines = LoanScheduleLine::query()
                ->where('loan_schedule_snapshot_id', $snapshot->id)
                ->get()
                ->sortBy([
                    ['due_date', 'asc'],
                    ['installment_number', 'asc'],
                ])
                ->values();

            foreach ($lines as $line) {
                if (! $this->isPastGrace($line, $asOf, $graceDays)) {
                    continue;
                }

                $originalDue = $this->scheduledDueExcludingPenalties($line);
                if ($originalDue <= 0) {
                    continue;
                }

                $paid = $this->scheduledPaidExcludingPenalties($line);
                $unpaid = max(0, $originalDue - $paid);
                $penaltyBase = $unpaid >= $this->minimumUnpaidAmountMinor() ? $unpaid : null;
                $arrearsRow = $this->storeArrears($lockedLoan, $line, $originalDue, $paid, $unpaid, $penaltyBase);

                if ($unpaid === 0 || $penaltyBase === null || $this->alreadyPenalizedThisMonth($arrearsRow, $asOf)) {
                    $arrears[] = $this->arrearsPayload($arrearsRow);

                    continue;
                }

                $penalty = $this->monthlyPenalty($penaltyBase);
                $line->forceFill([
                    'penalty_minor' => $line->penalty_minor + $penalty,
                    'total_installment_minor' => $this->lineTotal($line) + $penalty,
                ])->save();

                DB::table('loan_arrears')
                    ->where('id', $this->rowInt($arrearsRow, 'id'))
                    ->update([
                        'last_penalized_at' => $asOf->toDateTimeString(),
                        'updated_at' => now(),
                    ]);

                $assessedPenaltyMinor += $penalty;
                $freshArrearsRow = DB::table('loan_arrears')->where('id', $this->rowInt($arrearsRow, 'id'))->first();
                if (is_object($freshArrearsRow)) {
                    $arrears[] = $this->arrearsPayload($freshArrearsRow);
                }
            }

            return [
                'loan' => $lockedLoan->refresh(),
                'assessed_penalty_minor' => $assessedPenaltyMinor,
                'arrears' => $arrears,
            ];
        });
    }

    private function isPastGrace(LoanScheduleLine $line, CarbonImmutable $asOf, int $graceDays): bool
    {
        $due = CarbonImmutable::parse($line->due_date)->startOfDay();

        return $asOf->greaterThanOrEqualTo($due->addDays($graceDays));
    }

    private function scheduledDueExcludingPenalties(LoanScheduleLine $line): int
    {
        return $line->principal_minor
            + $line->interest_minor
            + $line->fees_minor
            + $line->insurance_minor
            + $line->tax_minor;
    }

    private function scheduledPaidExcludingPenalties(LoanScheduleLine $line): int
    {
        $value = DB::table('loan_repayment_allocations')
            ->where('loan_schedule_line_id', $line->id)
            ->whereIn('component', [
                LoanRepaymentAllocation::COMPONENT_PRINCIPAL,
                LoanRepaymentAllocation::COMPONENT_INTEREST,
                LoanRepaymentAllocation::COMPONENT_FEES,
                LoanRepaymentAllocation::COMPONENT_INSURANCE,
                LoanRepaymentAllocation::COMPONENT_TAX,
            ])
            ->sum('amount_minor');

        return is_int($value) ? $value : (int) $value;
    }

    private function storeArrears(Loan $loan, LoanScheduleLine $line, int $originalDue, int $paid, int $unpaid, ?int $penaltyBase): object
    {
        $existing = DB::table('loan_arrears')
            ->where('loan_schedule_line_id', $line->id)
            ->first();

        $values = [
            'original_due_minor' => $originalDue,
            'paid_minor' => $paid,
            'unpaid_minor' => $unpaid,
            'penalty_base_minor' => $penaltyBase,
            'status' => $unpaid > 0 ? 'open' : 'closed',
            'updated_at' => now(),
        ];

        if (is_object($existing)) {
            DB::table('loan_arrears')->where('id', $this->rowInt($existing, 'id'))->update($values);

            $updated = DB::table('loan_arrears')->where('id', $this->rowInt($existing, 'id'))->first();
            if (is_object($updated)) {
                return $updated;
            }
        }

        $id = DB::table('loan_arrears')->insertGetId(array_merge($values, [
            'public_id' => (string) Str::ulid(),
            'loan_id' => $loan->id,
            'loan_schedule_line_id' => $line->id,
            'due_on' => $this->dateString($line->due_date),
            'currency' => $loan->currency,
            'created_at' => now(),
        ]));

        $created = DB::table('loan_arrears')->where('id', $id)->first();
        if (! is_object($created)) {
            throw new InvalidArgumentException('Loan arrears assessment could not be loaded.');
        }

        return $created;
    }

    private function monthlyPenalty(int $penaltyBaseMinor): int
    {
        return $this->fixedPenaltyAmountMinor() + $this->percentOf($penaltyBaseMinor, $this->variableRatePercent());
    }

    private function percentOf(int $baseMinor, string $rate): int
    {
        return BigDecimal::of((string) $baseMinor)
            ->multipliedBy(BigDecimal::of($rate))
            ->dividedBy('100')
            ->toScale(0, RoundingMode::HALF_UP)
            ->toInt();
    }

    private function lineTotal(LoanScheduleLine $line): int
    {
        return $line->principal_minor
            + $line->interest_minor
            + $line->fees_minor
            + $line->insurance_minor
            + $line->tax_minor
            + $line->penalty_minor
            + $line->capitalized_interest_minor;
    }

    private function alreadyPenalizedThisMonth(object $arrearsRow, CarbonImmutable $asOf): bool
    {
        $data = (array) $arrearsRow;
        $lastPenalizedAt = $data['last_penalized_at'] ?? null;
        if (! is_string($lastPenalizedAt) || $lastPenalizedAt === '') {
            return false;
        }

        return CarbonImmutable::parse($lastPenalizedAt)->format('Y-m') === $asOf->format('Y-m');
    }

    /**
     * @return array<string, mixed>
     */
    private function arrearsPayload(object $arrearsRow): array
    {
        return [
            'public_id' => $this->rowString($arrearsRow, 'public_id'),
            'loan_schedule_line_id' => $this->rowInt($arrearsRow, 'loan_schedule_line_id'),
            'due_on' => $this->rowString($arrearsRow, 'due_on'),
            'original_due_minor' => $this->rowInt($arrearsRow, 'original_due_minor'),
            'paid_minor' => $this->rowInt($arrearsRow, 'paid_minor'),
            'unpaid_minor' => $this->rowInt($arrearsRow, 'unpaid_minor'),
            'penalty_base_minor' => $this->rowNullableInt($arrearsRow, 'penalty_base_minor'),
            'status' => $this->rowString($arrearsRow, 'status'),
            'last_penalized_at' => $this->rowNullableString($arrearsRow, 'last_penalized_at'),
        ];
    }

    private function graceDays(Loan $loan): int
    {
        $product = $loan->loanProduct;
        if ($product instanceof LoanProduct && is_int($product->penalty_grace_days)) {
            return $product->penalty_grace_days;
        }

        return 5;
    }

    private function fixedPenaltyAmountMinor(): int
    {
        $value = config('formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.fixed_amount_minor', 5000);

        return is_int($value) ? $value : 5000;
    }

    private function variableRatePercent(): string
    {
        $value = config('formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.variable_rate_percent', '2');

        return is_string($value) && $value !== '' ? $value : '2';
    }

    private function minimumUnpaidAmountMinor(): int
    {
        $value = config('formulas.policies.penalties_and_arrears.rules.monthly_arrears_penalty.minimum_unpaid_amount_minor', 1000);

        return is_int($value) ? $value : 1000;
    }

    private function dateString(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value)) {
            return substr($value, 0, 10);
        }

        if (is_int($value) || is_float($value)) {
            return substr((string) $value, 0, 10);
        }

        return '';
    }

    private function rowString(object $row, string $key): string
    {
        $data = (array) $row;
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : (string) $value;
    }

    private function rowNullableString(object $row, string $key): ?string
    {
        $data = (array) $row;
        $value = $data[$key] ?? null;

        return $value === null ? null : (is_string($value) ? $value : (string) $value);
    }

    private function rowInt(object $row, string $key): int
    {
        $data = (array) $row;
        $value = $data[$key] ?? 0;

        return is_int($value) ? $value : (int) $value;
    }

    private function rowNullableInt(object $row, string $key): ?int
    {
        $data = (array) $row;
        $value = $data[$key] ?? null;

        return $value === null ? null : (is_int($value) ? $value : (int) $value);
    }
}
